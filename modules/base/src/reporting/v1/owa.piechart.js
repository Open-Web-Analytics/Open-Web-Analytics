OWA.pieChart = function( options ) {

    // config options
    this.options = {

        height: 200,
        width:    200,
        metric: '',
        dimension: '',
        metrics: [],
        numSlices: 5,
        showGrid: true,
        showDots: true,
        showLegend: true,
        autoSizeWidth: true
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

        for (option in options) {

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

    setupPieChart : function() {

        var that = this;
        var w = this.getContainerWidth();
        //alert(w);
        var h = this.getContainerWidth(); //this.getOption('chartHeight');
        //alert(h);
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

                    var item = {label: resultSet.resultsRows[i][dimension].value, data: resultSet.resultsRows[i][metric].value * 1};
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
            colors: ["#6BAED6", "#FD8D3C", "#dba255", "#919733"]
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