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
        pieChart: false,
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
    this.autoRefreshInterval = 10000;
    this.autoRefresh = false;
    this.autoRefreshTimerId = '';

    this.domSelectors = {
        areaChart: '',
        grid: ''
    };

    this.options = {
        defaultView: 'grid',
        areaChart: {
            series:[],
            showDots: true,
            showLegend: true,
            lineWidth: 4
        },
        pieChart: {
            metric: '',
            dimension: '',
            metrics: [],
            // Raw dimension value -> slice label, set by a report definition.
            valueLabels: null,
            numSlices: 5
        },
        sparkline: {
            metric: ''
        },
        grid: {
            /*
             * OFF by default.
             *
             * The column numbered the rows on screen, which is not a fact about
             * the data -- it renumbers when the sort changes and starts again
             * at 1 on page two, so it never identifies anything. It cost a
             * column of width in every grid on every report, and width is what
             * a widget three columns wide has least of.
             *
             * Still an option: a caller that wants them can ask.
             */
            showRowNumbers: false,
            excludeColumns: [],
            columnFormatters: {},
            /*
             * The dimension chooser and the Filter control above a grid.
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
            width: ''
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
        pie: 'makePieChart',
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

            if ( this.autoRefresh ) {

                this.startAutoRefresh();
            }
        }
    },

    /**
     * Enables auto-refresh mode
     */
    enableAutoRefresh : function( interval ) {

        if ( ! this.isLoaded ) {

            this.autoRefreshInterval = interval || this.autoRefreshInterval;
            this.autoRefresh = true;
        } else {

            this.startAutoRefresh( interval );
        }
    },

    /**
     * Starts auto refresh timer
     *
     * @param    interval    int    interval duration in milliseconds
     */
    startAutoRefresh : function(interval) {

        this.autoRefreshInterval = interval || this.autoRefreshInterval;

        if ( this.isLoaded && ! this.autoRefreshTimerId ) {

            var that = this;
            this.autoRefreshTimerId = setInterval(function() {
                    that.getNewResultSet();
                },
                this.autoRefreshInterval
            );
        }
    },

    /**
     * Halts auto refresh of result set
     *
     */
    stopAutoRefresh : function() {

        clearInterval(this.autoRefreshTimerId);
        this.autoRefreshTimerId = '';
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

    makePieChart : function (resultSet, dom_id, options) {
     
         var pc = new OWA.pieChart();

         if ( ! options ) {
             options = this.options.pieChart;
         };

         if ( ! dom_id ) {

             dom_id = this.dom_id;
         }

         if ( ! resultSet ) {

             resultSet = this.resultSet;
         }

         pc.generate(resultSet, dom_id, options);

         //register dom_id as a listener for data change events
        this.registerDataChangeSubscriber( dom_id );
    },
    
    /**
     * Render a headline from a message with named slots.
     *
     * The message is DATA -- a sentence a report declares -- and this does the
     * substituting. It replaces renderTemplate for headlines, where the
     * template was a jqote string carried in configuration: a definition that
     * can hand a template engine arbitrary source is a definition that cannot
     * safely be authored by a user, which is what report configuration is
     * meant to become.
     *
     * Three slot forms, which is everything the 59 existing headlines used:
     *
     *   {visits.formatted}              the metric's formatted value
     *   {uniquePageViews.raw}           its raw value
     *   {visits|visit|visits}           its count, then singular, then plural
     *
     * One is singular; everything else, zero included, takes the plural. That
     * is what the templates being replaced did (`> 1`), and it is right for
     * English: "0 visits".
     *
     * An unknown metric renders as an empty string rather than the slot text,
     * so a mistyped name reads as missing data rather than as markup leaking
     * into the sentence.
     */
    renderHeadline : function(message, dom_id) {

        dom_id = dom_id || this.dom_id;

        var that = this;

        var aggregate = function(name) {

            var aggregates = that.resultSet
                && that.resultSet.aggregates
                ? that.resultSet.aggregates
                : {};

            return aggregates[name] || null;
        };

        var text = String(message).replace(/\{([^}]+)\}/g, function(whole, slot) {

            var parts = slot.split('|');

            // {metric|singular|plural}
            if (parts.length === 3) {

                var counted = aggregate(parts[0]);
                var count = counted ? Number(counted.value) : 0;

                return count === 1 ? parts[1] : parts[2];
            }

            var dotted = slot.split('.');
            var agg = aggregate(dotted[0]);

            if (!agg) {
                return '';
            }

            return dotted[1] === 'raw' ? agg.value : agg.formatted_value;
        });

        jQuery('#' + dom_id).html(text);
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

        kpi.generate(this.resultSet, dom_id, options);

        //register dom_id as a listener for data change events
        this.registerDataChangeSubscriber( dom_id );

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
 * Data Grid UI control Class
 *
 * @param    target_dom_id    string    dom id where the control should be created.
 * @param     options            obj        config object
 *
 */

OWA.dataGrid = function(target_dom_id, options) {

    this.dom_id = target_dom_id;
    this.options = options;
    this.init = false;
    this.gridColumnOrder = [];
    this.columnLinks = '';
    this.constraintPicker = '';
    this.previousDimensionName = '';
};

OWA.dataGrid.prototype = {

    generate : function(resultSet) {
        OWA.debug( 'hi from generate');
        var that = this;

        // custom formattter functions.
        jQuery.extend(jQuery.fn.fmatter , {
            // urlFormatter allows for a single param substitution.
            urlFormatter : function(cellvalue, options, rowdata) {
            //alert(JSON.stringify(cellvalue));
                var sub_value = options.rowId;
                //alert(options.rowId);
                var name = options.colModel.realColName;
                OWA.debug(options.rowId-1+' '+name);

                if ( rowdata[name].link.length > 0 ) {
                    var new_url = rowdata[name].link;
                    var link =  '<a href="' + new_url + '">' + cellvalue.formatted_value + '</a>';
                    return link;
                }
            },

            useServerFormatter : function(cellvalue, options, rowdata) {
                var name = options.colModel.realColName;
                return rowdata[name].formatted_value;
                //return that.resultSet.resultsRows[options.rowId-1][name].formatted_value;
            },

            /*
             * The attribution history stored on a session: a JSON array of
             * {md, sr, cn, ad, at, st}, oldest first.
             *
             * Named, not supplied. A report definition names this formatter and
             * the widget resolves the name -- the definition never carries a
             * function, which is the gate on it ever being user-authored. Same
             * reason excludeColumns became a list of names.
             *
             * The markup was a jqote template fetched by DOM id
             * (#attributionCell). It is here instead because a formatter and
             * the markup it renders are one thing, and because this is the only
             * place the fields can be escaped: jqote does not escape, and every
             * one of these six values arrives from a URL parameter on a tracked
             * page.
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
             * The Play link on a domstream recording.
             *
             * Named, not supplied: the report names this formatter and the cell
             * carries only DATA -- {overlay, url, width, height}. A report that
             * assembled the anchor itself would be handing the grid markup, and
             * the grid has no way to tell markup it built from markup it was
             * given.
             *
             * The href is the recorded page with the player's parameters on the
             * fragment, which is how the overlay reaches the tracker on that
             * page. The viewport travels as data attributes because the click
             * handler needs it to size the window -- the replay positions events
             * against the geometry they were recorded in.
             */
            domstreamPlayer : function( cellvalue ) {

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

                return '<a class="play" href="' + esc( data.url ) + '#owa_overlay.' + esc( data.overlay )
                     + '" data-width="' + esc( parseInt( data.width, 10 ) || 0 )
                     + '" data-height="' + esc( parseInt( data.height, 10 ) || 0 )
                     + '">Play</a>';
            }

        });

        // load grid control

        // happens with first results set when loading from URL.
        if (this.init !== true) {

            this.display(resultSet);

        } else {

            this.refresh(resultSet);
        }

        // hide the built in jqgrid loading divs.
        jQuery("#load_"+that.dom_id+"_grid").hide();
        jQuery("#load_"+that.dom_id+"_grid").css("z-index", 101);

        // check to see if we need ot hide the previous page control.
        if (resultSet.page == 1) {
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').show();
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
        } else if (resultSet.page == resultSet.total_pages) {
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').hide();
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
        } else {
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
        }
        //alert(resultSet.page + ' ' + resultSet.total_pages);

    },


    /**
     * creates the entire grid for the first time
     * @private
     */
    display : function( resultSet ) {

        if (resultSet.resultsReturned > 0) {

            // listen for changes to result set
            this.subscribeToDataUpdates();
            this.injectDomElements(resultSet);
            this.setGridOptions(resultSet);
            this.addAllRowsToGrid(resultSet);
            this.makeGridPagination(resultSet);
            this.init = true;

        } else {
            var dom_id = this.dom_id;
            jQuery("#" + dom_id).html("No data is available for this time period.");
        }
    },

    /**
     * refreshes the grid
     * @private
     */
    refresh : function(resultSet) {

        var that = this;
        // unload current grid jut in case columns have changed
        jQuery("#" + that.dom_id + '_grid').jqGrid('GridUnload', "#gbox_" + that.dom_id + '_grid');
        // setup grid columns/options again
        this.setGridOptions(resultSet);
        jQuery("#"+that.dom_id + ' _grid').jqGrid('clearGridData',true);
        this.addAllRowsToGrid(resultSet);
    },

    // listens for changes to parent resultSet object
    subscribeToDataUpdates : function() {

        var that = this;
        // listen for data changes
        jQuery( '#' + that.dom_id ).bind('new_result_set', function(event, resultSet) {
            that.generate(resultSet);
        });
    },

    makeGridPagination : function(resultSet) {

        if (resultSet.more) {

            var that = this;

            var p = '';
            p = p + '<LI class="owa_previousPageControl">';
            p = p + '<span>&laquo</span></LI>';
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
            //style button
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').button();
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl > .ui-button-text').css('line-height', '0.5');
            // bind click
            jQuery(".owa_previousPageControl").bind('click', function() {that.pageGrid('back');});

            var pn = '';
            pn = pn + '<LI class="owa_nextPageControl">';
            pn = pn + '<span>&raquo</span></LI>';

            jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(pn);
            // style button
            //style button
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').button();
            jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl > .ui-button-text').css('line-height', '0.5');
            //bind click
            jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').bind('click', function() {that.pageGrid('forward');});

            if (resultSet.page == 1) {
                jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
            }

        }
    },

    pageGrid : function (direction) {

        var that = this;
        // valid event names are 'page_forward' and 'page_back'
        jQuery('#' + that.dom_id).trigger('page_' + direction, []);
    },

    addAllRowsToGrid :function(resultSet) {

        var that = this;
        // uses the built in jqgrid loading divs. just giveit a message and show it.
        jQuery("#load_"+that.dom_id+"_grid").html('Loading...');
        jQuery("#load_"+that.dom_id+"_grid").show();
        jQuery("#load_"+that.dom_id+"_grid").css("z-index", 1000);
        // add data to grid.
        //
        // The grid is constructed with datatype:'local' so it never auto-fetches
        // on init -- OWA always hand-feeds the fetched result set through
        // addJSONData below. Under the old jqGrid 3.6.5, addJSONData always parsed
        // its argument with the configured jsonReader. free-jqgrid's addJSONData
        // instead picks its reader from the CURRENT datatype: 'local' uses
        // localReader (which ignores jsonReader and reads nothing from OWA's
        // {resultsRows:[...]} shape -> 0 rows), while 'json' uses jsonReader. So
        // flip to 'json' for the manual parse, then restore 'local' so nothing
        // triggers a background reload afterward.
        var grid = jQuery("#"+that.dom_id + '_grid');
        grid.jqGrid('setGridParam', { datatype: 'json' });
        grid[0].addJSONData(resultSet);
        grid.jqGrid('setGridParam', { datatype: 'local' });
        // dispay new count
        this.displayRowCount(resultSet);
    },

    displayRowCount : function(resultSet) {

        if (resultSet.total_pages > 1) {

            var start = '';
            var end = '';
            if (resultSet.page === 1) {
                start = 1;
                end = resultSet.resultsReturned;
            } else {
                start = ((resultSet.page -1)  * resultSet.resultsPerPage) + 1;
                end = ((resultSet.page -1) * resultSet.resultsPerPage) + resultSet.resultsReturned;
            }

            var that = this;
            //jQuery("#"+that.dom_id + '_grid').jqGrid('setGridParam', { rowNum: start } );

            var p = '<li class="owa_rowCount">';
            p += 'Results: '+ start + ' - ' + end;
            p = p + '</li>';


            //alert ("#"+that.dom_id + '_grid' + ' > .owa_rowCount');
            var check = jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html();
            //alert(check);
            if (check === null)    {
                jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
            } else {
                jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html(p);
            }
        }
    },

    injectDomElements : function(resultSet) {

        // A grid whose data was computed rather than queried has nothing to
        // explore FROM -- see options.grid.showExplorerControls.
        // NOT this.options.grid: createGrid() hands the explorer's grid options
        // straight in as `options`, so inside a dataGrid they ARE this.options.
        var showControls = this.options.showExplorerControls !== false;

        var p = '';
        if ( showControls ) {
            p += '<div class="owa_genericHorizontalList explorerTopControls"><ul></ul><div style="clear:both;"></div></div>';
        }
        p += '<div style="clear:both;"></div>';
        p += '<table id="'+ this.dom_id + '_grid"></table>';
        p += '<div class="owa_genericHorizontalList owa_resultsExplorerBottomControls"><ul></ul></div>';
        p += '<div style="clear:both;"></div>';

        var that = this;
        jQuery('#'+that.dom_id).append(p);

        if ( ! showControls ) {

            // The grid element is in place; the controls that would sit above
            // it are the part being skipped.
            return;
        }

        // add top level controls
        // secondard dimension picker
        jQuery('#'+that.dom_id + ' > .explorerTopControls > ul').append(
            OWA.util.sprintf(
                '<li class="controlItem"><span id="%s"></span></li>',
                this.dom_id + '_grid_secondDimensionChooser'
            )
        );

        /*
         * What the grid is grouped by, as pills.
         *
         * The dimensions the grid ALREADY has come from its own URL, so the
         * control shows the real state rather than an empty slot beside it.
         * Adding or removing one rewrites the list and refetches -- which is
         * what the single select did too, one dimension at a time.
         */
        /*
         * relatedDimensions is {family: [ {name, label}, ... ]} -- a LIST per
         * family. Passed through as it is: the family grouping is what makes
         * seventy dimensions findable, and reading the key as a NAME gave every
         * option an array index for a value, so choosing "Date" asked the grid
         * to group by "7".
         */
        /*
         * A dimension the grid queries but does not SHOW is not part of this
         * control.
         *
         * Six shipped grids group by a label and its url or id together and
         * exclude the second from the columns -- top content is grouped by
         * pageTitle AND pagePath, and pagePath is there only so the rows can
         * link somewhere. The bar drew a picker for it all the same, so a grid
         * showing one column offered two pickers, the second naming a column
         * the reader cannot see.
         *
         * Split rather than dropped: the hidden ones still have to travel with
         * every refetch, or the first regroup would take pagePath out of the
         * query and the row links would quietly stop being links.
         */
        var allDimensions = this.getDimensions( resultSet ).filter( Boolean );
        var excluded      = this.options.excludeColumns || [];

        var hiddenDimensions = allDimensions.filter( function ( name ) {
            return excluded.indexOf( name ) !== -1;
        } );

        var shownDimensions = allDimensions.filter( function ( name ) {
            return excluded.indexOf( name ) === -1;
        } );

        var dimensionControls = new OWA.dimensionSelectors(
            '#' + this.dom_id + '_grid_secondDimensionChooser',
            {
                choices: resultSet.relatedDimensions || {},
                selected: shownDimensions,
                hidden: hiddenDimensions,
                onChange: function ( dims ) {
                    jQuery( '#' + that.dom_id ).trigger( 'dimension_list_change', [ dims ] );
                }
            }
        );

        dimensionControls.display();

        /*
         * Built ONCE, and deliberately not refreshed on refetch.
         *
         * The widget's metrics are locked -- there is no control to change them
         * -- so the metric reduction has already pinned the fact table before
         * this control exists. relatedDimensions IS the set of dimensions
         * related to that table, so every option offered here is one the table
         * can answer, and choosing one cannot invalidate it.
         *
         * Measured rather than assumed: the browsers widget resolves to
         * base.session on the site_usage metric set, because pagesPerVisit,
         * visitDuration and bounceRate exist only there. Adding a request-only
         * dimension like pagePath does not move the table to the request -- it
         * makes the query illegal, which is why pagePath is not in the 70
         * dimensions this control offers in the first place.
         */


        // inject constraint builder
        // secondard dimension picker
        jQuery('#'+that.dom_id + ' > .explorerTopControls > ul').append('<li class="controlItem"><span class="constraintPicker"></span></li>');
        // constraint builder selector
        var cb_button_selector = '#'+ this.dom_id + ' > .explorerTopControls > ul > .controlItem > .constraintPickerButton';
        var cb_cont_selector = '#'+ this.dom_id + ' > .explorerTopControls > ul > .controlItem > .constraintPicker';

        // turn into button
        jQuery(cb_button_selector).button();

        // make object
        this.constraintPicker = new OWA.constraintBuilder(cb_cont_selector, {});

        this.constraintPicker.setRelatedDimensions( resultSet.relatedDimensions, [] );
        this.constraintPicker.setRelatedMetrics( resultSet.relatedMetrics, [] );
        // add current constraints to this method call
        var resultSet_url = new OWA.uri( resultSet.self );
        var cur_con = resultSet_url.getQueryParam(OWA.util.appNs('constraints'));
        this.constraintPicker.display(cur_con);

        // listen for the constraint change event
        jQuery( cb_cont_selector).bind('constraint_change', function(event, constraints) {
            // propigate the event up one level where result set explorer might be listening
            jQuery( '#' + that.dom_id ).trigger('constraint_change', [constraints]);
        });
    },

    setGridOptions : function(resultSet) {

        var that = this;

        var columns = [];

        var columnDef = '';

        // reset grid column order
        this.gridColumnOrder = [];

        for (var column in resultSet.resultsRows[0]) {

            // check to see if we should exclude any columns
            if (this.options.excludeColumns.length > 0) {

                for (var i=0;i<=this.options.excludeColumns.length -1;i++) {
                    // if column name is not on the exclude list then add it.
                    if (this.options.excludeColumns[i] != column) {
                        // add column
                        columnDef = this.makeGridColumnDef(resultSet.resultsRows[0][column]);
                        columns.push(columnDef);
                        // set grid column order
                        this.gridColumnOrder.push( resultSet.resultsRows[0][column].name );
                    }
                }

            } else {
                // add column
                columnDef = this.makeGridColumnDef(resultSet.resultsRows[0][column]);
                columns.push(columnDef);
                // set grid column order
                this.gridColumnOrder.push( resultSet.resultsRows[0][column].name );
            }
        }


        jQuery('#' + that.dom_id + '_grid').jqGrid({
            jsonReader: {
                repeatitems: false,
                root: "resultsRows",
                cell: '',
                id: '',
                page: 'page',
                total: 'total_pages',
                records: 'resultsReturned'
            },
            afterInsertRow: function(rowid, rowdata, rowelem) {return;},
            datatype: 'local',
            colModel: columns,
            rownumbers: that.options.showRowNumbers,
            viewrecords: true,
            rowNum: resultSet.resultsReturned,
            height: '100%',
            autowidth: true,
            hoverrows: false,
            // Defaulted, not passed straight through. A sort that does not
            // resolve comes back null here, and jqGrid calls .toLowerCase() on
            // sortorder while building the grid -- "Cannot read properties of
            // null (reading 'toLowerCase')", thrown before the grid finishes.
            // The page still renders, so the only trace is the browser console.
            //
            // Defence in depth, NOT the fix for any current bug: the one
            // occurrence of this had a misspelt metric in report_ecommerce.php
            // ('transactionsRevenue' for 'transactionRevenue') and correcting
            // that clears it on its own -- verified by removing this default and
            // re-running, which still passes. Kept because a caller typo should
            // not be able to throw out of grid construction.
            sortname: resultSet.sortColumn || '',
            sortorder: resultSet.sortOrder || 'asc',
            onSortCol: function( index, iCol, sortorder ) {

                //that.sortGrid( index, sortorder );
                jQuery('#' + that.dom_id).trigger('sort_column_change', [index, sortorder]);
                return 'stop';
            }
        });

        // set header css
        for (var y=0;y < columns.length;y++) {
            var css = {};
            //if dimension column then left align
            if ( columns[y].classes == 'owa_dimensionGridCell' ) {
                css['text-align'] = 'left';
            } else {
                css['text-align'] = 'right';
            }
            // if sort column then bold.
            if (resultSet.sortColumn +'' === columns[y].name) {
                //css.fontWeight = 'bold';
            }
            // set the css. no way to just set a class...
            jQuery('#' + that.dom_id + '_grid').jqGrid('setLabel', columns[y].name, '',css);
        }

    },

    // private
    makeGridColumnDef : function(column) {

        var _sort_type = '';
        var _align = '';
        var _format = '';
        var _class = '';
        var _width = '';
        var _resizable = true;
        var _fixed = false;
        var _datefmt = '';
        var _link_template = '';

        if (column.result_type === 'dimension') {
            _align = 'left';
            _class = 'owa_dimensionGridCell';
        } else {
            _align = 'right';
            _class = 'owa_metricGridCell';
            _width = 100;
            _resizable = false;
            _fixed = true;
        }

        if (column.data_type === 'string') {
            _sort_type = 'text';
        } else {
            _sort_type = 'number';
        }

        if (column.link) {
            _format = 'urlFormatter';
        } else {
            _format = 'useServerFormatter';
        }

        // set custom formatter if one exists.
        if (this.options.columnFormatters.hasOwnProperty(column.name)) {
            _format = this.options.columnFormatters[column.name];
        }

        if ( this.columnLinks.hasOwnProperty( column.name ) ) {
            _link_template = this.columnLinks[column.name].template;
        }

        var columnDef = {
            //name: column.name +'.value',
            name: column.name,
            //index: column.name +'.value',
            index: column.name +'',
            label: column.label,
            sorttype: _sort_type,
            align: _align,
            formatter: _format,
            classes: _class,
            width: _width,
            resizable: _resizable,
            fixed: _fixed,
            realColName: column.name,
            datefmt: _datefmt,
            link_template: _link_template
        };

        return columnDef;

    },

    getDimensions : function ( resultSet ) {

        var dims = '';
        var self = new OWA.uri(resultSet.self);
        dims = OWA.util.urldecode( self.getQueryParam(OWA.util.appNs('dimensions')) );
        dims = dims.split(',');

        return dims;
    }

};

/**
 * TWO CONTEXTS, TWO SETS OF RULES
 *
 * These pickers look the same in the grid's explore bar and in the custom
 * report builder's widget modal, and their obligations are opposite.
 *
 * REFINING a rendered widget: the metrics are locked -- there is no control to
 * change them -- so the metric reduction has already pinned the fact table
 * before the control exists. relatedDimensions, which the server computes
 * against that table, IS the legal list. The control reasons about nothing; it
 * renders what it was handed, and cannot offer an illegal combination.
 *
 * EDITING a widget: the metrics are being chosen, so no table is resolved yet.
 * Every pick narrows what else is possible, in both directions -- metrics
 * constrain the dimensions and dimensions constrain the metrics -- so that
 * control must actively reduce, which is why the builder carries entity maps
 * and re-narrows on each change.
 *
 * The rule: a control takes its choices FROM ITS CALLER and does no legality
 * reasoning of its own. Refining passes relatedDimensions; editing passes the
 * list it narrowed. Confusing the two is what produces a refresh that is not
 * needed here, or a missing one over there.
 */

/**
 * The dimensions a grid is grouped by: one picker each, and a plus for another.
 *
 * WHAT IT REPLACES
 *
 * A single "Secondary Dimension" select. The grid groups by a LIST -- the
 * dimensions travel in one URL parameter and changeDimension() has always
 * appended to them -- so the select showed one slot of something already
 * plural, and what the grid was grouped by was legible only from its columns.
 *
 * It also behaved differently on its first use than after: the swap-or-append
 * branch keys off previousDimensionName, which starts empty, so the first pick
 * ADDED a dimension and every pick after REPLACED one, with nothing on screen
 * to explain the difference.
 *
 * A PICKER PER DIMENSION, NOT A PILL EACH
 *
 * Deliberately unlike the widget-edit modal, which uses pills. Refining is
 * about CHANGING what you are looking at -- swap this dimension for that one --
 * and a select is the control for changing a choice. Pills are for curating a
 * set, which is what editing a widget's definition is. The two screens look
 * different because the actions are different.
 *
 * The first picker always holds a dimension: a grid grouped by nothing is not a
 * state the report has, so that one offers no blank. The rest can be cleared,
 * which is how a dimension is removed.
 *
 * @param string target_dom_selector
 * @param object options {choices, selected, max, onChange}
 */
OWA.dimensionSelectors = function ( target_dom_selector, options ) {

    this.dom_selector = target_dom_selector;

    this.options = jQuery.extend( {
        /*
         * {family: [{name, label}, ...]} -- the shape relatedDimensions already
         * has, kept rather than flattened. Seventy dimensions in one flat list
         * is a scroll; grouped by family (Visitor, Visit, System, Geo ...) it is
         * a menu, and chosen renders each family as a heading.
         */
        choices: {},
        // Names currently grouped by, in column order.
        selected: [],
        /*
         * Grouped by, but not shown -- and not this control's to offer.
         *
         * They are added back to every emitted list, because they are part of
         * the query even though they are not part of the choice: a grid whose
         * rows link somewhere needs the column the link is built from, and
         * dropping it on the first regroup would take the links with it.
         */
        hidden: [],
        /*
         * Two.
         *
         * Not a data limit -- the grid will group by more, and some shipped
         * widgets do. It is what fits: each slot is a picker, and a widget can
         * be as narrow as a quarter of the page, where three pickers plus the
         * filter control no longer sit on a line.
         */
        max: 2,
        onChange: null
    }, options || {} );

    this.selected = ( this.options.selected || [] ).slice();
};

OWA.dimensionSelectors.prototype = {

    value : function () {

        return this.selected.slice();
    },

    /**
     * One picker's options: everything legal, minus what the OTHER pickers
     * hold. Its own value stays, or the control would clear itself on render.
     */
    optionsFor : function ( $select, index, blankLabel ) {

        var that = this;
        var mine = this.selected[ index ];

        if ( blankLabel !== null ) {
            $select.append( jQuery( '<option>' ).attr( 'value', '' ).text( '' ) );
        }

        jQuery.each( this.options.choices || {}, function ( family, dims ) {

            var $group = jQuery( '<optgroup>' ).attr( 'label', family );
            var added  = 0;

            jQuery.each( dims || [], function ( i, dim ) {

                if ( ! dim || ! dim.name ) {
                    return;
                }

                // Used in another picker: offering it here would let one grid
                // group by the same dimension twice.
                if ( dim.name !== mine && that.selected.indexOf( dim.name ) !== -1 ) {
                    return;
                }

                $group.append( jQuery( '<option>' ).attr( 'value', dim.name )
                    .prop( 'selected', dim.name === mine )
                    .text( dim.label || dim.name ) );

                added++;
            } );

            // A family with nothing left to offer would render as a heading
            // with nothing under it.
            if ( added ) {
                $select.append( $group );
            }
        } );
    },

    display : function () {

        var that  = this;
        var $root = jQuery( this.dom_selector );

        $root.empty();

        /*
         * A widget already scoped to more dimensions than this control holds is
         * left alone.
         *
         * Grouping by several is something the report author did on purpose --
         * Latest Visits groups by seven -- so a two-slot control over it could
         * only take dimensions away, and would need seven pickers to show what
         * is there. The grid's own column headings still name every one of
         * them, so nothing becomes invisible; there is simply nothing here to
         * change.
         */
        if ( this.selected.length > this.options.max ) {

            $root.removeClass( 'owa_dimensionSelectors' );

            return;
        }

        $root.addClass( 'owa_dimensionSelectors' );

        this.selected.forEach( function ( name, index ) {

            var $slot = jQuery( '<span class="owa_dimSlot">' ).attr( 'data-index', index );

            var $select = jQuery( '<select class="owa_dimSelect">' )
                .attr( 'data-placeholder', index === 0 ? 'Dimension' : 'Add dimension' );

            // The first has no blank: the grid must be grouped by something.
            that.optionsFor( $select, index, index === 0 ? null : '' );

            $slot.append( $select );
            $root.append( $slot );

            $select.chosen( { no_results_text: 'Name not found.', width: '100%' } );

            /*
             * Let the list escape the widget while it is open.
             *
             * .owa_reportGridItem carries overflow-x:auto so a wide table
             * scrolls inside its own widget rather than widening the page --
             * and per CSS, one axis being non-visible computes the OTHER to
             * auto. So the widget is a scroll box in both directions and the
             * dropdown is clipped by it: measured at 278px tall with 74px
             * visible.
             *
             * Lifted only while a list is open, so the reason the overflow
             * exists still holds the rest of the time.
             */
            $select.on( 'chosen:showing_dropdown', function () {
                jQuery( this ).closest( '.owa_reportGridItem' ).addClass( 'owa_dropdownOpen' );
            } );

            $select.on( 'chosen:hiding_dropdown', function () {
                jQuery( this ).closest( '.owa_reportGridItem' ).removeClass( 'owa_dropdownOpen' );
            } );

            $select.on( 'change', function () {

                var picked = jQuery( this ).val();

                if ( picked ) {
                    that.selected[ index ] = picked;
                } else {
                    // Cleared: that is how a dimension is removed. Never the
                    // first, which offers no blank to begin with.
                    that.selected.splice( index, 1 );
                }

                that.display();
                that.changed();
            } );
        } );

        if ( this.selected.length < this.options.max ) {

            var $add = jQuery( '<button type="button" class="owa_dimAdd" title="Group by another dimension">' )
                .text( '+' );

            $add.on( 'click', function ( e ) {

                e.preventDefault();

                /*
                 * The new picker is drawn empty and the grid is NOT refetched
                 * yet -- there is nothing to group by until something is
                 * chosen, and refetching on an empty slot would reload the
                 * same data.
                 */
                that.selected.push( '' );
                that.display();

                jQuery( that.dom_selector + ' .owa_dimSlot' ).last()
                    .find( '.chosen-container' ).trigger( 'mousedown' );
            } );

            $root.append( $add );
        }
    },

    changed : function () {

        if ( typeof this.options.onChange === 'function' ) {

            /*
             * Empty slots are a control state, not a grouping, so they are
             * dropped -- and the hidden ones are added back, because they are
             * part of the query the grid has to reissue.
             */
            this.options.onChange(
                this.selected.filter( Boolean ).concat( this.options.hidden || [] ) );
        }
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

        jQuery(container_selector).append(
            '<span class="toggle-button"></span><span class="constraintCount" style="display:none;"></span>'
          + '<div class="builder"><ul></ul><div style="clear:both;"></div><div class="add-button"></div><div class="apply-button"></div>'
        );

        var button_selector = container_selector + ' > .toggle-button';
        var builder_selector = container_selector + ' > .builder';
        jQuery(builder_selector).hide();

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

        // setup the toggle button
        // jQuery-UI 1.12 replaced button()'s icons:{primary,secondary} with a
        // single icon + iconPosition. The old primary was just ui-icon-blank
        // (a spacer); keep the secondary dropdown triangle on the right.
        jQuery( button_selector )
            .button({
                icon: 'ui-icon-triangle-1-s',
                iconPosition: 'end',
                // 'Filter', not 'Select...': the label that used to sit beside
                // this button is gone, so the button is what names the control.
                label: OWA.l('Filter')
            })
            .click(function() {
                jQuery(builder_selector).toggle();
        });

        // setup add button
        jQuery( builder_selector + ' > .add-button' )
            .button({

                label: OWA.l('+ Add Filter ')
            })
            .click(function() {
                that.addNewConstraintRow( builder_selector + ' > ul' );
        });

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

                    if ( value ) {
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

    addNewConstraintRow : function(selector, name, operator, value) {

        // generate container

        // generate the dim/metric chooser button
        jQuery( selector ).append(
            '<LI class="constraintRow"><span class="constraintDimensionPicker"></span> <span class="constraintOperatorPicker"></span><input class="constraintValueField" type="text" size="30"><span class="constraintRemoveButton">X</span></LI>'
        );

        // create constraint dimension picker
        var dimpicker_selector = selector + ' > li:last > .constraintDimensionPicker';
        var cdp = new OWA.dimensionPicker(dimpicker_selector);
        cdp.setDimensions( this.combineRelatedMetricsWithDimensions() );
        cdp.display(name);

        // generate operatior picker
        this.makeOperatorPicker(selector + ' > li:last > .constraintOperatorPicker', operator);

        if (value) {
            jQuery(selector + ' > li:last > .constraintValueField').val(value);
        }

        // setup add button
        jQuery( selector + '> li:last > .constraintRemoveButton' )
            .button({

                label: OWA.l('X')
            })
            .click(function() {
                jQuery( this ).parent().remove();
        });




    },

    makeOperatorPicker : function( selector, selected ) {

        // append the container
        var c = ''
        //c += '<label for="operator-list">Select Operator:</label>';
        c += '<select name="operator-list" class="operator-list">';

        // build the list of operators
        for (var operator in this.operators) {

            if ( this.operators.hasOwnProperty( operator ) ) {

                c += OWA.util.sprintf(
                        '<option value="%s">%s</option>',
                        operator,
                        this.operators[operator]
                );
            }
        }

        c += '</select>';
        c += '';

        jQuery(selector).append(c);

        // Core jQuery-UI selectmenu (1.11+) replaces the Nagel fork. It has no
        // "value" setter method: set the value on the native <select> first, then
        // enhance / refresh so the widget reflects it. width is now a style, not an
        // option, so size the menu via width in the widget's classes option.
        var opList = jQuery(selector + ' > .operator-list');
        if ( selected ) {
            opList.val(selected);
        }
        opList.selectmenu({ width: 200 });

    }

};