// OWA is defined by owa.js; this module augments it (OWA.kpiBox = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

OWA.kpiBox = function( options ) {

    // config options
    this.options = {

        width: '',

        /*
         * A sparkline inside every box.
         *
         * Off when the boxes sit under a trend: the chart above them already
         * draws the shape over time, at a size you can actually read, so a
         * thumbnail of it in each box is the same information twice -- and the
         * boxes are how you choose which of them the chart draws, which the
         * sparklines make harder to scan rather than easier.
         */
        showSparklines: true
    };

    // merge passed options with defaults.
    if ( options ) {

        this.mergeOptions ( options );
    }

    this.dom_id = '';
    this.domSelector = '';
}

OWA.kpiBox.prototype = {

    mergeOptions: function ( options ) {

        for (var option in options) {

            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    },

    setDomId: function( dom_id ) {

        this.dom_id = dom_id;
        /*
         * '#' + the id. Without it this read as a TYPE selector -- an element
         * called <siteTrend-metrics> -- which is valid CSS matching nothing, so
         * the remove() below was a silent no-op and every new result set
         * appended ANOTHER full set of boxes under the old ones. Every refetch
         * doubled them: a granularity change, a page change, a site change.
         *
         * The area chart and the pie chart both build this selector with the
         * '#'; this was the one that did not.
         */
        this.domSelector = '#' + this.dom_id + ' > .metricInfoboxesContainer';
        // listen for data change events
        var that = this;
        jQuery( '#' + that.dom_id ).bind( 'new_result_set', function( event, resultSet ) {
            jQuery( that.domSelector ).remove();
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

    generate : function(resultSet, dom_id, options) {

        OWA.debug('Generating KPI box for: ' + dom_id + ' with options: ' + JSON.stringify(options));
        if ( dom_id ) {

             this.setDomId( dom_id );
         }

         dom_id = this.dom_id;

         if ( options ) {

             this.mergeOptions( options );
         }

         var html = '';
         var con_id = 'kpiContainer-'+ resultSet.guid;
        jQuery('#' + dom_id).append(OWA.util.sprintf('<div id="%s" class="metricInfoboxesContainer" style="width:auto;"></div><div style="clear:both;"></div>', con_id ) );
        //jQuery('#' + dom_id).append('<div style="clear:both;"></div>');

        for(var i in resultSet.aggregates) {

            if (resultSet.aggregates.hasOwnProperty(i)) {
                var item = resultSet.aggregates[i];

                item.dom_id = dom_id + '-' + resultSet.aggregates[i].name+'-'+ resultSet.guid;

                if (this.options.label) {
                    item.label = this.options.label;
                }

                if ( this.options.width ) {
                    item.width = this.options.width;
                }
                var width = item.width || 'auto';
                /*
                 * The metric NAME on the box, not only inside its id.
                 *
                 * The id is `<dom_id>-<metric>-<guid>`, so the name was there
                 * but only recoverable by taking the string apart -- and both
                 * ends of it contain hyphens. Anything that wants to know which
                 * metric a box shows reads this.
                 */
                var html = OWA.util.sprintf(
                    '<div id ="%s" class="owa_metricInfobox" data-metric="%s" style="min-width:135px;width:%s">',
                    item.dom_id, item.name, width );
                html += OWA.util.sprintf('<p class="owa_metricInfoboxLabel">%s</p>', item.label);
                html += OWA.util.sprintf('<p class="owa_metricInfoboxLargeNumber">%s</p>', item.formatted_value);
                html += '</div>';

                jQuery('#' + con_id ).append( html );

                if ( this.options.showSparklines ) {

                    var spark_options = {
                        metric: resultSet.aggregates[i].name,
                        filter: ''
                    };

                    if (this.options.filter) {
                        spark_options.filter = this.options.filter;
                    }

                    var sl = new OWA.sparkline();
                    sl.generate( resultSet, item.dom_id, spark_options );
                }
            }
        }
    }
}