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
         * One colour per metric, and enough of them.
         *
         * A trend used to plot one metric, so three colours was three more than
         * it needed. Now that a trend can carry a metric set, every metric is
         * its own line and the palette has to separate them -- these are picked
         * to stay distinguishable rather than to sit on a colour wheel.
         *
         * The first is the one a single-metric trend has always drawn in, so
         * the sixty-one shipped trends look exactly as they did.
         */
        colors: ["#1874CD", "#dba255", "#919733", "#c0504d", "#7a5195", "#2e8b57"],

        /*
         * The colour of the synthetic Total. Deliberately not from the palette
         * above: it is not one of the metrics, and a reader should be able to
         * tell that without reading the label.
         */
        totalColor: "#4d4d4d",

        /* What the other lines fade to when one is selected in the legend. */
        dimmedOpacity: 0.5
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

            // create data array for flot.
            var dataseries = [];

            series = this.options.series;

            var data_type_x = '';

            for( var ii = 0; ii <= series.length -1; ii++ ) {

                var x_series_name = series[ii].x;
                var y_series_name = series[ii].y;

                /*
                 * A NEW array per series.
                 *
                 * This used to be declared once, outside the loop, so every
                 * series pushed into the same array AND every entry in
                 * dataseries referenced that one object -- so a chart of two
                 * metrics drew two identical lines, each holding both metrics'
                 * points end to end. A trend charted one metric everywhere it
                 * shipped, so nothing ever exercised it.
                 */
                var data = [];

                for( var i=0; i <= resultSet.resultsRows.length -1; i++ ) {

                    data_type_x = resultSet.resultsRows[i][x_series_name].data_type;

                    var data_type_y = resultSet.resultsRows[i][y_series_name].data_type;

                    /*
                     * The y value as a NUMBER.
                     *
                     * A result set carries metric values as strings, and flot
                     * coerces them when it plots -- so a single line has always
                     * drawn correctly and nothing needed this. It matters now:
                     * the Total series is arithmetic over these, and a chart
                     * mixing string series with a numeric one is one type
                     * confusion away from an axis scaled lexically.
                     */
                    var item = [
                        this.formatValue( data_type_x, resultSet.resultsRows[i][x_series_name].value ),
                        this.formatValue( data_type_y, resultSet.resultsRows[i][y_series_name].value ) * 1
                    ];

                    data.push( item );
                }

                dataseries.push( {
                    label: resultSet.getMetricLabel( y_series_name ),
                    metric: y_series_name,
                    data: data
                } );
            }

            /*
             * The sum of everything plotted, drawn first.
             *
             * Only when there is more than one metric: a "total" of one line is
             * that line, and drawing it twice would say nothing.
             *
             * The sum is only meaningful when the metrics are of a kind -- four
             * counts add up, a count and a rate do not. That is the report
             * author's judgement, not this function's: it charts the metric set
             * it was given.
             */
            if ( dataseries.length > 1 ) {

                dataseries.unshift( this.totalSeries( dataseries ) );
            }

            // A colour each, fixed here rather than left to flot's rotation, so
            // the legend, the lines and the dimming below all agree about which
            // colour belongs to which metric.
            for ( var c = 0; c < dataseries.length; c++ ) {

                dataseries[c].color = dataseries[c].isTotal
                    ? this.options.totalColor
                    : this.options.colors[ ( c - ( dataseries[0].isTotal ? 1 : 0 ) ) % this.options.colors.length ];
            }

            this.setupAreaChart( series, dom_id );

            var num_ticks = resultSet.resultsRows.length;

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
                     * FILLED only when there is one line.
                     *
                     * The fill is what makes a single trend read as an area
                     * chart, and it is why one has always been drawn that way.
                     * Several translucent fills stacked on each other muddy
                     * every colour underneath, which defeats the point of
                     * giving each metric its own.
                     */
                    lines: {
                        show: true,
                        fill: dataseries.length === 1,
                        fillColor: this.options.fillColor,
                        lineWidth: this.options.lineWidth
                    }
                },
                legend: {
                    show: this.options.showLegend,

                    /*
                     * BELOW the chart, not floating inside it.
                     *
                     * flot draws its own legend over the plot area, where it
                     * covers the very data it is labelling -- tolerable for one
                     * entry, useless for five. Given a container it renders
                     * there instead, and the container sits after the plot in
                     * the widget, which puts it under the x-axis labels.
                     */
                    container: jQuery( '#' + dom_id + ' > .owa_chartLegend' ),
                    noColumns: dataseries.length
                }
            };

            if (data_type_x === 'yyyymmdd') {

                this.flotOptions.xaxis.mode = "time";
                this.flotOptions.xaxis.timeformat = this.options.timeformat;
            }

            OWA.debug('Plotting area graph in ' + selector);

            this.dataseries = dataseries;
            this.selected   = null;

            this.draw();

            this.bindLegend();

            this.init = true;

        } else {
            jQuery('#'+ dom_id).html("No data is available for this time period");
            jQuery('#'+ dom_id).css('height', '50px');
        }
    },

    /**
     * The sum of every plotted metric, point by point.
     *
     * By POSITION, not by x value: every series here comes from the same result
     * set rows, so row i is the same moment in all of them. Matching on x would
     * be the same answer with a lookup in front of it.
     */
    totalSeries : function ( dataseries ) {

        var points = [];

        for ( var i = 0; i < dataseries[0].data.length; i++ ) {

            var sum = 0;

            for ( var s = 0; s < dataseries.length; s++ ) {

                var point = dataseries[s].data[i];

                // A series shorter than the first contributes nothing at this
                // point rather than making the total NaN from there on.
                if ( point ) {

                    sum += point[1];
                }
            }

            points.push( [ dataseries[0].data[i][0], sum ] );
        }

        return { label: 'Total', metric: '__total', isTotal: true, data: points };
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
        jQuery("#"+dom_id).html('<div class="owa_areaChart"></div><div class="owa_chartLegend"></div>');

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
