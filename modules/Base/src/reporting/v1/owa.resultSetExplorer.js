//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * Result Set Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.5.0
 */

// OWA is defined by owa.js; this module augments it (OWA.resultSet = ... etc.). jQuery
// was supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

OWA.resultSet = function( attributes ) {

    for (var attribute in attributes) {

        this[attribute] = attributes[attribute];
    }
};

OWA.resultSet.prototype = {

    getMetricLabel : function(name) {
        //alert(this.resultSet.aggregates[name].label);
        if (this.aggregates[name].label.length > 0) {
            return this.aggregates[name].label;
        } else {
            return 'unknown';
        }
    },

    getMetricValue : function(name) {
        //alert(this.resultSet.aggregates[name].label);
        if (this.aggregates[name].value.length > 0) {
            return this.aggregates[name].value;
        } else {
            return 0;
        }
    },

    getSeries : function(value_name, value_name2, filter) {

        if (this.resultsRows.length > 0) {

            var series = [];
            //create data array
            for(var i=0;i<=this.resultsRows.length -1;i++) {

                if (filter) {
                    var check = filter(this.resultsRows[i]);
                    if (!check) {
                        continue;
                    }
                }

                var item = '';
                if (value_name2) {
                    item =[this.resultsRows[i][value_name].value, this.resultsRows[i][value_name2].value];
                } else {
                    item = this.resultsRows[i][value_name].value;

                }

                series.push(item);
            }

            return series;
        }
    }
};


/**
 * Result Set Explorer Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */
 
OWA.resultSetExplorer = function(dom_id, options) {

    this.dom_id = dom_id || '';
    this.gridInit = false;
    this.init = {
        grid: false,
        areaChart: false
    };

    this.columnLinks = '';
    this._columnLinksCount = 0;
    this.resultSet = [];
    this.currentView = '';
    this.currentContainerWidth = '';
    this.currentWindowWidth = '';
    this.view = '';
    this.asyncQueue = [];
    this.subscriber_dom_ids = [];

    this.domSelectors = {
        areaChart: '',
        grid: ''
    };

    this.options = {
        defaultView: 'grid',
        areaChart: {
            series:[],
            showDots: false,
            showLegend: true,
            lineWidth: 2.5
        },
        // A `pie` widget's options; see OWA.shareChart.
        shareChart: {
            metric: '',
            dimension: '',
            // Raw dimension value -> row label, set by a report definition.
            valueLabels: null,
            numSlices: 5,
            // The widget's own constraints; see OWA.dataGrid.emptyText.
            ownConstraints: ''
        },
        sparkline: {
            metric: ''
        },
        grid: {
            /*
             * Each row's place in the whole list, so the first row of page
             * two is 11 at ten a page.
             */
            showRowNumbers: true,
            /*
             * A card: one metric against one dimension at a quarter of the
             * row (report_widgets.php), drawn as a ranked list -- each row's
             * value with a bar under it against the largest. No row numbers,
             * no Total row and no shares.
             */
            compact: false,
            excludeColumns: [],
            columnFormatters: {},
            /*
             * The Filter control, the dimension pickers in the headings, and
             * Rows per page and Go to.
             *
             * On by default -- that is what an EXPLORER is. Off for a grid whose
             * rows were computed rather than queried: the goal funnel's steps
             * come from one ordered query the report ran itself, so there is no
             * result-set URL behind them to re-query with a different dimension
             * or a new constraint. Drawing the controls anyway offers the reader
             * choices that cannot do anything.
             */
            showExplorerControls: true
        },
        template: {
            template: '',
            params: '',
            mode: 'append',
            dom_id: ''
        },
        metricBoxes: {
            width: '',

            /*
             * How narrow a box may get before the row wraps.
             *
             * Separate from `width`, which pins a box AT a size and takes it
             * out of the sharing entirely. This one says "share the row, but
             * wrap rather than go under this" -- which is what a card wants,
             * where the number of metrics is the author's and the width is not.
             *
             * Empty leaves kpiBox's own default.
             */
            minWidth: '',

            // null: decide from whether there is a chart above them. See
            // makeMetricBoxes().
            sparklines: null
        },
        chart: {showGrid: true},
        chartHeight: 125,
        chartWidth:700,
        autoResizeCharts: true,
        views:['grid', 'areaChart','pie', 'sparkline']
    };

    this.viewObjects = {};
    this.loadUrl = '';
    this.dataExportApiParams = {};
    this.isLoaded = false;
};

OWA.resultSetExplorer.prototype = {

    //remove
    viewMethods: {
        grid: 'refreshGrid',
        areaChart: 'makeAreaChart',
        pie: 'makeShareChart',
        sparkline: 'makeSparkline',
        template: 'renderTemplate'
    },

    setDataLoadUrl : function(url) {

        this.loadUrl = url;
    },

    changeSort : function(column, order) {

        var url = new OWA.uri( this.resultSet.self );
        var sortorder = '';
        if (order === 'desc') {
            sortorder = '-';
        }

        // set sort order
        url.setQueryParam(OWA.util.appNs('sort'), column + sortorder);
        // remove page param
        url.removeQueryParam(OWA.util.appNs('page'));
        // fetch new results
        //alert( url.getSource() );
        this.getNewResultSet( url.getSource() );

    },

    /**
     * Add/Changes a dimension
     * handler for secondary_dimension_change events
     */
    changeDimension : function(oldname, newname) {

        // get current list of dimensions from url
        var url = new OWA.uri( this.resultSet.self );
        var dims = OWA.util.urldecode(url.getQueryParam(OWA.util.appNs('dimensions')));

        var new_dims = [];

        if (dims) {

            dims = dims.split(',');

            if ( OWA.util.in_array(oldname, dims) ) {

                // loop through dims looking for the current sec. dim
                for (var i=0; i < dims.length; i++) {
                    // if you find it replace with new one
                    var new_dim;
                    if ( dims[i] === oldname ) {
                        new_dim = newname;
                    } else {
                        new_dim = dims[i]
                    }

                    new_dims.push(new_dim);
                }
            } else {
                // just add to the existng dim set
                new_dims = dims;
                new_dims.push( newname );
            }

            new_dims = new_dims.join(',');

            url.setQueryParam(OWA.util.appNs('dimensions'), new_dims);
            this.getNewResultSet( url.getSource() );
        }
    },

    /**
     * Group the grid by exactly these dimensions.
     *
     * The list-based sibling of changeDimension(), which swaps ONE name for
     * another. The grid has always grouped by a LIST -- changeDimension reads
     * `dimensions` off the result set's own URL, replaces an entry if it finds
     * the old name and APPENDS if it does not -- so the list is the real model
     * and the single "secondary dimension" select was a one-slot view of it.
     *
     * changeDimension stays, because secondary_dimension_change is a public
     * event and something outside this file may still fire it.
     *
     * @param array dims dimension names, in the order the grid should group by
     */
    setDimensionList : function ( dims ) {

        var url = new OWA.uri( this.resultSet.self );

        url.setQueryParam( OWA.util.appNs( 'dimensions' ), dims.join( ',' ) );

        this.getNewResultSet( url.getSource() );
    },

    changeConstraints : function (constraints) {

        var url = new OWA.uri( this.resultSet.self );

        // set constraints
        url.setQueryParam(OWA.util.appNs('constraints'), constraints);

        // fetch new results
        this.getNewResultSet( url.getSource() );

    },

    /**
     * Show this page, at this many rows a page.
     *
     * @param int page           1 is the first
     * @param int resultsPerPage
     */
    changePage : function ( page, resultsPerPage ) {

        var url = new OWA.uri( this.resultSet.self );

        url.setQueryParam( OWA.util.appNs( 'page' ), page );

        if ( resultsPerPage ) {
            url.setQueryParam( OWA.util.appNs( 'resultsPerPage' ), resultsPerPage );
        }

        this.getNewResultSet( url.getSource() );
    },

    getOption : function(name) {

        return this.options[name];
    },

    getAggregates : function() {

        return this.resultSet.aggregates;
    },

    // needed??
    setView : function(name) {

        this.view = name;
    },

    // makesa unqiue idfor each row
    // needed?
    makeRowGuid : function(row) {

    },

    getRowValues : function(old) {

        var row = {};

        for (var item in old) {

            if (old.hasOwnProperty(item)) {
                row[item] = old[item].value;
            }
        }

        return row;
    },

    loadFromArray : function(json, view) {

        if (view) {
            this.view = view;
        }

        this.loader(json);

    },

    load : function(url) {
        this.showLoader();
        url = url || this.loadUrl;
        this.getResultSet(url);
    },

    /**
     * Creates a data grid from the result set
     *
     * @param    dom_id    string    the target dom ID for the grid
     * @param    options    obj        grid options
     */
    createGrid : function (dom_id, options) {

        // set defaults for backwards compatability
        dom_id = dom_id || this.dom_id;
        options = options || this.options.grid;

        // make new grid object
        var grid = new OWA.dataGrid( dom_id, options );

        // show grid
        grid.generate(this.resultSet);

        //register dom_id as a listener for data change events
        this.registerDataChangeSubscriber( dom_id );

        // closure
        var that = this;

        // subscribe to grid page events
        jQuery( "#" + dom_id ).bind( 'page_forward', function(event) {
            that.getNewResultSet(that.resultSet.next);
        });

        jQuery( "#" + dom_id ).bind( 'page_back', function(event) {
            that.getNewResultSet(that.resultSet.previous);
        });

        // subscribe to grid secondary dimension change event
        jQuery( "#" + dom_id ).bind( 'secondary_dimension_change', function(event, oldname, newname) {
            that.changeDimension(oldname, newname);
        });

        // The pills speak in whole lists; the event above stays for anything
        // outside this file still firing the one-at-a-time version.
        jQuery( "#" + dom_id ).bind( 'dimension_list_change', function(event, dims) {
            that.setDimensionList(dims);
        });

        // subscribe to grid sort column change event
        jQuery( "#" + dom_id ).bind( 'sort_column_change', function(event, column, direction) {
            that.changeSort(column, direction);
        });

        // subscribe to constraint_change event
        jQuery( "#" + dom_id ).bind( 'constraint_change', function(event, constraints) {
            that.changeConstraints(constraints);
        });

        // Rows per page and Go to.
        jQuery( "#" + dom_id ).bind( 'page_change', function(event, page, resultsPerPage) {
            that.changePage(page, resultsPerPage);
        });
    },

    /**
     * Registers a dom_id to publish new result sets to
     */
    registerDataChangeSubscriber : function( dom_id ) {

        this.subscriber_dom_ids.push( dom_id );
    },

    /**
     * Depricated
     */
    refreshGrid : function() {

        return this.createGrid();
    },

    loader : function(data) {

        if (data) {

            this.setResultSet(data);
            this.isLoaded = true;

            if (this.view) {
                var method_name = this.viewMethods[this.view];
                this[method_name]();
            }

            if (this.asyncQueue.length > 0) {

                for(var i=0;i< this.asyncQueue.length;i++) {

                    this.dynamicFunc(this.asyncQueue[i]);
                }
            }
        }
    },

    dynamicFunc : function (func){
        //alert(func[0]);
        var args = Array.prototype.slice.call(func, 1);
        //alert(args);
        this[func[0]].apply(this, args);
    },

    showLoader: function() {
        jQuery('#'+this.dom_id).append('<div class="loader"><img class="loading" src="'+OWA.getSetting('baseUrl')+'public/base/i/loader.gif"></div>');
    },

    hideLoader: function() {
        jQuery('#'+this.dom_id).find('.loader').remove();
    },

    // fetch the result set from the server
    getResultSet : function(url) {

        var that = this;
        jQuery.getJSON(url, '', function (data) {that.hideLoader(); that.loader(data); });
    },

    getNewResultSet : function( url ) {

        url = url || this.resultSet.self;

        var that = this;
        jQuery.getJSON(url, '', function (data) {that.setResultSet(data);});
    },

    setResultSet : function(rs) {

        /*
         * When the numbers were built, for the header's "Data as of" line
         * (OWA.report.showAsOf). Announced rather than handed to a report
         * object, because a widget does not know which page it is on.
         */
        // The REST response wraps the result set in 'data', as unwrapped below.
        var asOf = OWA.util.is_object( rs )
            ? ( rs.hasOwnProperty( 'data' ) && rs.data ? rs.data.asOf : rs.asOf ) : null;

        if ( asOf ) {

            jQuery( document ).trigger( 'owa:resultSetAsOf', [ asOf ] );
        }

        // check to see if resultSet is new
        if ( OWA.util.is_object(rs) && OWA.util.is_object( this.resultSet ) ) {
            // if not new then return. nothing to do.
            if (rs.guid === this.resultSet.guid) {
                OWA.debug('result set has same GUID. no change needed.');
                return;
            } else {
                OWA.debug('result set has new GUID. change needed.');
            }

        }

        // this applies data to a special resultSet object that
        // has some helper methods.
		
		//check needed to handle new REST API response object which puts the resultSet in it's 'data' prop.
        if (rs.hasOwnProperty('data')) {
	    	
	    	this.resultSet = new OWA.resultSet(rs.data);    
	        
        } else {
			
			this.resultSet = new OWA.resultSet(rs);	        
        }
		
        this.applyLinks();

        // notify listeners of new data
        var that = this;

        for (var i = 0; i < that.subscriber_dom_ids.length; i++) {
            OWA.debug('about to trigger data updates.');
            jQuery('#' + that.subscriber_dom_ids[i]).trigger('new_result_set', [that.resultSet]);
        }

        /*
         * ...and once they have all rebuilt, say again which metric is charted.
         *
         * The chart and the metric boxes are separate subscribers that each
         * rebuild themselves from this event, so whichever of them goes second
         * would otherwise wipe out what the first marked. Doing it after the
         * loop means it does not depend on the order they were registered in.
         */
        this.markChartedMetric();
    },

    /**
     * Adds a link template to a column
     * @public
     */
    addLinkToColumn : function(col_name, link_template, sub_params) {

        this.columnLinks = {};
        if (col_name) {
            var item = {};
            item.name = col_name;
            item.template = link_template;
            item.params = sub_params;

            this.columnLinks[col_name] = item;
            item = '';
        }

        this._columnLinksCount++;
    },

    /**
     * Applies links to result set dimensions where necessary
     * @private
     */
    applyLinks : function() {

        var p = '';

        if (this.resultSet.resultsRows.length > 0) {

            if (this._columnLinksCount > 0) {

                for(var i=0;i<=this.resultSet.resultsRows.length - 1;i++) {

                    for (var y in this.columnLinks) {
                        if (this.columnLinks.hasOwnProperty(y)) {
                            //alert(this.dom_id + ' : '+y);
                            var template = this.columnLinks[y].template;

                            /*
                             * The linked column may not be in this result set.
                             *
                             * A link template is registered once, for a column
                             * the report was built with -- but the reader can
                             * now change WHICH dimensions the grid groups by,
                             * and swapping one out takes its column with it. A
                             * template for a column that is no longer there has
                             * nothing to apply to.
                             *
                             * Latent until the dimension controls could replace
                             * the FIRST dimension: the old single-slot picker
                             * only ever changed the secondary one, so the
                             * linked column could not disappear and this read
                             * ...[y].name on undefined only in theory.
                             */
                            if ( ! this.resultSet.resultsRows[i][y] ) {
                                continue;
                            }

                            if (this.resultSet.resultsRows[i][y].name.length > 0) {
                                //if (this.resultSet.resultsRows[i][this.columnLinks[y]].name.length > 0) {

                                for (var z in this.columnLinks[y].params) {

                                    if (this.columnLinks[y].params.hasOwnProperty(z)) {

                                        template = template.replace('%s', OWA.util.urlEncode(this.resultSet.resultsRows[i][this.columnLinks[y].params[z]].value));
                                    }
                                }

                                this.resultSet.resultsRows[i][this.columnLinks[y].name].link = template;
                            }
                        }
                    }
                }
            }
        }
    },

    // move to resultSet obj?
    formatValue : function(type, value) {

        switch(type) {
            // convery yyyymmdd to javascript timestamp as  flot requires that
            case 'yyyymmdd':

                //date = jQuery.datepicker.parseDate('yymmdd', value);
                //value = Date.parse(date);
                var year = value.substring(0,4) * 1;
                var month = (value.substring(4,6) * 1) -1;
                var day = value.substring(6,8) * 1;
                var d = Date.UTC(year,month,day,0,0,0,0);
                value = d;
                OWA.debug('year: %s, month: %s, day: %s, timestamp: %s',year,month,day,d);
                break;

            case 'currency':
                value = value/100;
        }

        return value;
    },

    // move? check first to see if used by anyone other than area shart.
    timestampFormatter : function(timestamp) {

        var d = new Date(timestamp*1);
        var curr_date = d.getUTCDate();
        var curr_month = d.getUTCMonth() + 1;
        var curr_year = d.getUTCFullYear();
        //alert(d+' date: '+curr_month);
        var date =  curr_month + "/" + curr_date + "/" + curr_year;
        //var date =  curr_month + "/" + curr_date;
        return date;
    },


    /**
     * Main method for displaying an area chart
     */
    makeAreaChart : function(series, dom_id) {

        // setup area chart options
        var options = {

        };

        var ac = new OWA.areaChart();
        dom_id = dom_id || this.dom_id;
        // set the target dom_id chart should appear in
        ac.setDomId( dom_id );
        // generate area chart
        ac.generate(this.resultSet, series, dom_id);

        /*
         * Kept, not discarded.
         *
         * The chart owns state now -- which series the reader has brought
         * forward from the legend -- and a chart that state cannot be read from
         * can only be checked by looking at it. It is also what a later caller
         * would need to redraw one without rebuilding it.
         */
        this.areaChart = ac;

        /*
         * ...and the chart knows who to ask for data.
         *
         * The granularity control rewrites the result-set URL and refetches,
         * and fetching belongs to the explorer. Without this the chart would
         * have to own a second copy of that, or the control could not exist.
         */
        ac.explorer = this;

        // Now that it can, draw it -- generate() ran before this was set.
        ac.drawGranularityControl( dom_id );

        //register dom_id as a listener for data change events
        this.registerDataChangeSubscriber( dom_id );
    },

    // shows a tool tip for flot charts
    showTooltip : function(x, y, contents) {

        jQuery('<div id="tooltip">' + contents + '</div>').css( {
            position: 'absolute',
            display: 'none',
            top: y + 5,
            left: x + 5,
            border: '1px solid #cccccc',
            padding: '2px',
            'background-color': '#ffffff',
            opacity: 0.90
        }).appendTo("body").fadeIn(100);
    },
    
    getMetricLabel : function(name) {

        return this.resultSet.getMetricLabel( name );
    },

    getMetricValue : function(name) {

        return this.resultSet.getMetricValue( name );
    },

    makeShareChart : function ( resultSet, dom_id, options ) {

        var chart = new OWA.shareChart();

        dom_id = dom_id || this.dom_id;

        chart.generate( resultSet || this.resultSet, dom_id, options || this.options.shareChart );

        this.registerDataChangeSubscriber( dom_id );
    },
    

    renderTemplate : function(template, params, mode, dom_id) {

        template = template || this.options.template.template;
        params = params || this.options.template.params;
        mode = mode || this.options.template.mode;
        dom_id = dom_id || this.options.template.dom_id || this.dom_id;
        jQuery.jqotetag('*');
        //dom_id = dom_id || this.dom_id;

        if (mode === 'append') {
            jQuery('#' + dom_id).jqoteapp(template, params);
        } else if (mode === 'prepend') {
            jQuery('#' + dom_id).jqotepre(template, params);
        } else if (mode === 'replace') {
            jQuery('#' + dom_id).jqotesub(template, params);
        }
    },

    // moved to resultSet
    getSeries : function(value_name, value_name2, filter) {

        if (this.resultSet.resultsRows.length > 0) {

            var series = [];
            //create data array
            for(var i=0;i<=this.resultSet.resultsRows.length -1;i++) {

                if (filter) {
                    var check = filter(this.resultSet.resultsRows[i]);
                    if (!check) {
                        continue;
                    }
                }

                var item = '';
                if (value_name2) {
                    item =[this.resultSet.resultsRows[i][value_name].value, this.resultSet.resultsRows[i][value_name2].value];
                } else {
                    item = this.resultSet.resultsRows[i][value_name].value;

                }

                series.push(item);
            }

            return series;
        }
    },
    
    makeMetricBoxes : function(dom_id, label, metrics, filter) {

        var kpi = new OWA.kpiBox();

        if ( ! dom_id ) {
            dom_id = this.dom_id;
        }

        var options = {};

        if ( label ) {
            options.label = label;
        }

        if ( metrics ) {

            options.metrics = metrics;
        }

        if ( filter ) {
            options.filter = filter;
        }

        if ( this.options.metricBoxes.width ) {
            options.width = this.options.metricBoxes.width;
        }

        if ( this.options.metricBoxes.minWidth ) {
            options.minWidth = this.options.metricBoxes.minWidth;
        }

        /*
         * No sparkline in a box that sits under a chart.
         *
         * The chart above already draws the shape over time, so a thumbnail of
         * it inside every box says the same thing again -- and these boxes are
         * the control for choosing which metric that chart draws, which reads
         * better as a row of numbers than as a row of small graphs.
         *
         * INFERRED from whether this explorer has a chart, rather than declared
         * by each caller: makeAreaChart is queued before makeMetricBoxes in all
         * five places that draw both, so by now the answer is knowable and no
         * template has to repeat it. `sparklines` overrides, either way.
         */
        options.showSparklines = ( this.options.metricBoxes.sparklines === null
            || this.options.metricBoxes.sparklines === undefined )
            ? ! this.areaChart
            : !! this.options.metricBoxes.sparklines;

        kpi.generate(this.resultSet, dom_id, options);

        //register dom_id as a listener for data change events
        this.registerDataChangeSubscriber( dom_id );

        // The boxes are a way to choose what the chart above them draws.
        this.bindMetricBoxes( dom_id );
    },

    /**
     * Clicking a metric box charts that metric.
     *
     * The boxes under a trend ARE the metrics the widget measures, and the
     * chart draws one of them -- so they are already the list of what it could
     * draw instead, and a separate picker beside them would be a second copy of
     * the same list.
     *
     * DELEGATED, and bound once. Both the boxes and the chart rebuild
     * themselves whenever a new result set arrives -- a granularity change is
     * one -- so a handler on the boxes would be thrown away with them.
     *
     * @param string dom_id the element the boxes were rendered into
     */
    bindMetricBoxes : function ( dom_id ) {

        var that = this;

        this.metricBoxesDomId = dom_id;

        jQuery( '#' + dom_id )
            .off( 'click.owaChartMetric' )
            .on( 'click.owaChartMetric', '.owa_metricInfobox', function () {

                if ( ! that.areaChart ) {

                    return;
                }

                if ( that.areaChart.changeMetric( jQuery( this ).attr( 'data-metric' ) ) ) {

                    that.markChartedMetric();
                }
            } );

        this.markChartedMetric();
    },

    /**
     * Say on the boxes which metric the chart is drawing.
     *
     * Interactive only when there is more than one to choose between: one box
     * that can only re-select itself is a control that does nothing, and
     * styling it as clickable would promise otherwise.
     */
    markChartedMetric : function () {

        if ( ! this.metricBoxesDomId || ! this.areaChart ) {

            return;
        }

        var $boxes = jQuery( '#' + this.metricBoxesDomId + ' .owa_metricInfobox' );
        var charted = this.areaChart.chartedMetric();

        $boxes.each( function () {

            jQuery( this ).toggleClass( 'owa_metricInfoboxCharted',
                jQuery( this ).attr( 'data-metric' ) === charted );
        } );

        jQuery( '#' + this.metricBoxesDomId )
            .toggleClass( 'owa_metricBoxesSelectable', $boxes.length > 1 );
    },
    
    makeSparkLine : function(dom_id, options) {

        if ( ! dom_id ) {
            dom_id = this.dom_id;
        }

        var sl = new OWA.sparkline();
        sl.generate( this.resultSet, dom_id, options);
        //register dom_id as a listener for data change events
        this.registerDataChangeSubscriber( dom_id );
    },
    
    getApiEndpoint : function() {

        return this.getOption('api_endpoint') || OWA.getSetting('api_endpoint');
    },
    
    makeApiRequestUrl : function(method, options, url) {

        var url = url || this.getApiEndpoint();
        url += '?';
        url += OWA.util.appNs('do') + '=' + method;
        var count = OWA.util.countObjectProperties(options);
        var i = 1;
        for (var option in options) {

            if (options.hasOwnProperty(option)) {

                if (typeof options[option] != 'undefined') {
                    url += '&' + OWA.util.appNs(option) + '=' + OWA.util.urlEncode(options[option]);
                }
                i++;
            }
        }

        return url;
    }
    
};

/**
 * Dimension Picker UI control Class
 *
 * @param    target_dom_id    string    dom id where the control should be created.
 * @param     options            obj        config object
 */
OWA.dimensionPicker = function(target_dom_selector, options) {

    this.options = options || {};

    this.dim_list = {};
    this.alternate_field_selector = '';
    this.dom_id = target_dom_selector;
    this.exclusions = [];

    if ( options && options.hasOwnProperty('exclusions') ) {

        this.setExclusions(options.exclusions);
    }

};

OWA.dimensionPicker.prototype = {


    setDimensions : function ( dims ) {

        this.dim_list = dims;
    },

    reset: function(dim_list) {

        if ( dim_list ) {
            this.setDimensions( dim_list );
        }

        this.generateDimList();
    },

    display: function(selected) {

        var dom_id = this.dom_id;

        var container_selector = dom_id;

        // add container level dom elements
        var container_dom_elements =  '<span class="dimensionPicker">';
        container_dom_elements += '</span>';
        jQuery( container_selector ).html( container_dom_elements );

        // hide the dim list
        jQuery( container_selector + ' > .dimensionPicker > .dim-list').hide();

        this.generateDimList(container_selector + ' > .dimensionPicker', selected);
    },

    setDimensionlist : function ( dim_list ) {

        this.dim_list = dim_list;
    },

    generateDimList : function(selector, selected) {

        var container_selector = selector;
        /*
         * The placeholder IS the label.
         *
         * Each control in the grid's bar used to be a text label plus a
         * control, and two of those wrap onto separate lines as soon as the
         * widget is narrow -- which is most of them, since a widget is often
         * half a report wide. Naming the control inside itself keeps it to one
         * element, so the bar stays a row.
         */
        var placeholder = this.options.placeholder || 'Select...';

        var c = '<select data-placeholder="' + placeholder + '" name="dim-list" class="dim-list"><option value=""></option>';
        var that = this;

        if ( OWA.util.countObjectProperties( this.dim_list ) > 0 ) {

            for (var group in this.dim_list) {

                if ( this.dim_list.hasOwnProperty(group) ) {

                    c += OWA.util.sprintf('<optgroup label="%s">', group);

                    var num_dim_in_group = 0;
                    // add list items
                    for( var i=0; i < this.dim_list[group].length; i++ ) {

                        // check to see if the dim is on the exclusion list
                        if ( this.exclusions.length > 0 &&
                             OWA.util.in_array( this.dim_list[group][i].name, this.exclusions )
                        ) {
                            // skip if so
                            continue;
                        } else {

                            c += OWA.util.sprintf(
                                    '<option value="%s">%s</option>',
                                    this.dim_list[group][i].name,
                                    this.dim_list[group][i].label
                            );

                            num_dim_in_group++;
                        }
                    }

                    // if there are no dims in a group due to
                    // exclusions there remoe the header

                    if ( num_dim_in_group < 1 ) {
                        //jQuery( container_selector + ' > .dimensionPicker > .dim-list > h4:last' ).remove();
                    }
                }
            }
        } else {
            c += OWA.l('There are no related dimensions.');
        }
        // append container and list to dom
        jQuery( container_selector ).append(c);
        // transform into select menu

        // Pass an explicit width matching the <select>'s declared width:150px.
        // chosen-js 1.x sizes its container from the <select>'s offsetWidth AT
        // ENHANCEMENT TIME (AbstractChosen.container_width), which is 0 when the
        // select is inside a display:none parent -- the constraint/filter builder
        // creates its dimension pickers while its .builder is hidden, so without
        // an explicit width the chosen container collapsed to a ~2px sliver and
        // the dimension list was unusable. options.width bypasses the runtime
        // measurement (chosen 0.9.x read the CSS width, so this wasn't needed
        // before the 0.9.6 -> 1.8.7 upgrade).
        jQuery( container_selector + ' > .dim-list' ).chosen( {
            no_results_text: "Name not found.",
            placeholder_text_single: placeholder,
            // Still explicit -- chosen-js 1.x measures the select at enhancement
            // time and reads 0 inside a display:none parent (the constraint
            // builder enhances while its .builder is hidden). '100%' lets the
            // CSS decide, so the control can shrink with the bar instead of
            // holding it at 150px and forcing a wrap.
            width: '100%'
        } );


jQuery( container_selector + ' > .dim-list' ).chosen().change( function() {

                //OWA.debug(JSON.stringify(obj));
                var value = jQuery(selector + ' > .dim-list').val();
                jQuery( that.dom_id ).trigger(
                    'dimension_change',
                    ['', value]
                );
        });


        // set select value
        if ( selected ) {
            // chosen-js 1.x renamed the "re-sync the widget to the <select>"
            // event from chosen 0.9.x's `liszt:updated` to `chosen:updated`
            // (and no longer listens for the old name at all). Without this the
            // dimensionPicker's chosen widget silently ignores a programmatic
            // .val(), so a pre-selected secondary/constraint dimension never
            // renders. See the chosen 0.9.6 -> chosen-js 1.8.7 migration.
            jQuery(selector + ' > .dim-list').val( selected ).trigger('chosen:updated');

        } else {

            // hack for setting label of select menu
            //jQuery(container_selector + ' > .ui-selectmenu > .ui-selectmenu-status').html(OWA.l('Select...'));

        }
    },

    setAlternateField : function( selector ) {

        this.alternate_field_selector = selector;
    },

    setExclusions : function ( ex_array ) {

        this.exclusions = ex_array;
    }

};

/**
 * The grid of a result set's rows, drawn as a table.
 *
 *   - a bar above it: the Filter button and a chip per filter, then rows per
 *     page, Go to, and the pager
 *   - the dimension columns' headings are pickers: choose another dimension in
 *     its place, remove it (not the first), or add one beside it
 *   - the metric columns' headings sort; a metric with a description shows it
 *     as the heading's tooltip
 *   - a Total row first, from the result set's aggregates, and on each row a
 *     count's share of that total
 *
 * Every change is published as an event on the grid's element, and the
 * explorer that owns the grid refetches (createGrid() binds them):
 *
 *   page_forward, page_back
 *   page_change           (page, resultsPerPage)
 *   dimension_list_change (dims)
 *   sort_column_change    (column, 'asc'|'desc')
 *   constraint_change     (the constraints string)
 *
 * The rows are built from the result set and nothing else, so a refetch
 * redraws the whole grid rather than patching it.
 *
 * @param    target_dom_id    string    dom id where the control should be created.
 * @param     options            obj        config object
 */

OWA.dataGrid = function(target_dom_id, options) {

    this.dom_id = target_dom_id;
    this.options = jQuery.extend( {
        showRowNumbers: true,
        compact: false,
        excludeColumns: [],
        columnFormatters: {},
        showExplorerControls: true,
        ownConstraints: ''
    }, options || {} );
    this.init = false;
    this.resultSet = null;
    this.popover = null;
};

/** What Rows per page offers. A result set's own size is added when it is not one of them. */
OWA.dataGrid.PAGE_SIZES = [ 10, 25, 50, 100 ];

/*
 * How many dimensions the headings let you choose between.
 *
 * Not a data limit -- the grid will group by more, and some shipped widgets
 * do. A grid already grouped by more (Latest Visits groups by seven) is
 * something the report author did on purpose, and a two-slot control over it
 * could only take dimensions away, so its headings are plain.
 */
OWA.dataGrid.MAX_DIMENSIONS = 2;

/*
 * The metric kinds whose rows are each a share of the total. A ratio or an
 * average is not: two rows' bounce rates do not add up to the site's.
 */
OWA.dataGrid.SHARE_TYPES = [ 'count', 'distinct_count', 'sum' ];

/*
 * The filter conditions, as the server parses them (ResultSetManager's
 * constraint_operators): the first operator in this order found in a clause is
 * the one it means, so a value may contain any operator that comes later.
 */
OWA.dataGrid.OPERATORS = [ '==', '!=', '>=', '<=', '>', '<', '=~', '!~', '=@', '!@' ];

/*
 * "(not set)" is the label for an empty value, and the server reads
 * `==(not set)` and `!=(not set)` as "is empty" and "has a value". Each key is
 * the whole tail of a clause.
 */
OWA.dataGrid.EMPTY_TESTS = { '!=(not set)': 'has a value', '==(not set)': 'is not set' };

/** How a filter reads on its chip. */
OWA.dataGrid.OPERATOR_PHRASES = {
    '==': 'is',
    '!=': 'is not',
    '>=': 'at least',
    '<=': 'at most',
    '>':  'greater than',
    '<':  'less than',
    '=~': 'matches',
    '!~': 'does not match',
    '=@': 'contains',
    '!@': 'does not contain'
};

/** What the Add a filter dialog offers, for a dimension and for a metric. */
OWA.dataGrid.CONDITIONS = {
    dimension: [
        [ '=@', 'Contains' ],
        [ '==', 'Exactly matches' ],
        [ '!=', "Doesn't match" ],
        [ '!=(not set)', 'Has a value' ],
        [ '==(not set)', 'Is not set' ]
    ],
    metric: [
        [ '>', 'Greater than' ],
        [ '<', 'Less than' ],
        [ '==', 'Equals' ]
    ]
};

/**
 * A constraints string as a list of {name, operator, value}.
 *
 * An empty test keeps "(not set)" in its operator, so writing the list back
 * gives the same string.
 *
 * @param  string str  e.g. "pagePath=@/blog/,pageViews>50"
 * @return array
 */
OWA.dataGrid.parseConstraints = function ( str ) {

    var list = [];

    String( str || '' ).split( ',' ).forEach( function ( clause ) {

        if ( ! clause ) {
            return;
        }

        for ( var i = 0; i < OWA.dataGrid.OPERATORS.length; i++ ) {

            var op  = OWA.dataGrid.OPERATORS[ i ];
            var at  = clause.indexOf( op );

            if ( at > 0 ) {

                var value = clause.slice( at + op.length );

                if ( OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( op + value ) ) {
                    op    = op + value;
                    value = '';
                }

                list.push( { name: clause.slice( 0, at ), operator: op, value: value } );

                return;
            }
        }
    } );

    return list;
};

/**
 * What an empty widget says.
 *
 * "No data matches these filters" only when the READER filtered: a filter
 * of the report's, or one added from the grid. A widget's own constraints
 * are part of what it measures -- Top Referrers is sessions whose medium is
 * referral -- and a reader who set no filter was told one had emptied it.
 *
 * @param rs  the result set, whose `self` URL carries every constraint
 * @param own the widget's own constraints string, as its definition gave them
 */
OWA.dataGrid.emptyText = function ( rs, own ) {

    var mine = {};

    OWA.dataGrid.parseConstraints( own ).forEach( function ( c ) {
        mine[ OWA.dataGrid.formatConstraints( [ c ] ) ] = true;
    } );

    var asked = rs && rs.self
        ? OWA.dataGrid.parseConstraints( new OWA.uri( rs.self ).getQueryParam( OWA.util.appNs( 'constraints' ) ) )
        : [];

    var readers = asked.filter( function ( c ) {
        return ! mine[ OWA.dataGrid.formatConstraints( [ c ] ) ];
    } );

    return readers.length
        ? OWA.l( 'No data matches these filters.' )
        : OWA.l( 'No data for this period.' );
};

/** The list parseConstraints() returns, as a constraints string. */
OWA.dataGrid.formatConstraints = function ( list ) {

    return ( list || [] ).map( function ( c ) {
        return c.name + c.operator + ( c.value || '' );
    } ).join( ',' );
};

/**
 * Which rows a page shows, and of how many.
 *
 * The server reads up to ten pages ahead (ResultSetManager over-queries by
 * ten times the page size), so resultsTotal counts the rows from this page's
 * first one to at most ten pages on. Short of that it is exact; at it, there
 * may be more, and the total is a lower bound.
 *
 * @return object {start, end, total, more}
 */
OWA.dataGrid.range = function ( rs ) {

    var per      = parseInt( rs.resultsPerPage, 10 ) || 0;
    var page     = parseInt( rs.page, 10 ) || 1;
    var returned = parseInt( rs.resultsReturned, 10 ) || 0;
    var fetched  = parseInt( rs.resultsTotal, 10 ) || returned;
    var offset   = per * ( page - 1 );

    return {
        start: returned ? offset + 1 : 0,
        end:   offset + returned,
        total: offset + fetched,
        more:  per > 0 && fetched >= per * 10
    };
};

/** A row's share of the total, "16.5%", or '' when there is none to give. */
OWA.dataGrid.share = function ( value, total ) {

    var v = parseFloat( value );
    var t = parseFloat( total );

    if ( isNaN( v ) || isNaN( t ) || t <= 0 ) {
        return '';
    }

    return ( Math.round( v / t * 1000 ) / 10 ).toFixed( 1 ) + '%';
};

/*
 * The formatters a report can name for a column (a definition's `formatters`).
 *
 * Named, not supplied: the definition never carries a function, which is the
 * gate on it ever being user-authored. Each is given the cell
 * ({value, formatted_value, ...}), {colModel: {realColName}} and the row, and
 * returns the cell's HTML.
 *
 * The cell's HTML is markup, so anything interpolated into it is escaped. The
 * last two keep a local esc(): their tests pull the formatter's source out and
 * eval() it on its own, where the global OWA does not exist.
 */
OWA.dataGrid.formatters = {

    // A dimension with a link.
    urlFormatter : function(cellvalue, options, rowdata) {
        var name = options.colModel.realColName;

        if ( rowdata[name].link && rowdata[name].link.length > 0 ) {
            return '<a href="' + OWA.util.escapeHtml( rowdata[name].link ) + '">'
                 + OWA.util.escapeHtml( cellvalue.formatted_value )
                 + '</a>';
        }
    },

    useServerFormatter : function(cellvalue, options, rowdata) {
        var name = options.colModel.realColName;
        return OWA.util.escapeHtml( rowdata[name].formatted_value );
    },

    /*
     * The attribution history stored on a session: a JSON array of
     * {md, sr, cn, ad, at, st}, oldest first. Every one of these six values
     * arrives from a URL parameter on a tracked page.
     */
    attributionList : function(cellvalue) {

        // Cells arrive as {value, formatted_value}; the JSON is in .value.
        var raw = ( cellvalue && typeof cellvalue === 'object' && ! Array.isArray( cellvalue ) )
                ? cellvalue.value
                : cellvalue;

        if ( ! raw ) {
            return '(none)';
        }

        var list = raw;

        if ( typeof list === 'string' ) {
            try {
                list = JSON.parse( list );
            } catch ( e ) {
                return '(none)';
            }
        }

        if ( ! Array.isArray( list ) || ! list.length ) {
            return '(none)';
        }

        var esc = function( v ) {
            return String( v )
                .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
                .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' )
                .replace( /'/g, '&#39;' );
        };

        var fields = [
            [ 'md', 'Medium' ], [ 'sr', 'Source' ], [ 'cn', 'Campaign' ],
            [ 'ad', 'Ad' ], [ 'at', 'Ad Type' ], [ 'st', 'Search Terms' ]
        ];

        return list.map( function( item, i ) {

            var parts = fields
                .filter( function( f ) { return item && item[ f[0] ]; } )
                .map( function( f ) {
                    return '<i>' + f[1] + ':</i> ' + esc( item[ f[0] ] );
                } );

            return '<b>Attribution ' + ( i + 1 ) + ':</b><br>'
                 + parts.join( ' -&gt; ' ) + '<br>';
        } ).join( '' );
    },

    /*
     * A link that opens a page in an overlay session. The cell carries only
     * DATA -- {overlay, url, width, height, label} -- and this builds the
     * anchor: the page with the overlay's parameters on the fragment, which is
     * how the overlay reaches the tracker on that page, and the viewport it
     * was recorded in as data attributes.
     */
    overlayLink : function( cellvalue ) {

        var data = ( cellvalue && typeof cellvalue === 'object' && ! Array.isArray( cellvalue ) )
                 ? cellvalue.value
                 : cellvalue;

        if ( typeof data === 'string' ) {
            try {
                data = JSON.parse( data );
            } catch ( e ) {
                return '';
            }
        }

        if ( ! data || ! data.url || ! data.overlay ) {
            return '';
        }

        var esc = function( v ) {
            return String( v )
                .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
                .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' )
                .replace( /'/g, '&#39;' );
        };

        return '<a class="owa_overlayLink" href="' + esc( data.url ) + '#owa_overlay.' + esc( data.overlay )
             + '" data-width="' + esc( parseInt( data.width, 10 ) || 0 )
             + '" data-height="' + esc( parseInt( data.height, 10 ) || 0 )
             + '">' + esc( data.label || 'Open' ) + '</a>';
    }
};

OWA.dataGrid.prototype = {

    generate : function( resultSet ) {

        if ( this.init !== true ) {

            this.subscribeToDataUpdates();
            this.init = true;
        }

        this.resultSet = resultSet;
        this.render( resultSet );
    },

    // listens for changes to parent resultSet object
    subscribeToDataUpdates : function() {

        var that = this;

        jQuery( '#' + that.dom_id ).bind( 'new_result_set', function( event, resultSet ) {
            that.generate( resultSet );
        } );
    },

    /** Publish a change for the explorer to refetch with. */
    emit : function ( name, args ) {

        this.closePopover();
        jQuery( '#' + this.dom_id ).trigger( name, args || [] );
    },

    render : function ( rs ) {

        var $root = jQuery( '#' + this.dom_id );
        var $grid = $root.children( '.owa_grid' );

        if ( ! $grid.length ) {
            $grid = jQuery( '<div class="owa_grid"></div>' ).appendTo( $root );
        }

        this.closePopover();
        $grid.empty();

        var rows = rs.resultsRows || [];
        var $bar = this.controls( rs );

        if ( $bar ) {
            $grid.append( $bar );
        }

        if ( ! rows.length ) {

            $grid.append( jQuery( '<p class="owa_gridEmpty"></p>' )
                .text( OWA.dataGrid.emptyText( rs, this.options.ownConstraints ) ) );

            return;
        }

        var $table = jQuery( '<table class="owa_gridTable"></table>' )
            .toggleClass( 'is-compact', !! this.options.compact )
            .append( this.head( rs ) )
            .append( this.body( rs ) );

        $grid.append( jQuery( '<div class="owa_gridScroll"></div>' ).append( $table ) );
    },

    /**
     * The columns, from the first row: dimensions first, then metrics, each in
     * the order the result set has them, without the excluded ones.
     */
    columns : function ( rs ) {

        var first    = ( rs.resultsRows || [] )[ 0 ] || {};
        var excluded = this.options.excludeColumns || [];
        var dims     = [];
        var metrics  = [];

        for ( var name in first ) {

            if ( ! first.hasOwnProperty( name ) || excluded.indexOf( name ) !== -1 ) {
                continue;
            }

            var cell = first[ name ] || {};
            var col  = {
                name:  name,
                label: cell.label || name,
                kind:  cell.result_type === 'dimension' ? 'dimension' : 'metric',
                link:  !! cell.link
            };

            ( col.kind === 'dimension' ? dims : metrics ).push( col );
        }

        return dims.concat( metrics );
    },

    /** What the grid is grouped by, from its own URL, split into the shown and the excluded. */
    dimensionLists : function ( rs ) {

        var all      = this.getDimensions( rs ).filter( Boolean );
        var excluded = this.options.excludeColumns || [];

        return {
            shown:  all.filter( function ( n ) { return excluded.indexOf( n ) === -1; } ),
            hidden: all.filter( function ( n ) { return excluded.indexOf( n ) !== -1; } )
        };
    },

    rowNumbers : function () {

        return !! this.options.showRowNumbers && ! this.options.compact;
    },

    /** Whether the dimension headings are pickers. */
    dimensionsEditable : function ( rs ) {

        return this.options.showExplorerControls !== false
            && !! rs.self
            && this.dimensionLists( rs ).shown.length <= OWA.dataGrid.MAX_DIMENSIONS;
    },

    constraints : function ( rs ) {

        if ( ! rs.self ) {
            return [];
        }

        return OWA.dataGrid.parseConstraints(
            new OWA.uri( rs.self ).getQueryParam( OWA.util.appNs( 'constraints' ) ) );
    },

    /** {name: {label, description, metric_type}} for every metric the result set relates. */
    metricInfo : function ( rs ) {

        var info = {};

        jQuery.each( rs.relatedMetrics || {}, function ( group, list ) {
            jQuery.each( list || [], function ( i, m ) {
                if ( m && m.name ) {
                    info[ m.name ] = m;
                }
            } );
        } );

        return info;
    },

    /** A dimension's or metric's label, for a chip or a heading. */
    labelOf : function ( rs, name ) {

        var label = '';

        jQuery.each( [ rs.relatedDimensions || {}, rs.relatedMetrics || {} ], function ( i, families ) {
            jQuery.each( families, function ( family, list ) {
                jQuery.each( list || [], function ( j, d ) {
                    if ( d && d.name === name && ! label ) {
                        label = d.label || d.name;
                    }
                } );
            } );
        } );

        return label || ( rs.labels && rs.labels[ name ] ) || name;
    },

    // ---- the bar ---------------------------------------------------------

    controls : function ( rs ) {

        var that     = this;
        var explore  = this.options.showExplorerControls !== false && !! rs.self;
        var pages    = parseInt( rs.total_pages, 10 ) || 1;
        var page     = parseInt( rs.page, 10 ) || 1;
        var paged    = pages > 1 || page > 1;

        if ( ! explore && ! paged ) {
            return null;
        }

        var $bar = jQuery( '<div class="owa_gridControls"></div>' );

        if ( explore ) {

            var $filters = jQuery( '<div class="owa_gridFilters"></div>' ).appendTo( $bar );

            var $add = jQuery( '<button type="button" class="owa_gridButton owa_gridFilterButton"'
                + ' aria-haspopup="dialog" aria-expanded="false">'
                + '<i class="fas fa-filter" aria-hidden="true"></i></button>' )
                .append( document.createTextNode( OWA.l( 'Filter' ) ) )
                .on( 'click', function () { that.openFilter( jQuery( this ) ); } );

            $filters.append( $add );

            this.constraints( rs ).forEach( function ( c, index ) {
                $filters.append( that.chip( rs, c, index ) );
            } );
        }

        // A page with no rows has nothing to page through: the filters are
        // what there is to change.
        if ( ! ( rs.resultsRows || [] ).length ) {
            return $bar;
        }

        var $paging = jQuery( '<div class="owa_gridPaging"></div>' ).appendTo( $bar );
        var per     = parseInt( rs.resultsPerPage, 10 ) || 0;

        if ( explore && per ) {

            var sizes = OWA.dataGrid.PAGE_SIZES.slice();

            if ( sizes.indexOf( per ) === -1 ) {
                sizes.push( per );
                sizes.sort( function ( a, b ) { return a - b; } );
            }

            var $size = jQuery( '<select class="owa_gridPageSize"></select>' );

            sizes.forEach( function ( n ) {
                $size.append( jQuery( '<option>' ).val( n ).text( n ).prop( 'selected', n === per ) );
            } );

            $size.on( 'change', function () {
                that.emit( 'page_change', [ 1, parseInt( jQuery( this ).val(), 10 ) ] );
            } );

            $paging.append( jQuery( '<label class="owa_gridPagingItem"></label>' )
                .text( OWA.l( 'Rows per page' ) + ' ' ).append( $size ) );
        }

        if ( explore && pages > 1 ) {

            var $goto = jQuery( '<input type="number" class="owa_gridGoTo" min="1" inputmode="numeric">' )
                .attr( 'max', pages ).val( page );

            var go = function () {

                var n = Math.min( pages, Math.max( 1, parseInt( $goto.val(), 10 ) || 1 ) );

                if ( n !== page ) {
                    that.emit( 'page_change', [ n, per ] );
                } else {
                    $goto.val( page );
                }
            };

            $goto.on( 'change', go ).on( 'keydown', function ( e ) {
                if ( e.which === 13 ) { e.preventDefault(); go(); }
            } );

            $paging.append( jQuery( '<label class="owa_gridPagingItem"></label>' )
                .text( OWA.l( 'Go to' ) + ' ' ).append( $goto ) );
        }

        var r = OWA.dataGrid.range( rs );

        var $pager = jQuery( '<span class="owa_gridPager"></span>' );

        var $prev = jQuery( '<button type="button" class="owa_gridIconButton owa_gridPrev">'
            + '<i class="fas fa-chevron-left" aria-hidden="true"></i></button>' )
            .attr( 'aria-label', OWA.l( 'Previous page' ) )
            .prop( 'disabled', ! rs.previous )
            .on( 'click', function () { that.emit( 'page_back' ); } );

        var $next = jQuery( '<button type="button" class="owa_gridIconButton owa_gridNext">'
            + '<i class="fas fa-chevron-right" aria-hidden="true"></i></button>' )
            .attr( 'aria-label', OWA.l( 'Next page' ) )
            .prop( 'disabled', ! rs.next )
            .on( 'click', function () { that.emit( 'page_forward' ); } );

        var text = r.start + '–' + r.end + ' ' + OWA.l( 'of' ) + ' ' + r.total + ( r.more ? '+' : '' );

        $pager.append( $prev )
              .append( jQuery( '<span class="owa_gridRange" aria-live="polite"></span>' ).text( text ) )
              .append( $next );

        $paging.append( $pager );

        return $bar;
    },

    chip : function ( rs, c, index ) {

        var that   = this;
        var phrase = OWA.dataGrid.EMPTY_TESTS[ c.operator ]
                  || OWA.dataGrid.OPERATOR_PHRASES[ c.operator ]
                  || c.operator;

        var $text = jQuery( '<span class="owa_gridChipText"></span>' )
            .text( this.labelOf( rs, c.name ) + ' ' + OWA.l( phrase ) + ( c.value !== '' ? ' ' : '' ) );

        if ( c.value !== '' ) {
            $text.append( jQuery( '<strong></strong>' ).text( c.value ) );
        }

        var $remove = jQuery( '<button type="button" class="owa_gridChipRemove">'
            + '<i class="fas fa-times" aria-hidden="true"></i></button>' )
            .attr( 'aria-label', OWA.l( 'Remove this filter' ) )
            .on( 'click', function () {

                var list = that.constraints( rs );

                list.splice( index, 1 );
                that.emit( 'constraint_change', [ OWA.dataGrid.formatConstraints( list ) ] );
            } );

        return jQuery( '<span class="owa_gridChip"></span>' ).append( $text ).append( $remove );
    },

    // ---- the table -------------------------------------------------------

    head : function ( rs ) {

        var that     = this;
        var columns  = this.columns( rs );
        var info     = this.metricInfo( rs );
        var editable = this.dimensionsEditable( rs );
        var lists    = this.dimensionLists( rs );
        var dims     = columns.filter( function ( c ) { return c.kind === 'dimension'; } );
        var $tr      = jQuery( '<tr></tr>' );

        if ( this.rowNumbers() ) {
            $tr.append( jQuery( '<th scope="col" class="owa_gridRowNum"></th>' ).attr( 'aria-label', OWA.l( 'Row' ) ) );
        }

        columns.forEach( function ( col ) {

            var $th = jQuery( '<th scope="col"></th>' ).attr( 'data-column', col.name );
            var sorted = rs.sortColumn === col.name;

            $th.addClass( col.kind === 'dimension' ? 'owa_gridDim' : 'owa_gridMetric' );

            if ( col.kind === 'dimension' && col === dims[ dims.length - 1 ] ) {
                $th.addClass( 'owa_gridDimEnd' );
            }

            if ( sorted ) {
                $th.addClass( 'is-sorted' );
            }

            $th.attr( 'aria-sort', sorted ? ( rs.sortOrder === 'desc' ? 'descending' : 'ascending' ) : 'none' );

            var slot = lists.shown.indexOf( col.name );

            if ( col.kind === 'dimension' && editable && slot !== -1 ) {

                $th.append( that.dimensionHeading( rs, col, slot, lists, col === dims[ dims.length - 1 ] ) );

            } else {

                $th.append( that.sortHeading( rs, col, info[ col.name ] ) );
            }

            $tr.append( $th );
        } );

        return jQuery( '<thead></thead>' ).append( $tr );
    },

    sortHeading : function ( rs, col, meta ) {

        var that   = this;
        var sorted = rs.sortColumn === col.name;
        var desc   = sorted && rs.sortOrder === 'desc';

        var $label = jQuery( '<span class="owa_gridSortLabel"></span>' ).text( col.label );

        var $button = jQuery( '<button type="button" class="owa_gridSort"></button>' );

        if ( sorted ) {
            $button.append( '<i class="fas ' + ( desc ? 'fa-arrow-down' : 'fa-arrow-up' ) + '" aria-hidden="true"></i>' );
        }

        $button.append( $label );

        if ( meta && meta.description ) {
            $label.addClass( 'has-description' );
            $button.attr( 'title', meta.description );
        }

        /*
         * A metric sorts largest first, a dimension A to Z; a second click on
         * the sorted column turns it round.
         */
        $button.on( 'click', function () {

            var order = sorted
                ? ( desc ? 'asc' : 'desc' )
                : ( col.kind === 'metric' ? 'desc' : 'asc' );

            that.emit( 'sort_column_change', [ col.name, order ] );
        } );

        return $button;
    },

    dimensionHeading : function ( rs, col, slot, lists, last ) {

        var that  = this;
        var $head = jQuery( '<span class="owa_gridDimHead"></span>' );

        var $pick = jQuery( '<button type="button" class="owa_gridButton owa_gridDimButton"'
            + ' aria-haspopup="dialog" aria-expanded="false"></button>' )
            .text( col.label )
            .append( ' <i class="fas fa-chevron-down" aria-hidden="true"></i>' )
            .on( 'click', function () { that.openDimensions( jQuery( this ), rs, slot ); } );

        $head.append( $pick );

        // The first is what the report is about, and stays.
        if ( slot > 0 ) {

            $head.append( jQuery( '<button type="button" class="owa_gridIconButton owa_gridDimRemove">'
                + '<i class="fas fa-times" aria-hidden="true"></i></button>' )
                .attr( 'aria-label', OWA.l( 'Remove' ) + ' ' + col.label )
                .on( 'click', function () {

                    var shown = lists.shown.slice();

                    shown.splice( slot, 1 );
                    that.emit( 'dimension_list_change', [ shown.concat( lists.hidden ) ] );
                } ) );
        }

        if ( last && lists.shown.length < OWA.dataGrid.MAX_DIMENSIONS ) {

            $head.append( jQuery( '<button type="button" class="owa_gridIconButton owa_gridDimAdd"'
                + ' aria-haspopup="dialog" aria-expanded="false">'
                + '<i class="fas fa-plus" aria-hidden="true"></i></button>' )
                .attr( 'aria-label', OWA.l( 'Add a dimension' ) )
                .on( 'click', function () { that.openDimensions( jQuery( this ), rs, lists.shown.length ); } ) );
        }

        return $head;
    },

    body : function ( rs ) {

        var that      = this;
        var columns   = this.columns( rs );
        var info      = this.metricInfo( rs );
        var aggs      = rs.aggregates || {};
        var dimCount  = columns.filter( function ( c ) { return c.kind === 'dimension'; } ).length;
        var offset    = OWA.dataGrid.range( rs ).start - 1;
        var $tbody    = jQuery( '<tbody></tbody>' );
        var formatters = this.options.columnFormatters || {};

        var totals = ! this.options.compact && columns.some( function ( c ) {
            return c.kind === 'metric' && aggs[ c.name ];
        } );

        if ( totals ) {

            var $total = jQuery( '<tr class="owa_gridTotal"></tr>' );
            var lead   = dimCount + ( this.rowNumbers() ? 1 : 0 );

            if ( lead ) {
                $total.append( jQuery( '<td class="owa_gridDim owa_gridDimEnd"></td>' )
                    .attr( 'colspan', lead ).text( OWA.l( 'Total' ) ) );
            }

            columns.forEach( function ( c ) {

                if ( c.kind !== 'metric' ) {
                    return;
                }

                var agg = aggs[ c.name ];

                $total.append( jQuery( '<td class="owa_gridMetric"></td>' ).attr( 'data-column', c.name )
                    .text( OWA.util.metricText( agg ) ) );
            } );

            $tbody.append( $total );
        }

        /*
         * A card's bars: each row's first metric against the largest on the
         * page, in a row of its own under it so the bar can run the width of
         * the card.
         */
        var barMetric = null;
        var barMax    = 0;

        if ( this.options.compact ) {

            barMetric = columns.filter( function ( c ) { return c.kind === 'metric'; } )[ 0 ] || null;

            ( rs.resultsRows || [] ).forEach( function ( row ) {
                var v = barMetric && row[ barMetric.name ] ? parseFloat( row[ barMetric.name ].value ) : NaN;
                if ( ! isNaN( v ) && v > barMax ) {
                    barMax = v;
                }
            } );
        }

        ( rs.resultsRows || [] ).forEach( function ( row, i ) {

            var $tr = jQuery( '<tr class="owa_gridRow"></tr>' );

            if ( i % 2 === 1 && ! that.options.compact ) {
                $tr.addClass( 'is-alt' );
            }

            if ( that.rowNumbers() ) {
                $tr.append( jQuery( '<td class="owa_gridRowNum"></td>' ).text( offset + i + 1 ) );
            }

            columns.forEach( function ( c, ci ) {

                var cell = row[ c.name ] || { value: '', formatted_value: '' };
                var $td  = jQuery( '<td></td>' ).attr( 'data-column', c.name );

                if ( c.kind === 'dimension' ) {

                    $td.addClass( 'owa_gridDim' );

                    if ( ci === dimCount - 1 ) {
                        $td.addClass( 'owa_gridDimEnd' );
                    }

                } else {

                    $td.addClass( 'owa_gridMetric' );
                }

                $td.html( that.cellHtml( c, cell, row, i, formatters ) );

                var meta = info[ c.name ];
                var agg  = aggs[ c.name ];

                if ( c.kind === 'metric' && meta && agg && ! that.options.compact
                     && OWA.dataGrid.SHARE_TYPES.indexOf( meta.metric_type ) !== -1 ) {

                    var share = OWA.dataGrid.share( cell.value, agg.value );

                    if ( share ) {
                        $td.append( ' ' ).append( jQuery( '<span class="owa_gridShare"></span>' ).text( '(' + share + ')' ) );
                    }
                }

                $tr.append( $td );
            } );

            $tbody.append( $tr );

            if ( barMetric && barMax > 0 ) {

                var v = row[ barMetric.name ] ? parseFloat( row[ barMetric.name ].value ) : 0;
                var w = Math.max( 0, Math.min( 100, ( v || 0 ) / barMax * 100 ) );

                $tbody.append( jQuery( '<tr class="owa_gridBarRow" aria-hidden="true"></tr>' )
                    .append( jQuery( '<td></td>' ).attr( 'colspan', columns.length )
                        .append( jQuery( '<span class="owa_gridBar"></span>' )
                            .append( jQuery( '<span class="owa_gridBarFill"></span>' ).css( 'width', w.toFixed( 1 ) + '%' ) ) ) ) );
            }
        } );

        return $tbody;
    },

    /** A cell's HTML, through the formatter its column names or its kind implies. */
    cellHtml : function ( col, cell, row, i, formatters ) {

        var name = formatters.hasOwnProperty( col.name ) ? formatters[ col.name ]
                 : ( col.link ? 'urlFormatter' : 'useServerFormatter' );

        if ( col.kind === 'metric' && name === 'useServerFormatter' ) {
            return OWA.util.escapeHtml( OWA.util.metricText( cell ) );
        }

        var fn   = OWA.dataGrid.formatters[ name ] || OWA.dataGrid.formatters.useServerFormatter;
        var html = fn( cell, { colModel: { realColName: col.name }, rowId: i + 1 }, row );

        if ( html === undefined || html === null ) {
            html = OWA.util.escapeHtml( cell.formatted_value );
        }

        return html;
    },

    // ---- popovers --------------------------------------------------------

    /**
     * One popover at a time, anchored under the control that opened it.
     *
     * Fixed rather than absolute: a grid sits inside a widget that scrolls
     * sideways when its table is wide, and an absolutely placed popover is
     * cut off by that box. A fixed one is placed against the window and kept
     * under its control as the page scrolls.
     */
    openPopover : function ( $trigger, label, $content ) {

        var that = this;

        if ( this.popover && this.popover.trigger[0] === $trigger[0] ) {
            this.closePopover();
            return null;
        }

        this.closePopover();

        var $pop = jQuery( '<div class="owa_gridPop" role="dialog"></div>' )
            .attr( 'aria-label', label )
            .append( $content );

        $trigger.closest( '.owa_grid' ).append( $pop );
        $trigger.attr( 'aria-expanded', 'true' );

        /*
         * Under the control, as tall as the room below it allows; above it
         * when there is more room there, which is the case for a heading
         * near the foot of the window.
         */
        var place = function () {

            var r     = $trigger[0].getBoundingClientRect();
            var width = $pop.outerWidth();
            var left  = Math.max( 8, Math.min( r.left, window.innerWidth - width - 8 ) );
            var below = window.innerHeight - r.bottom - 14;
            var above = r.top - 14;
            var up    = below < 320 && above > below;

            $pop.css( {
                left: Math.round( left ) + 'px',
                top: up ? 'auto' : Math.round( r.bottom + 6 ) + 'px',
                bottom: up ? Math.round( window.innerHeight - r.top + 6 ) + 'px' : 'auto',
                maxHeight: Math.round( Math.min( 520, Math.max( 160, up ? above : below ) ) ) + 'px'
            } );
        };

        var outside = function ( e ) {

            if ( ! $pop[0].contains( e.target ) && ! $trigger[0].contains( e.target ) ) {
                that.closePopover();
            }
        };

        var keys = function ( e ) {

            if ( e.key === 'Escape' || e.which === 27 ) {
                e.preventDefault();
                that.closePopover( true );
            }
        };

        place();

        window.addEventListener( 'scroll', place, true );
        window.addEventListener( 'resize', place );
        document.addEventListener( 'mousedown', outside, true );
        $pop[0].addEventListener( 'keydown', keys );

        this.popover = {
            el: $pop,
            trigger: $trigger,
            off: function () {
                window.removeEventListener( 'scroll', place, true );
                window.removeEventListener( 'resize', place );
                document.removeEventListener( 'mousedown', outside, true );
            }
        };

        return $pop;
    },

    closePopover : function ( refocus ) {

        if ( ! this.popover ) {
            return;
        }

        var p = this.popover;

        this.popover = null;
        p.off();
        p.el.remove();
        p.trigger.attr( 'aria-expanded', 'false' );

        if ( refocus ) {
            p.trigger.trigger( 'focus' );
        }
    },

    /**
     * The dimensions to choose from for one heading: slot is the index of the
     * dimension it replaces, or the count of them to add one.
     */
    openDimensions : function ( $trigger, rs, slot ) {

        var that   = this;
        var lists  = this.dimensionLists( rs );
        var mine   = lists.shown[ slot ];
        var $box   = jQuery( '<div class="owa_gridDimPop"></div>' );

        var $search = jQuery( '<input type="search" class="owa_gridPopSearch">' )
            .attr( 'placeholder', OWA.l( 'Search dimensions' ) )
            .attr( 'aria-label', OWA.l( 'Search dimensions' ) );

        var $list = jQuery( '<div class="owa_gridPopList"></div>' );

        $box.append( $search ).append( $list );

        // Replacing a heading's dimension can also sort by it.
        if ( mine ) {

            var $sorts = jQuery( '<div class="owa_gridPopSorts"></div>' );

            [ [ 'asc', 'Sort A to Z' ], [ 'desc', 'Sort Z to A' ] ].forEach( function ( s ) {

                var on = rs.sortColumn === mine && ( rs.sortOrder === 'desc' ) === ( s[0] === 'desc' );

                $sorts.append( jQuery( '<button type="button" class="owa_gridPopOption"></button>' )
                    .toggleClass( 'is-current', on )
                    .attr( 'aria-pressed', on ? 'true' : 'false' )
                    .text( OWA.l( s[1] ) )
                    .on( 'click', function () { that.emit( 'sort_column_change', [ mine, s[0] ] ); } ) );
            } );

            $box.prepend( $sorts );
        }

        jQuery.each( rs.relatedDimensions || {}, function ( family, dims ) {

            var $group = jQuery( '<div class="owa_gridPopGroup"></div>' );

            $group.append( jQuery( '<div class="owa_gridPopHeading"></div>' ).text( family ) );

            jQuery.each( dims || [], function ( i, dim ) {

                if ( ! dim || ! dim.name ) {
                    return;
                }

                var current = dim.name === mine;
                var inUse   = ! current && lists.shown.indexOf( dim.name ) !== -1;

                var $opt = jQuery( '<button type="button" class="owa_gridPopOption"></button>' )
                    .attr( 'data-name', dim.name )
                    .text( dim.label || dim.name )
                    .toggleClass( 'is-current', current )
                    .prop( 'disabled', inUse );

                if ( current || inUse ) {
                    $opt.append( jQuery( '<span class="owa_gridPopNote"></span>' )
                        .text( current ? OWA.l( 'shown' ) : OWA.l( 'in use' ) ) );
                }

                $opt.on( 'click', function () {

                    if ( current ) {
                        that.closePopover( true );
                        return;
                    }

                    var shown = lists.shown.slice();

                    shown[ slot ] = dim.name;
                    that.emit( 'dimension_list_change', [ shown.concat( lists.hidden ) ] );
                } );

                $group.append( $opt );
            } );

            if ( $group.children( '.owa_gridPopOption' ).length ) {
                $list.append( $group );
            }
        } );

        $search.on( 'input', function () {

            var q = String( jQuery( this ).val() || '' ).toLowerCase();

            $list.children( '.owa_gridPopGroup' ).each( function () {

                var $g   = jQuery( this );
                var seen = 0;

                $g.children( '.owa_gridPopOption' ).each( function () {
                    var hit = jQuery( this ).text().toLowerCase().indexOf( q ) !== -1;
                    jQuery( this ).toggle( hit );
                    seen += hit ? 1 : 0;
                } );

                $g.toggle( seen > 0 );
            } );

        } ).on( 'keydown', function ( e ) {

            if ( e.which === 13 ) {
                e.preventDefault();
                $list.find( '.owa_gridPopOption:visible:enabled' ).first().trigger( 'click' );
            }
        } );

        var $pop = this.openPopover( $trigger, OWA.l( mine ? 'Change dimension' : 'Add a dimension' ), $box );

        if ( $pop ) {
            $search.trigger( 'focus' );
        }
    },

    /** Add a filter: a field, a condition and a value. */
    openFilter : function ( $trigger ) {

        var that = this;
        var rs   = this.resultSet;
        var $box = jQuery( '<form class="owa_gridFilterPop" novalidate></form>' );
        var id   = 'owa_gridFilter-' + this.dom_id;

        $box.append( jQuery( '<div class="owa_gridPopTitle"></div>' ).text( OWA.l( 'Add a filter' ) ) );

        var $field = jQuery( '<select class="owa_gridFilterField"></select>' ).attr( 'id', id + '-field' );
        var kinds  = {};

        jQuery.each( [ [ 'dimension', rs.relatedDimensions ], [ 'metric', rs.relatedMetrics ] ], function ( i, pair ) {

            jQuery.each( pair[1] || {}, function ( family, list ) {

                var $group = jQuery( '<optgroup></optgroup>' ).attr( 'label', family );

                jQuery.each( list || [], function ( j, d ) {

                    if ( d && d.name && ! kinds[ d.name ] ) {
                        kinds[ d.name ] = pair[0];
                        $group.append( jQuery( '<option></option>' ).val( d.name ).text( d.label || d.name ) );
                    }
                } );

                if ( $group.children().length ) {
                    $field.append( $group );
                }
            } );
        } );

        // The grid's own first dimension, which is what a filter is usually of.
        var first = this.dimensionLists( rs ).shown[ 0 ];

        if ( first && kinds[ first ] ) {
            $field.val( first );
        }

        $box.append( jQuery( '<label class="owa_gridFieldLabel"></label>' ).attr( 'for', id + '-field' ).text( OWA.l( 'Field' ) ) )
            .append( $field );

        var $ops = jQuery( '<fieldset class="owa_gridFilterOps"></fieldset>' )
            .append( jQuery( '<legend class="owa_gridFieldLabel"></legend>' ).text( OWA.l( 'Condition' ) ) );

        var $valueLabel = jQuery( '<label class="owa_gridFieldLabel"></label>' ).attr( 'for', id + '-value' ).text( OWA.l( 'Value' ) );
        var $value      = jQuery( '<input type="text" class="owa_gridFilterValue" autocomplete="off">' ).attr( 'id', id + '-value' );
        var $hint       = jQuery( '<p class="owa_gridPopHint"></p>' );
        var $apply      = jQuery( '<button type="submit" class="owa_gridButton owa_gridPrimary"></button>' ).text( OWA.l( 'Add filter' ) );

        var operator = function () {
            return $ops.find( 'input:checked' ).val();
        };

        var sync = function () {

            var empty = OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( operator() );
            var value = String( $value.val() || '' );
            var comma = value.indexOf( ',' ) !== -1;

            $valueLabel.toggle( ! empty );
            $value.toggle( ! empty );

            $hint.text( comma
                ? OWA.l( 'A value cannot contain a comma.' )
                : OWA.l( 'Rows must match every filter.' ) );

            $apply.prop( 'disabled', ! $field.val() || ( ! empty && ( value === '' || comma ) ) );
        };

        var conditions = function () {

            var kind = kinds[ $field.val() ] === 'metric' ? 'metric' : 'dimension';

            $ops.children( '.owa_gridFilterOp' ).remove();

            OWA.dataGrid.CONDITIONS[ kind ].forEach( function ( c, i ) {

                $ops.append( jQuery( '<label class="owa_gridFilterOp"></label>' )
                    .append( jQuery( '<input type="radio">' ).attr( 'name', id + '-op' ).val( c[0] ).prop( 'checked', i === 0 ) )
                    .append( jQuery( '<span></span>' ).text( OWA.l( c[1] ) ) ) );
            } );

            sync();
        };

        $field.on( 'change', conditions );
        $ops.on( 'change', sync );
        $value.on( 'input', sync );

        $box.append( $ops ).append( $valueLabel ).append( $value ).append( $hint );

        $box.append( jQuery( '<div class="owa_gridPopActions"></div>' )
            .append( jQuery( '<button type="button" class="owa_gridButton owa_gridQuiet"></button>' )
                .text( OWA.l( 'Cancel' ) )
                .on( 'click', function () { that.closePopover( true ); } ) )
            .append( $apply ) );

        $box.on( 'submit', function ( e ) {

            e.preventDefault();

            if ( $apply.prop( 'disabled' ) ) {
                return;
            }

            var op   = operator();
            var list = that.constraints( rs );

            list.push( {
                name: $field.val(),
                operator: op,
                value: OWA.dataGrid.EMPTY_TESTS.hasOwnProperty( op ) ? '' : String( $value.val() )
            } );

            that.emit( 'constraint_change', [ OWA.dataGrid.formatConstraints( list ) ] );
        } );

        conditions();

        var $pop = this.openPopover( $trigger, OWA.l( 'Add a filter' ), $box );

        if ( $pop ) {
            $field.trigger( 'focus' );
        }
    },

    getDimensions : function ( resultSet ) {

        // A grid of computed rows (the goal funnel's steps) has no URL.
        if ( ! resultSet.self ) {
            return [];
        }

        var self = new OWA.uri(resultSet.self);

        return String( self.getQueryParam(OWA.util.appNs('dimensions')) || '' ).split(',');
    }

};

OWA.constraintBuilder = function( target_dom_selector, options ) {

    this.dom_selector = target_dom_selector;
    this.options = {};
    this.constraints = {};
    this.relatedDimensions = {};
    this.relatedMetrics = {};

};

OWA.constraintBuilder.prototype = {

    operators: {
        '==':    'Exactly Matching',
        '!=':    'Not Matching',
        '>':    'Greater than',
        '<':    'Less than',
        '=@':    'Contains'

    },

    /*
     * THE EMPTY TEST, offered in the picker only. Each key is the whole tail
     * of the clause: "(not set)" is the label for NULL and '', and the server
     * reads `==(not set)` / `!=(not set)` as "is empty" / "has a value". Kept
     * out of `operators` because that map also parses constraint strings.
     */
    emptyTests: {
        '!=(not set)':  'Is Set',
        '==(not set)':  'Is Not Set'
    },

    parseConstraintString : function( str ) {

        var con_obj = {
            name:         '',
            value:        '',
            operator:     ''
        };

        return con_obj;
    },

    constraintsStringToArray : function ( str ) {

        var a = []
        var c_array = [];

        if (str) {

            if ( OWA.util.strpos(str, ',') ) {
                a = str.split(',');
            } else {
                a.push(str);
            }

            for( var i=0; i < a.length; i++ ) {

                for ( var operator in this.operators ) {

                    if ( this.operators.hasOwnProperty(operator) ) {

                        if ( OWA.util.strpos( a[i], operator ) ) {

                            var b = a[i].split(operator);

                            var c = {
                                'name':     b[0],
                                'operator': operator,
                                'value':    b[1]
                            };

                            c_array.push(c);
                        }
                    }
                }
            }

        }

        return c_array;
    },

    display : function ( constraints_str ) {

        var c_array = this.constraintsStringToArray(constraints_str);
        this.createConstraintAssembler(c_array);
        this.showConstraintCount( c_array.length );
    },

    /**
     * How many filters are on, as a badge beside the toggle.
     *
     * The toggle says "Filter" whether anything is filtered or not, so a grid
     * showing a fraction of its rows looks exactly like one showing all of
     * them -- and a reader draws conclusions from the number of rows. The badge
     * is the one thing on the bar that says the figures are of a subset.
     *
     * Hidden at zero rather than showing "0": an always-present badge is
     * furniture, and furniture stops being read.
     */
    showConstraintCount : function ( count ) {

        var badge = jQuery( this.dom_selector + ' > .constraintPickerContainer > .constraintCount' );

        if ( count > 0 ) {
            badge.text( count ).show();
        } else {
            badge.text( '' ).hide();
        }
    },

    createConstraintAssembler : function( constraints ) {

        var that = this;
        // outer container
        jQuery(that.dom_selector).append(
            '<div class="constraintPickerContainer"></div>'
        );

        var container_selector = that.dom_selector + ' > .constraintPickerContainer';

        /*
         * The builder is addressed by ID from here on, not as a child of the
         * container it was appended to.
         *
         * .dialog() MOVES the element -- it wraps it in .ui-dialog and appends
         * that to appendTo -- so `... > .constraintPickerContainer > .builder`
         * stops matching the moment the dialog is created, and every selector
         * built from it (the row list, the two buttons, the apply handler's
         * iteration) would silently address nothing. An id survives the move.
         *
         * Named after the thing it FILTERS.
         *
         * Once the builder is a dialog it is a sibling of every other dialog
         * under .owa, so the id is the only way anything -- a test, a second
         * filter on the same report -- can say which one it means. Sanitising
         * the whole selector gave unique but unreadable ids: a grid's builder
         * came out `trend-breakdownexplorerTopControlsulcontrolItem...`.
         *
         * The leading #id of the selector is the element the filter belongs
         * to, and it is already unique on the page, so it names the dialog:
         * `#owa_filterBuilder-trend-breakdown`, `-funnelFilter`. A selector
         * that does not start with an id falls back to the sanitised whole
         * thing, which is still unique.
         */
        var owner = String( that.dom_selector ).match( /^#([A-Za-z0-9_-]+)/ );

        var builder_id = 'owa_filterBuilder-' + ( owner
            ? owner[1]
            : String( that.dom_selector ).replace( /[^A-Za-z0-9_-]/g, '' ) );

        /*
         * The toggle is an ICON.
         *
         * It sits in a bar whose other controls are the dimensions the grid is
         * grouped by -- the things a reader changes to ask a different
         * question. A jQuery-UI button labelled "Filter" with a dropdown
         * triangle was the widest item there and read as another dimension
         * slot, on a bar that is often half a report wide. An icon says
         * "there is a control behind this" and costs the width of a glyph.
         *
         * Written as markup rather than enhanced with .button(): the triangle
         * came from the button widget, so keeping the widget and hiding its
         * icon would be styling around a thing whose whole contribution is the
         * label and the arrow.
         *
         * The title is what names it now, and aria-label is what names it to a
         * reader who never sees the glyph.
         */
        jQuery(container_selector).append(
            '<span class="toggle-button owa_filterToggle" role="button" tabindex="0"'
          + ' title="' + OWA.l('Filter') + '" aria-label="' + OWA.l('Filter') + '"'
          + ' aria-expanded="false"><i class="fa fa-filter"></i></span>'
          + '<span class="constraintCount" style="display:none;"></span>'
          + '<div class="builder"><ul></ul><div style="clear:both;"></div><div class="apply-button"></div>'
        );

        var button_selector = container_selector + ' > .toggle-button';
        var builder_selector = '#' + builder_id;

        jQuery( container_selector + ' > .builder' ).attr( 'id', builder_id );

        // if there are existing constraints
        if (constraints.length > 0) {

            for (var i=0; i < constraints.length; i++) {

                this.addNewConstraintRow(
                    builder_selector + ' > ul',
                    constraints[i].name,
                    constraints[i].operator,
                    constraints[i].value
                );
            }

        } else {
            // just add an empty row
            this.addNewConstraintRow(builder_selector + ' > ul');
        }

        /*
         * A MODAL, not a panel hanging off the bar.
         *
         * The builder is as wide as a filter row needs; the widget it belongs
         * to can be a quarter of a row. Every in-flow arrangement of those two
         * facts is a choice about which edge to overflow -- anchored to the
         * funnel's left it hung past the card's right edge, anchored right it
         * ran off the left and clipped the dimension picker. A dialog has no
         * parent to overflow.
         *
         * appendTo: '.owa' IS LOAD-BEARING. jQuery UI lifts a dialog to <body>
         * by default, and every rule styling what is inside this thing is
         * written `.owa .owa_...` like the rest of the reporting stylesheet --
         * so a lifted dialog matches none of them. That is exactly how both
         * report-builder modals shipped unstyled for a while.
         */
        jQuery( builder_selector ).dialog( {
            autoOpen: false,
            modal: true,
            appendTo: '.owa',
            width: Math.min( 560, jQuery( window ).width() - 40 ),
            title: OWA.l( 'Filter' ),
            dialogClass: 'owa_filterDialogFrame',
            close: function () {

                jQuery( button_selector ).attr( 'aria-expanded', 'false' );
            }
        } );

        // Open it. Keyboard as well as mouse: the icon is not a <button>, so
        // Enter and Space are not free.
        var toggle = function () {

            var open = jQuery( builder_selector ).dialog( 'isOpen' );

            jQuery( builder_selector ).dialog( open ? 'close' : 'open' );
            jQuery( button_selector ).attr( 'aria-expanded', open ? 'false' : 'true' );
        };

        jQuery( button_selector )
            .click( toggle )
            .keydown( function ( e ) {

                if ( e.which === 13 || e.which === 32 ) {

                    e.preventDefault();
                    toggle();
                }
            } );

        /*
         * NO bottom "Add filter" button.
         *
         * Two buttons at the foot of the dialog read as a choice between two
         * ways of finishing, when only one of them finishes anything: Apply is
         * the action, and adding a row is part of writing the filter. The plus
         * sits in the row it grows from -- see addNewConstraintRow -- which
         * leaves exactly one action button at the bottom.
         */

        // setup apply button
        jQuery( builder_selector + ' > .apply-button' )
            .button({

                label: OWA.l('Apply')
            })
            .click(function() {

                var constraints = '';

                // iterate through constraint rows
                jQuery(builder_selector + ' > ul > li').each(function(index) {

                    var name = jQuery(this)
                        .children('.constraintDimensionPicker')
                            .children('.dimensionPicker')
                                .children('.dim-list').val();

                    // Core jQuery-UI selectmenu (1.11+) has no 'value' method like
                    // the old Nagel fork; it keeps the native <select> in sync, so
                    // read the chosen operator straight off the select with .val().
                    var operator = jQuery(this)
                        .children('.constraintOperatorPicker')
                                .children('.operator-list').val();

                    var value = jQuery(this)
                        .children('.constraintValueField').val();

                    // An empty test carries its own value.
                    if ( that.emptyTests.hasOwnProperty( operator ) ) {

                        value = '';
                        constraints += name + operator;

                        if (index < jQuery(builder_selector + ' > ul > li').length - 1 ) {
                            constraints += ',';
                        }

                    } else if ( value ) {
                    //constraints += OWA.util.sprintf('%s%s%s,' name, operator, value);
                        constraints += name + operator + value;

                        if (index < jQuery(builder_selector + ' > ul > li').length - 1 ) {
                        //if (index < jQuery(this).siblings().length - 1 ) {
                            constraints += ',';
                        }
                    }

                  });

                // The badge counts CLAUSES, which is what the string holds --
                // a row left blank contributes nothing and is not counted,
                // because it filters nothing.
                that.showConstraintCount(
                    constraints ? constraints.split( ',' ).filter( Boolean ).length : 0 );

                var el = jQuery( that.dom_selector ).trigger('constraint_change', [constraints]);

                // Applying is the point of the dialog, so it closes on the way
                // out -- the reader wants to see the rows it changed, and they
                // are behind it.
                jQuery( builder_selector ).dialog( 'close' );
            });
    },

    setRelatedDimensions: function ( dims, exclusions ) {

        if (exclusions) {
            // filter the dim list
        }

        this.relatedDimensions = dims;
    },

    setRelatedMetrics : function ( metrics, exclusions ) {

        if (exclusions) {
            // filter the dim list
        }

        this.relatedMetrics = metrics;
    },

    combineRelatedMetricsWithDimensions : function() {

        var metrics = false;
        var dimensions = false;

        if ( OWA.util.countObjectProperties(this.relatedDimensions) > 0 ) {

            dimensions = true;
        }

        if ( OWA.util.countObjectProperties(this.relatedMetrics) > 0 ) {

            metrics = true;
        }

        if ( metrics && dimensions ) {

            var n = this.relatedDimensions;

            for ( var metric in this.relatedMetrics ) {

                if ( this.relatedMetrics.hasOwnProperty( metric ) ) {
                    n[metric] = this.relatedMetrics[metric];
                }
            }

            return n;

        } else if ( metrics ) {

            return this.relatedMetrics;

        } else if ( dimensions ) {

            return this.relatedDimensions;
        }
    },

    addNewConstraintRow : function(selector, name, operator, value, after) {

        var that = this;

        // generate container

        // generate the dim/metric chooser button
        jQuery( selector ).append(
            '<LI class="constraintRow"><span class="constraintDimensionPicker"></span> '
          + '<span class="constraintOperatorPicker"></span>'
          + '<input class="constraintValueField" type="text" size="30">'
          + '<span class="constraintAddButton" role="button" tabindex="0"'
          + ' title="' + OWA.l( 'Add another filter' ) + '"'
          + ' aria-label="' + OWA.l( 'Add another filter' ) + '">+</span>'
          + '<span class="constraintRemoveButton" role="button" tabindex="0"'
          + ' title="' + OWA.l( 'Remove this filter' ) + '"'
          + ' aria-label="' + OWA.l( 'Remove this filter' ) + '">X</span></LI>'
        );

        // create constraint dimension picker
        var dimpicker_selector = selector + ' > li:last > .constraintDimensionPicker';
        var cdp = new OWA.dimensionPicker(dimpicker_selector);
        cdp.setDimensions( this.combineRelatedMetricsWithDimensions() );
        cdp.display(name);

        // A parsed `==(not set)` is the empty test, not a typed value.
        if ( value === '(not set)' && this.emptyTests.hasOwnProperty( operator + value ) ) {

            operator = operator + value;
            value = '';
        }

        // generate operatior picker
        this.makeOperatorPicker(selector + ' > li:last > .constraintOperatorPicker', operator);

        if (value) {
            jQuery(selector + ' > li:last > .constraintValueField').val(value);
        }

        // The value field has nothing to say for an empty test.
        var $value = jQuery( selector + ' > li:last > .constraintValueField' );
        var syncValueField = function ( op ) {

            $value.toggle( ! that.emptyTests.hasOwnProperty( op ) );
        };

        syncValueField( operator );

        jQuery( selector + ' > li:last > .constraintOperatorPicker > .operator-list' )
            .on( 'change', function () { syncValueField( jQuery( this ).val() ); } );

        var $row = jQuery( selector + ' > li:last' );

        /*
         * The new row goes directly BELOW the one whose plus was pressed,
         * rather than at the end of the list. The rows are read top to bottom
         * as one sentence, so "another one after this" is where the reader is
         * looking.
         *
         * It is appended first and moved second: everything above addresses
         * the row it just built as `li:last`, and inserting in the middle
         * would leave those selectors pointing at somebody else's row.
         */
        if ( after && after.length ) {

            $row.insertAfter( after );
        }

        var addAfter = function () {

            that.addNewConstraintRow( selector, '', '', '', $row );
        };

        $row.children( '.constraintAddButton' )
            .on( 'click', addAfter )
            .on( 'keydown', function ( e ) {

                if ( e.which === 13 || e.which === 32 ) { e.preventDefault(); addAfter(); }
            } );

        var remove = function () {

            $row.remove();

            // Never zero rows: with the plus living IN a row, removing the last
            // one would leave nothing to add from.
            if ( ! jQuery( selector + ' > li' ).length ) {

                that.addNewConstraintRow( selector );
            }
        };

        $row.children( '.constraintRemoveButton' )
            .on( 'click', remove )
            .on( 'keydown', function ( e ) {

                if ( e.which === 13 || e.which === 32 ) { e.preventDefault(); remove(); }
            } );




    },

    makeOperatorPicker : function( selector, selected ) {

        // append the container
        var c = ''
        //c += '<label for="operator-list">Select Operator:</label>';
        c += '<select name="operator-list" class="operator-list">';

        // build the list of operators, then the empty tests
        var lists = [ this.operators, this.emptyTests ];

        for ( var l = 0; l < lists.length; l++ ) {

            for (var operator in lists[ l ]) {

                if ( lists[ l ].hasOwnProperty( operator ) ) {

                    c += OWA.util.sprintf(
                            '<option value="%s">%s</option>',
                            operator,
                            lists[ l ][operator]
                    );
                }
            }
        }

        c += '</select>';
        c += '';

        jQuery(selector).append(c);

        /*
         * CHOSEN, like the dimension picker beside it.
         *
         * This was a jQuery-UI selectmenu, so a filter row put two different
         * select widgets side by side -- a rounded chosen pill naming the
         * dimension, then a square selectmenu naming the operator, then a bare
         * <input> for the value. Three controls that do one job together, in
         * three different sets of clothes.
         *
         * The value is set on the native <select> BEFORE enhancing: chosen
         * renders from the select's state at enhancement time and has no
         * "set the value" method of its own -- the same reason the old code
         * set it before calling selectmenu(). The apply handler still reads
         * `.operator-list` directly, which keeps working because chosen leaves
         * the <select> in place and only hides it.
         *
         * disable_search because there are seven options; a search box on seven
         * is furniture.
         *
         * The width is EXPLICIT for the same reason the dimension picker's is:
         * chosen-js 1.x measures the <select> at enhancement time and reads 0
         * inside a display:none parent, and these rows are built while the
         * builder is still hidden. Without it the control collapses to a
         * sliver. See the comment in dimensionPicker.display().
         */
        var opList = jQuery(selector + ' > .operator-list');
        if ( selected ) {
            opList.val(selected);
        }
        opList.chosen( { disable_search: true, width: '150px' } );

    }

};


/**
 * A trend, and the grid of the rows behind it.
 *
 * A trend broken out by a dimension already draws a line per value, and the
 * question a reader asks next is always the same one -- which values, and how
 * much each. That is a grid, so a broken-out trend grows one under its boxes
 * and the two are the same question at two levels of detail.
 *
 * They are two result-set explorers rather than one, because they ask for
 * different rows: the trend needs one row per (date, value) to have a shape
 * over time, and the grid needs one row per value to be a ranking. Sharing a
 * result set would mean one of them summing the other's rows in the browser,
 * which is the thing that is wrong the moment a page boundary is involved.
 *
 * WHICH CONTROL DRIVES WHAT is the whole design, and it follows from what each
 * control means:
 *
 *   - the grid's FIRST dimension is what the report is about, so changing it
 *     changes the trend too -- the chart is that dimension over time.
 *   - a SECOND dimension refines the ROWS. It is a way of reading the table,
 *     not a different subject, and a trend cannot draw it anyway: a line per
 *     (medium, browser) pair is not a trend of anything.
 *   - a FILTER narrows the POPULATION, which is a statement about who is being
 *     counted. A chart and a table disagreeing about that would be two answers
 *     to one question, so the filter travels to both.
 *
 * Bound on the grid's own element, which is where its controls publish. The
 * grid's explorer is already listening there and keeps handling its own
 * refetch -- this adds a second listener rather than intercepting the first,
 * so the grid behaves identically whether or not it has a trend above it.
 *
 * @param object trend the trend's result-set explorer
 * @param object grid  the companion grid's result-set explorer
 */
OWA.linkTrendToBreakdownGrid = function ( trend, grid ) {

    var selector = '#' + grid.dom_id;

    jQuery( selector ).bind( 'dimension_list_change', function ( event, dims ) {

        /*
         * The chart is built by the trend's FIRST load, so it does not exist
         * while that is still in flight. Nothing is lost by ignoring the event
         * then: the trend is about to draw itself from its own query.
         */
        if ( ! trend.areaChart ) {

            return;
        }

        // Only the primary. changeBreakdown() answers false when it is the
        // dimension already charted, which is what makes adding a SECOND
        // dimension leave the trend alone.
        trend.areaChart.changeBreakdown( ( dims && dims[0] ) || '' );
    } );

    jQuery( selector ).bind( 'constraint_change', function ( event, constraints ) {

        trend.changeConstraints( constraints );
    } );
};
