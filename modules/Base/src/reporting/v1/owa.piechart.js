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
                     * A FIXED radius, so two pies the same width draw the same
                     * size.
                     *
                     * flot's pie radius defaults to 'auto', which means "as
                     * large as fits once the labels are placed" -- so the pie
                     * shrinks as its labels get longer or more numerous. The
                     * dashboard shows that plainly: Visitor Types has two short
                     * slices and Traffic Sources up to five plus an "others",
                     * so two widgets of identical width drew visibly different
                     * pies. Nothing about the DATA justifies that; it is the
                     * label text deciding the geometry.
                     *
                     * Under 1 so the labels flot draws around the edge have
                     * somewhere to sit.
                     */
                    radius: 0.72,

                    // NOT a flot option -- the pie plugin reads label.show,
                    // which defaults to true. Kept only because removing it
                    // would suggest labels were being turned off here.
                    showLabel: true
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

            legend: {
                show: false,
                position: "ne",
                margin: [-160,50]
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