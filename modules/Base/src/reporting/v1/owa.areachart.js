// OWA is defined by owa.js; this module augments it (OWA.areaChart = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

OWA.areaChart = function( options ) {

    // config options
    this.options = {
        
        series: [],
        height: 125,
        width:    '99%', // needed for flot resize plugin
        xaxis: {
            mode: 'time'
            
        },
        timeformat: "%m/%d",
        showGrid: true,
        showLegend: true,
        showDots: true,
        lineWidth: 4,
        autoResizeCharts: true,
        fillColor: "rgba(202,225,255, 0.6)",

        /*
         * One colour per line, and enough of them.
         *
         * A trend used to draw one line, so three colours was two more than it
         * needed. Now a dimension's values each get one -- visits by medium is
         * a line per medium -- and the palette has to keep them apart.
         */
        colors: ["#dba255", "#919733", "#c0504d", "#7a5195", "#2e8b57", "#b5651d", "#4f81bd", "#8064a2"],

        /*
         * The total keeps the colour a trend has always been drawn in, because
         * it IS the trend: the same filled area, with the lines it is made of
         * drawn over it.
         */
        totalColor: "#1874CD",

        /*
         * How many of a dimension's values get their own line.
         *
         * A breakdown by page path has as many values as there are pages, and
         * a hundred lines is not a chart. The six largest get a line each and
         * everything else is summed into one -- so the drawn lines still
         * account for every row, and Other plus the six is the total behind
         * them rather than a number with a silent gap in it.
         */
        maxSeries: 6,

        /* What the values past the cap are called once they are added up. */
        otherLabel: 'Other',

        /* What the other lines fade to when one is selected in the legend. */
        dimmedOpacity: 0.5,

        /* The x axis a reader can choose between, and what each queries. */
        granularities: [
            { dimension: 'date',  label: 'Day' },
            { dimension: 'month', label: 'Month' }
        ],

        monthFormat: "%b %Y"
    };
    
    // merge passed options with defaults.
    if ( options ) {
        
        for (var option in options) {
            
            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    }
    
    this.dom_id = '';
    this.domSelector = '';
    this.init = false;
}

OWA.areaChart.prototype = {
        
    setDomId: function(dom_id) {
        
        this.dom_id = dom_id;
        this.domSelector = "#" + dom_id + ' > .owa_areaChart';
        
        // listen for data change events
        var that = this;
        jQuery( '#' + that.dom_id ).bind( 'new_result_set', function( event, resultSet ) {
            //jQuery( that.domSelector ).remove();
            that.generate( resultSet );
        });
        
    },
    
    getOption: function( name ) {
        
        if ( this.options.hasOwnProperty( name ) ) {
            return this.options[ name ];
        }
    },
    
    setOption: function ( name, value ) {
        
        this.options[name] = value;
    },
    
    getContainerHeight : function() {
        
        var that = this;
        var h =  jQuery("#"+that.dom_id).height();
        //alert(h);
        return h;    
    },
    
    // move to OWA.util
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
                
            // 202608 -> the first of that month. The `month` COLUMN stores
            // yyyymm despite its name, which is why a month axis orders
            // correctly across a year boundary.
            case 'yyyymm':

                var m_year  = String( value ).substring( 0, 4 ) * 1;
                var m_month = ( String( value ).substring( 4, 6 ) * 1 ) - 1;

                value = Date.UTC( m_year, m_month, 1, 0, 0, 0, 0 );
                break;

            case 'currency':
                value = value/100;
        }
        
        return value;
    },
    
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
    generate : function( resultSet, series, dom_id ) {
        OWA.debug('generating area chart for ' + dom_id);
        // set dom_id just in case.
        if ( dom_id ) {
        
            this.setDomId( dom_id );
        }
        
        dom_id = this.dom_id;
        
        // set series just in case.
        if ( series ) {
        
            this.options.series = series;
        }
        
        var selector = this.domSelector;
        
        // remove in case the chart is already there.
        // this is kind of a hack as it mean that only one area chart can be placed in a dom_id at a time.
        // this is needed so that charts can be over riden when report
        // tabs change.
        jQuery( selector ).remove();
        
        // if there is data, plot it.
        if ( resultSet.resultsRows.length > 0 ) {

            series = this.options.series;

            /*
             * A trend charts ONE metric.
             *
             * What varies is the DIMENSION: given one, each of its values
             * becomes a line -- visits by medium is a line per medium -- and
             * the filled area behind them is their total. Given none, the
             * metric itself is the single filled area, which is what a trend
             * has always been and what all sixty-one shipped ones still are.
             *
             * The call shape is unchanged: an array of {x, y}, now with an
             * optional `series` naming the dimension to break down by. Four
             * older templates still call it the old way and go on working.
             */
            var spec = series[0] || {};

            var x_name    = spec.x;
            var y_name    = spec.y;
            var breakdown = spec.series || null;

            this.xDimension = x_name;

            var built = breakdown
                ? this.seriesByDimension( resultSet, x_name, y_name, breakdown )
                : this.singleSeries( resultSet, x_name, y_name );

            var dataseries = built.series;

            this.setupAreaChart( series, dom_id );

            var num_ticks = built.points;

            // reduce number of x axis ticks if data set has too many points.
            if ( num_ticks > 10 ) {

                num_ticks = 10;
            }

            this.flotOptions = {

                yaxis: {
                    tickDecimals:0 },
                xaxis:{
                    ticks: num_ticks,
                    tickDecimals: null
                },
                grid: {show: this.options.showGrid, hoverable: true, autoHilight:true, borderWidth:0, borderColor: null},
                series: {
                    points: { show: this.options.showDots, fill: this.options.showDots },

                    /*
                     * Fill is decided PER SERIES, on the series itself.
                     *
                     * The total is an area, the way a trend has always looked;
                     * the lines broken out of it are lines. Several translucent
                     * fills stacked on each other muddy every colour underneath,
                     * which is the whole reason the breakdown is not filled.
                     */
                    lines: {
                        show: true,
                        fill: false,
                        lineWidth: this.options.lineWidth
                    }
                },
                legend: {
                    show: this.options.showLegend && dataseries.length > 1,

                    /*
                     * BELOW the chart, not floating inside it.
                     *
                     * flot draws its own legend over the plot area, where it
                     * covers the very data it is labelling -- tolerable for one
                     * entry, useless for eight. Given a container it renders
                     * there instead, and the container sits after the plot in
                     * the widget, which puts it under the x-axis labels.
                     */
                    container: jQuery( '#' + dom_id + ' > .owa_chartLegend' ),
                    noColumns: dataseries.length
                }
            };

            if ( built.x_type === 'yyyymmdd' || built.x_type === 'yyyymm' ) {

                this.flotOptions.xaxis.mode = "time";

                // A month axis labelled with days would repeat the same day
                // number every tick.
                this.flotOptions.xaxis.timeformat = built.x_type === 'yyyymm'
                    ? this.options.monthFormat
                    : this.options.timeformat;
            }

            OWA.debug('Plotting area graph in ' + selector);

            /*
             * A colour each, fixed here rather than left to flot's rotation, so
             * the legend, the lines and the dimming all agree about which
             * colour belongs to which line. The total is always first.
             */
            for ( var c = 0; c < dataseries.length; c++ ) {

                dataseries[c].color = dataseries[c].isTotal
                    ? this.options.totalColor
                    : this.options.colors[ ( c - 1 ) % this.options.colors.length ];
            }

            this.dataseries = dataseries;
            this.selected   = null;

            this.draw();

            this.bindLegend();

            this.drawGranularityControl( dom_id );

            this.init = true;

        } else {
            jQuery('#'+ dom_id).html("No data is available for this time period");
            jQuery('#'+ dom_id).css('height', '50px');
        }
    },

    /**
     * One metric over time: the filled area a trend has always been.
     *
     * @return {series, points, x_type}
     */
    singleSeries : function ( resultSet, x_name, y_name ) {

        var data   = [];
        var x_type = '';

        for ( var i = 0; i < resultSet.resultsRows.length; i++ ) {

            var row = resultSet.resultsRows[i];

            x_type = row[ x_name ].data_type;

            data.push( [
                this.formatValue( x_type, row[ x_name ].value ),
                row[ y_name ].value * 1
            ] );
        }

        return {
            series: [ this.areaSeries( resultSet.getMetricLabel( y_name ), data ) ],
            points: data.length,
            x_type: x_type
        };
    },

    /**
     * One metric, broken out by the values of one dimension.
     *
     * The rows arrive as (x, dimension value) pairs, so they are pivoted here:
     * a line per value, and the total across ALL of them as the area behind.
     *
     * The total is the sum of every row, not of the lines that get drawn. Only
     * the largest few values are drawn -- a breakdown by page path would
     * otherwise be a hundred lines and no chart -- and a total that quietly
     * left the rest out would be a different number from the one the report's
     * metric boxes show.
     *
     * @return {series, points, x_type}
     */
    seriesByDimension : function ( resultSet, x_name, y_name, breakdown ) {

        var x_type  = '';
        var xs      = [];      // every x value, in the order first seen
        var seen_x  = {};
        var totals  = {};      // x -> the sum across every dimension value
        var byValue = {};      // dimension value -> { label, points: {x: y} , total }

        for ( var i = 0; i < resultSet.resultsRows.length; i++ ) {

            var row = resultSet.resultsRows[i];

            if ( ! row[ x_name ] || ! row[ y_name ] || ! row[ breakdown ] ) {

                continue;
            }

            x_type = row[ x_name ].data_type;

            var x = this.formatValue( x_type, row[ x_name ].value );
            var y = row[ y_name ].value * 1;

            if ( ! seen_x[ x ] ) {

                seen_x[ x ] = true;
                xs.push( x );
            }

            totals[ x ] = ( totals[ x ] || 0 ) + y;

            var cell = row[ breakdown ];

            var key = ( cell.value === null || cell.value === undefined ) ? '' : String( cell.value );

            if ( ! byValue[ key ] ) {

                byValue[ key ] = {
                    label: ( cell.formatted_value === null || cell.formatted_value === undefined
                        || cell.formatted_value === '' ) ? key : cell.formatted_value,
                    points: {},
                    total: 0
                };
            }

            byValue[ key ].points[ x ] = ( byValue[ key ].points[ x ] || 0 ) + y;
            byValue[ key ].total += y;
        }

        xs.sort( function ( a, b ) { return a - b; } );

        // The biggest first, by the metric being charted, so the cap keeps
        // what matters rather than whatever the sort happened to return first.
        var ranked = Object.keys( byValue ).sort( function ( a, b ) {

            return byValue[b].total - byValue[a].total;
        } );

        var values = ranked.slice( 0, this.options.maxSeries );
        var rest   = ranked.slice( this.options.maxSeries );

        var built = [];

        // The total, first and filled -- it is the shape of the whole thing,
        // and the lines are what it is made of.
        built.push( this.areaSeries( 'Total', xs.map( function ( x ) {

            return [ x, totals[ x ] ];

        } ) ) );

        for ( var v = 0; v < values.length; v++ ) {

            var entry = byValue[ values[v] ];

            built.push( {
                label: entry.label,
                dimensionValue: values[v],

                /*
                 * A missing x is a ZERO, not a gap.
                 *
                 * A dimension value with no rows on a given day had none of
                 * that metric that day. Leaving the point out would make flot
                 * join the days either side of it, drawing a line straight over
                 * an absence and reading as "steady" where the answer is
                 * "nothing".
                 */
                data: xs.map( function ( x ) {

                    return [ x, entry.points[ x ] || 0 ];
                } ),

                lines: { fill: false }
            } );
        }

        /*
         * Everything past the sixth, as one line.
         *
         * Summed rather than dropped: a reader comparing the lines against the
         * area behind them should be able to account for all of it, and six
         * lines under a total they visibly do not add up to is a chart that
         * raises a question it cannot answer.
         */
        if ( rest.length ) {

            built.push( {
                label: this.options.otherLabel,
                isOther: true,
                data: xs.map( function ( x ) {

                    var sum = 0;

                    for ( var r = 0; r < rest.length; r++ ) {

                        sum += byValue[ rest[r] ].points[ x ] || 0;
                    }

                    return [ x, sum ];
                } ),

                lines: { fill: false }
            } );
        }

        return { series: built, points: xs.length, x_type: x_type };
    },

    /** The filled series -- the one that makes this an AREA chart. */
    areaSeries : function ( label, data ) {

        return {
            label: label,
            isTotal: true,
            data: data,
            lines: { fill: true, fillColor: this.options.fillColor }
        };
    },

    /**
     * The day/month control, top right of the chart.
     *
     * A trend is a shape over time, and which time -- days or months -- is a
     * question about how you want to read it rather than about what the report
     * is. So it is a reader's control, not something baked into the definition:
     * the stored query says `date`, and this rewrites it on the way out.
     *
     * Drawn only when the chart knows who to ask for new data. The explorer
     * owns fetching; a select that could not refetch would be a control that
     * does nothing, which is worse than no control.
     */
    drawGranularityControl : function ( dom_id ) {

        var that = this;
        var $box = jQuery( '#' + dom_id + ' > .owa_chartControls' );

        $box.empty();

        if ( ! this.explorer || ! this.explorer.resultSet ) {

            return;
        }

        var current = this.xDimension;

        var known = this.options.granularities.filter( function ( g ) {

            return g.dimension === current;
        } );

        if ( ! known.length ) {

            // The chart is over something this control does not know how to
            // switch between. Leaving it off is the honest answer.
            return;
        }

        var $select = jQuery( '<select class="owa_chartGranularity">' )
            .attr( 'title', 'Show this trend by day or by month' );

        this.options.granularities.forEach( function ( g ) {

            $select.append( jQuery( '<option>' ).attr( 'value', g.dimension )
                .prop( 'selected', g.dimension === current ).text( g.label ) );
        } );

        $select.on( 'change', function () {

            that.changeGranularity( jQuery( this ).val() );
        } );

        $box.append( $select );
    },

    /**
     * Ask for the same trend at a different grain.
     *
     * The x dimension is swapped in the result-set URL and the whole thing is
     * refetched -- the grouping happens in SQL, so there is nothing to
     * recompute here and nothing that could be recomputed correctly: a month's
     * value is not the sum of the days that were returned, it is the sum of the
     * days that exist.
     *
     * The sort travels with it. It orders by the x dimension, and a sort naming
     * a dimension the query no longer has is a sort the server cannot resolve.
     */
    changeGranularity : function ( dimension ) {

        if ( ! dimension || dimension === this.xDimension || ! this.explorer ) {

            return;
        }

        var url  = new OWA.uri( this.explorer.resultSet.self );
        var key  = OWA.util.appNs( 'dimensions' );
        var dims = OWA.util.urldecode( url.getQueryParam( key ) || '' ).split( ',' );

        var was = this.xDimension;

        var swapped = dims.map( function ( name ) {

            return name.trim() === was ? dimension : name.trim();

        } ).filter( Boolean );

        url.setQueryParam( key, swapped.join( ',' ) );
        url.setQueryParam( OWA.util.appNs( 'sort' ), dimension );

        // A different grain is a different number of points, so the page the
        // reader was on no longer means anything.
        url.removeQueryParam( OWA.util.appNs( 'page' ) );

        this.xDimension = dimension;

        // The series spec is what generate() reads on the way back in, and the
        // refetch arrives as a new_result_set event carrying no spec of its own.
        if ( this.options.series[0] ) {

            this.options.series[0].x = dimension;
        }

        this.explorer.getNewResultSet( url.getSource() );
    },

    /**
     * Plot what is in this.dataseries, dimming everything but the selection.
     *
     * Redrawn rather than restyled: flot owns the canvas, and there is no
     * per-series opacity to change after the fact. Recolouring the series and
     * plotting again is how a flot chart changes its appearance.
     */
    draw : function () {

        var that = this;

        var plotted = this.dataseries.map( function ( s, i ) {

            var copy = jQuery.extend( {}, s );

            if ( that.selected !== null && that.selected !== i ) {

                copy.color = that.fade( s.color, that.options.dimmedOpacity );
            }

            return copy;
        } );

        jQuery.plot( jQuery( this.domSelector ), plotted, this.flotOptions );

        // What was actually handed to flot, so the drawn state is readable and
        // not only visible. The colours here ARE the dimming.
        this.plotted = plotted;

        this.markLegend();
    },

    /**
     * A colour at partial opacity.
     *
     * flot accepts any CSS colour for a series, so an rgba() string is the
     * whole mechanism -- there is nothing to set on the line itself.
     */
    fade : function ( color, alpha ) {

        var hex = String( color ).replace( '#', '' );

        if ( hex.length === 3 ) {

            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }

        if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {

            return color;   // already rgba(), or a name -- leave it alone
        }

        return 'rgba(' + parseInt( hex.substring( 0, 2 ), 16 )
            + ',' + parseInt( hex.substring( 2, 4 ), 16 )
            + ',' + parseInt( hex.substring( 4, 6 ), 16 )
            + ',' + alpha + ')';
    },

    /**
     * Clicking a legend entry brings that line forward.
     *
     * Bound once per plot, on the container rather than on the entries, because
     * flot rebuilds the legend's contents on every draw -- and every draw is
     * what a click causes.
     */
    bindLegend : function () {

        var that = this;
        var $legend = jQuery( '#' + this.dom_id + ' > .owa_chartLegend' );

        $legend.addClass( 'owa_chartLegendInteractive' ).off( 'click.owaSeries' );

        if ( this.dataseries.length < 2 ) {

            // One line is always the selected one. Offering a control that can
            // only turn itself off is worse than offering none.
            $legend.removeClass( 'owa_chartLegendInteractive' );

            return;
        }

        $legend.on( 'click.owaSeries', '.legendLabel, .legendColorBox', function () {

            /*
             * The series index is stamped on the cell, not read from its row.
             *
             * The legend is laid out with one COLUMN per series so it reads
             * left to right, which means every entry is in the same single
             * <tr> -- so closest('tr').index() is 0 for all of them, and every
             * click selected the first series.
             */
            var index = parseInt( jQuery( this ).attr( 'data-series' ), 10 );

            if ( isNaN( index ) || index < 0 || index >= that.dataseries.length ) {

                return;
            }

            // Clicking the selected one again puts every line back, so there is
            // always a way out that does not need a second control.
            that.selected = ( that.selected === index ) ? null : index;

            that.draw();
        } );
    },

    /**
     * Number the legend entries, and say which one is selected.
     *
     * Run after every draw, because flot rebuilds the legend's contents each
     * time -- so the numbering has to be reapplied, not set once.
     *
     * The entries are cells rather than rows: the legend is one column per
     * series so that it reads left to right, which puts them all in a single
     * <tr>. Each label and its colour swatch carry the index, which is what the
     * click handler reads and what these classes hang on.
     */
    markLegend : function () {

        var $legend  = jQuery( '#' + this.dom_id + ' > .owa_chartLegend' );
        var selected = this.selected;

        $legend.find( '.legendLabel' ).each( function ( i ) {

            var $label = jQuery( this );
            var $swatch = $label.prevAll( '.legendColorBox' ).first();

            $label.add( $swatch )
                .attr( 'data-series', i )
                .toggleClass( 'owa_seriesSelected', selected === i )
                .toggleClass( 'owa_seriesDimmed', selected !== null && selected !== i );
        } );
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

    
    setupAreaChart : function(series, dom_id) {
        
        dom_id = dom_id || this.dom_id;
        
        var that = this;
        
        //var w = this.getContainerWidth();
        var w = jQuery("#"+dom_id).css('width');
        //alert(w);
        var h = this.getContainerHeight() || this.getOption('height');
        //var h = this.getOption('height');
        
        /*
         * The plot, and a place under it for the legend.
         *
         * After the plot in the markup, so the legend lands below the x-axis
         * labels -- flot draws those inside the plot's own area, so anything
         * that follows the plot element is below them.
         */
        jQuery("#"+dom_id).html(
            '<div class="owa_chartControls"></div>'
          + '<div class="owa_areaChart"></div>'
          + '<div class="owa_chartLegend"></div>' );

        jQuery(that.domSelector).css('width', this.getOption('width'));
        jQuery(that.domSelector).css('height', h);
        
        // binds a tooltip to plot points
        var previousPoint = null;
        jQuery(that.domSelector).bind("plothover", function (event, pos, item) {

            jQuery("#x").text(pos.x.toFixed(2));
            jQuery("#y").text(pos.y.toFixed(2));
            
            if (item) {
                if (previousPoint != item.datapoint) {
                    
                    previousPoint = item.datapoint;
                    
                    jQuery("#tooltip").remove();
                    var x = item.datapoint[0].toFixed(0),
                        y = item.datapoint[1].toFixed(0);
                        
                    if (that.options.xaxis.mode === 'time') {
                    
                        x = that.timestampFormatter(x);
                    }
                    
                    that.showTooltip(item.pageX -75, item.pageY -50,
                                x+'<BR><B>'+item.series.label + ":</B> " + y);
                }
            } else {
                jQuery("#tooltip").remove();
                previousPoint = null;            
            }
        });  
    }    
}
