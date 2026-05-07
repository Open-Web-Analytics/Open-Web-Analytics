OWA.kpiBox = function( options ) {

    // config options
    this.options = {

        template: '#metricInfobox',
        width: ''
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

        for (option in options) {

            if ( options.hasOwnProperty( option ) ) {
                this.options[ option ] = options[ option ];
            }
        }
    },

    setDomId: function( dom_id ) {

        this.dom_id = dom_id;
        this.domSelector = this.dom_id + ' > .metricInfoboxesContainer';
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
                var selector = '#' + this.domSelector;

                var width = item.width || 'auto';
                var html = OWA.util.sprintf('<div id ="%s" class="owa_metricInfobox" style="min-width:135px;width:%s">', item.dom_id, width);
                html += OWA.util.sprintf('<p class="owa_metricInfoboxLabel">%s</p>', item.label);
                html += OWA.util.sprintf('<p class="owa_metricInfoboxLargeNumber">%s</p>', item.formatted_value);
                html += '</div>';

                jQuery('#' + con_id ).append( html );

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