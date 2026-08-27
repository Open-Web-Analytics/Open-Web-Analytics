// OWA is defined by owa.js; this module augments it (OWA.pieChart = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

OWA.pieChart = function( options ) {

    // config options
    this.options = {

        /*
         * How tall the plot area is. The width comes from the widget; this does
         * not, deliberately -- see setupPieChart(). Big enough for a circle
         * with its labels around it at the widths a card is drawn at.
         */
        height: 240,

        /*
         * How much of the plot box the circle fills, as a fraction of half the
         * SHORTER side. Turned into a pixel radius before it reaches flot --
         * see pixelRadius() -- because a fraction there is a fraction of a
         * number the label-fitting loop shrinks.
         *
         * Under 1 so the labels drawn around the edge have somewhere to sit.
         */
        radiusFraction: 0.72,
        width:    200,
        metric: '',
        dimension: '',
        metrics: [],
        numSlices: 5,
        showGrid: true,
        showDots: true,
        showLegend: true,
        autoSizeWidth: true,

        /*
         * Shared with the trend chart, so a value has one colour wherever it is
         * drawn. An OPTION rather than a literal in generate(), the way the
         * area chart carries it: a widget can override it, and what a chart is
         * drawing with can be read without rendering one.
         */
        colors: OWA.chartColors
    };

    // merge passed options with defaults.
    if ( options ) {

        this.mergeOptions ( options );
    }

    this.dom_id = '';
    this.domSelector = '';
}

OWA.pieChart.prototype = {

    mergeOptions: function ( options ) {

        for (var option in options) {

            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    },

    setDomId: function( dom_id ) {

        this.dom_id = dom_id;
        this.domSelector = "#"+this.dom_id + ' > .owa_pieChart';
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

    /**
     * The circle's radius in PIXELS, from the plot box.
     *
     * flot reads a radius above 1 as a pixel length and below 1 as a fraction
     * of maxRadius -- and maxRadius is what its label-fitting loop shrinks, so
     * a fraction hands the geometry to the label text. Resolving it here keeps
     * the size proportional to the widget while making it immune to that.
     *
     * The SHORTER side, so the circle fits a wide, short plot box -- which is
     * every pie now that the height no longer follows the width.
     *
     * Floored at 2 because flot's own test is `radius > 1`: a radius that came
     * out at 1 or less would be read back as a fraction, which is the exact
     * behaviour this exists to avoid.
     */
    pixelRadius : function () {

        var box = Math.min(
            jQuery( this.domSelector ).width() || this.getOption( 'width' ),
            jQuery( this.domSelector ).height() || this.getOption( 'height' )
        );

        return Math.max( 2, Math.round( box / 2 * this.getOption( 'radiusFraction' ) ) );
    },

    /**
     * The plot area: as wide as the widget, and NOT as tall.
     *
     * The height used to be the WIDTH -- a pie was a square the size of
     * whatever held it. At a quarter of a row that is a 286px circle, which
     * looks deliberate; at half a row it is a 626px one, and the widget becomes
     * the tallest thing on the report because it is round.
     *
     * A pie does not need height in proportion to width. It needs enough to
     * draw a circle and put labels round it, and the width beyond that is just
     * margin. So the height is its own option, capped at the width so a narrow
     * widget still gets a circle rather than an ellipse's worth of room.
     */
    setupPieChart : function() {

        var that = this;
        var w = this.getContainerWidth();

        var h = Math.min( w, this.getOption( 'chartHeight' ) || this.getOption( 'height' ) );

        jQuery("#"+that.dom_id).append('<div class="owa_pieChart"></div>');
        jQuery(that.domSelector).css('width', w);
        jQuery(that.domSelector).css('height', h);
    },
    
    generate : function ( resultSet, dom_id, options ) {

         OWA.debug('generating pie chart');

         if ( dom_id ) {

             this.setDomId( dom_id );
         }

         dom_id = this.dom_id;

         if ( options ) {

             this.mergeOptions( options );
         }

        var selector = this.domSelector
        var that = this;
        //create data array
        var data = [];
        var count = 0;

        if (this.options.dimension.length > 0) {
        // plots a dimensional set of data

            if (resultSet.resultsRows.length > 0) {

                var dimension = this.options.dimension;
                var numSlices = this.options.numSlices;
                var metric = this.options.metric;

                //create data array
                var iterations = 0;
                if (numSlices > resultSet.resultsRows.length) {
                    iterations = resultSet.resultsRows.length;
                } else {
                    iterations = numSlices;
                }


                for(var i=0;i<=iterations -1;i++) {

                    // The FORMATTED value when there is one, falling back to
                    // the raw one. A boolean dimension stores 1 for true and
                    // NULL for false, so the raw value labels slices "1" and
                    // "" -- and any dimension with a formatter was being shown
                    // unformatted here while the grid showed it properly.
                    var cell = resultSet.resultsRows[i][dimension];

                    // The report's own label for this VALUE wins: a boolean
                    // pie formats as Yes/No, which is correct but makes the
                    // reader supply the question from the title. Keyed on the
                    // raw value, which is what the query returned.
                    // Flat: the explorer's options.pieChart.* are merged down
                    // onto this chart, so here they are this.options.* -- the
                    // same way dimension and metric are read above.
                    var labels = that.options.valueLabels;
                    var raw_key = (cell.value === null || cell.value === undefined) ? '' : String(cell.value);

                    var slice_label;

                    if (labels && Object.prototype.hasOwnProperty.call(labels, raw_key)) {
                        slice_label = labels[raw_key];
                    } else if (cell.formatted_value === null || cell.formatted_value === undefined || cell.formatted_value === '') {
                        slice_label = cell.value;
                    } else {
                        slice_label = cell.formatted_value;
                    }

                    var item = {label: slice_label, data: resultSet.resultsRows[i][metric].value * 1};
                    data.push(item);
                    count = count + resultSet.resultsRows[i][metric].value;
                }

                // if there are extra slices then lump into other bucket.
                if (resultSet.resultsRows.length > iterations) {
                    var others = resultSet.aggregates[metric] - count;
                    data.push({label: 'others', data: others});
                }

            } else {
                //no results
                jQuery('#'+ that.dom_id).append("No data is available for this time period");
                jQuery('#'+ that.dom_id).css('height', '50px');

            }
        } else {

             if (!jQuery.isEmptyObject(resultSet.aggregates)) {
                // plots a set of values taken from the aggregrate metrics array
                var metrics = this.options.metrics;
                for(var ii=0;ii<=metrics.length -1 ;ii++) {
                    var value = resultSet.aggregates[metrics[ii]].value * 1;
                    data.push({label: resultSet.getMetricLabel(metrics[ii]), data: value});
                }
            } else {
                //OWA.setSetting('debug', true);
                //OWA.debug('there was no data');
                //alert('hi');
                jQuery('#'+ that.dom_id).append("No data is available for this time period");
                jQuery('#'+ that.dom_id).css('height', '50px');

            }

        }

        if ( ! this.init ) {

            this.setupPieChart();
        }

        // options
        var flot_options = {
            series: {
                pie: {
                    show: true,

                    /*
                     * A radius in PIXELS, so the label text cannot decide the
                     * geometry.
                     *
                     * flot's pie plugin fits its labels by SHRINKING: drawPie()
                     * returns false when a label div lands outside the canvas,
                     * and the caller multiplies maxRadius by 0.95 and tries
                     * again, up to ten times. So a pie labelled
                     * "organic-search" draws smaller than one labelled "New",
                     * on identical canvases, with nothing in the data to
                     * justify it.
                     *
                     * This was already diagnosed here and fixed the wrong way.
                     * `radius: 0.72` pins the FRACTION -- and the fraction was
                     * never the problem, because flot computes
                     * `maxRadius * radius` and maxRadius is the thing that
                     * shrinks. Pinning it changed nothing.
                     *
                     * A value ABOVE 1 is read as a pixel length and used
                     * verbatim (see the plugin's `radius > 1 ? radius :
                     * maxRadius * radius`), which the shrink loop cannot reach.
                     * The loop still runs while the labels overflow, and still
                     * pulls maxRadius in -- but maxRadius now positions only
                     * the LABELS, so what moves is the text, and the pie stays
                     * the size it was asked for.
                     *
                     * Computed per pie from the plot box rather than written
                     * down, so it still tracks a widget that is genuinely
                     * smaller.
                     */
                    radius: this.pixelRadius(),

                    /*
                     * NO LABELS AROUND THE EDGE. They are a legend now -- see
                     * the `legend` block below.
                     *
                     * Round-the-edge labels are what made two pies different
                     * sizes: flot fits them by SHRINKING the pie, so the width
                     * of the words "organic-search" decided the geometry. They
                     * also collide with each other on a narrow slice and push
                     * the circle off centre.
                     *
                     * A legend to the right says the same thing in a column,
                     * reads in one place instead of five, and cannot overflow
                     * the canvas -- which is the condition the shrink loop
                     * exists to resolve.
                     */
                    label: { show: false }
/*
                    label: {
                        show: true,
                        background: {
                            color: '#ffffff',
                            opacity: '.7'
                        },
                        radius:1,
                        formatter: function(label, slice){
                            return '<div style="font-size:x-small;text-align:center;padding:2px;color:'+slice.color+';">'+Math.round(slice.percent)+'%</div>';
                        }
                        //formatter: function(label, slice){ return '<div style="font-size:x-small;text-align:center;padding:2px;color:'+slice.color+';">'+label+'<br/>'+Math.round(slice.percent)+'%</div>';}

                    }
*/
                }
            },

            /*
             * The slice names, in a column to the right of the pie.
             *
             * flot's pie plugin measures the legend and shifts the circle's
             * centre left by half its width, so the two sit side by side rather
             * than overlapping -- this is the plugin's own arrangement, not
             * something positioned on top of it.
             *
             * The percentage travels with the name, because that is what the
             * round-the-edge labels carried and it is the number a pie is read
             * for. `series.percent` is set by the plugin before the legend is
             * built.
             */
            legend: {
                show: this.getOption( 'showLegend' ),
                position: "ne",
                margin: [ 0, 0 ],
                labelFormatter: function ( label, series ) {

                    return label + ' ' + Math.round( series.percent ) + '%';
                }
            },
            colors: this.options.colors
        };

        //GRAPH
        OWA.debug(JSON.stringify(data));
        jQuery.plot(jQuery(selector), data, flot_options);
        this.init = true;
    },
    
    // moved when migrating pie chart
    getContainerWidth : function() {

        var that = this;

        if (this.getOption('autoSizeWidth')) {
            return jQuery("#"+that.dom_id).width();
        } else {
            return this.option.width;
        }
    },

    //move when migrating pie chart
    getContainerHeight : function() {
        var that = this;
        var h =  jQuery("#"+that.dom_id).height();
        //alert(h);
        return h;

    }

}