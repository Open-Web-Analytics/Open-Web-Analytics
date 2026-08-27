// OWA is defined by owa.js; this module augments it (OWA.kpiBox = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';

OWA.kpiBox = function( options ) {

    // config options
    this.options = {

        width: '',

        /*
         * How narrow a box may get before the row wraps.
         *
         * Inline rather than in the stylesheet because it is the ONE piece of a
         * box's sizing a caller varies: the boxes share their row by flexing,
         * and this is the basis they flex from. A trend card lowers it so four
         * metrics fit a half-width row; everything else keeps the 135 that a
         * full-width row was tuned for.
         */
        minWidth: '135px',

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
        /*
         * The CAROUSEL, which is what wraps the boxes now -- see makeCarousel.
         * This selector is what generate() removes before rebuilding, so it has
         * to name the outermost thing generate() creates. Naming the inner
         * container instead would leave the wrapper behind and stack a fresh
         * set of boxes under the old ones on every result set, which is the
         * bug the '#' below was added to fix.
         */
        this.domSelector = '#' + this.dom_id + ' > .owa_metricCarousel';
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

    /**
     * The result set's aggregates, in the order the QUERY asked for them.
     *
     * ORDER IS MEANING. A widget's first metric is the one its chart draws, and
     * the boxes are how a reader picks a different one -- so a row that reads in
     * a different order than the definition says makes "the first metric is
     * charted" look arbitrary. On the dashboard's Site Metrics card the query
     * asked for uniqueVisitors, pageViews, bounceRate, pagesPerVisit,
     * visitDuration and the boxes drew uniqueVisitors, pageViews, visitDuration,
     * bounceRate, pagesPerVisit.
     *
     * `resultSet.aggregates` is keyed by the server and arrives in whatever
     * order the reduction produced, which is not the request's. The request IS
     * recoverable, though: resultSet.self is the URL that produced this data and
     * carries `metrics` verbatim. So the order is read from the answer's own
     * question rather than passed down separately, and cannot drift from it.
     *
     * Anything the result set carries that the query did not name goes last
     * rather than being dropped -- this orders boxes, it does not decide which
     * ones exist.
     *
     * @return array the aggregate objects, ordered
     */
    orderedAggregates : function ( resultSet ) {

        var items = [];

        for ( var i in resultSet.aggregates ) {

            if ( resultSet.aggregates.hasOwnProperty( i ) ) {

                items.push( resultSet.aggregates[ i ] );
            }
        }

        var wanted = this.queryMetrics( resultSet );

        if ( ! wanted.length ) {

            return items;
        }

        var remaining = {};

        items.forEach( function ( item ) { remaining[ item.name ] = item; } );

        var out = [];

        wanted.forEach( function ( name ) {

            if ( remaining[ name ] ) {

                out.push( remaining[ name ] );

                // Deleted as it is taken, so a metric named twice in the query
                // does not draw two boxes, and the sweep below finds only what
                // the query never mentioned.
                delete remaining[ name ];
            }
        } );

        items.forEach( function ( item ) {

            if ( remaining[ item.name ] ) {

                out.push( item );
            }
        } );

        return out;
    },

    /**
     * The metric names this result set was asked for, in order.
     *
     * Empty when there is no URL to read -- a result set handed in directly by
     * loadFromArray() has none -- in which case the caller keeps the server's
     * order, which is the only order there is.
     */
    queryMetrics : function ( resultSet ) {

        if ( ! resultSet || ! resultSet.self ) {

            return [];
        }

        var raw = OWA.util.urldecode(
            new OWA.uri( resultSet.self ).getQueryParam( OWA.util.appNs( 'metrics' ) ) || '' );

        return raw.split( ',' )
            .map( function ( name ) { return name.trim(); } )
            .filter( Boolean );
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
        /*
         * A ROW THAT SCROLLS, not a row that wraps.
         *
         * A widget shows as many metrics as its author asked for, and the
         * widget is as wide as the layout gives it -- so the two disagree
         * regularly, and wrapping resolved it by growing the widget downward.
         * That moves the chart, changes the panel's height, and puts the fifth
         * metric on a line of its own where it reads as a different kind of
         * thing from the four above it.
         *
         * The boxes stay on one line and the ones that do not fit are scrolled
         * to. The arrows appear only when there is something to scroll to --
         * see makeCarousel.
         */
        jQuery('#' + dom_id).append( OWA.util.sprintf(
              '<div class="owa_metricCarousel">'
            + '<button type="button" class="owa_metricCarouselArrow owa_metricCarouselPrev" '
            + 'aria-label="Show earlier metrics">&lsaquo;</button>'
            + '<div id="%s" class="metricInfoboxesContainer" style="width:auto;"></div>'
            + '<button type="button" class="owa_metricCarouselArrow owa_metricCarouselNext" '
            + 'aria-label="Show later metrics">&rsaquo;</button>'
            + '</div><div style="clear:both;"></div>', con_id ) );
        //jQuery('#' + dom_id).append('<div style="clear:both;"></div>');

        var ordered = this.orderedAggregates( resultSet );

        for (var i = 0; i < ordered.length; i++) {

            var item = ordered[i];

            item.dom_id = dom_id + '-' + item.name + '-' + resultSet.guid;

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
                '<div id ="%s" class="owa_metricInfobox" data-metric="%s" style="min-width:%s;width:%s">',
                item.dom_id, item.name, this.options.minWidth, width );
            html += OWA.util.sprintf('<p class="owa_metricInfoboxLabel">%s</p>', item.label);
            html += OWA.util.sprintf('<p class="owa_metricInfoboxLargeNumber">%s</p>', item.formatted_value);
            html += '</div>';

            jQuery('#' + con_id ).append( html );

            if ( this.options.showSparklines ) {

                var spark_options = {
                    metric: item.name,
                    filter: ''
                };

                if (this.options.filter) {
                    spark_options.filter = this.options.filter;
                }

                var sl = new OWA.sparkline();
                sl.generate( resultSet, item.dom_id, spark_options );
            }
        }

        this.makeCarousel( dom_id, con_id );
    },

    /**
     * The row of boxes scrolls rather than wrapping, with arrows when it must.
     *
     * A widget shows as many metrics as its author asked for, and it is as wide
     * as the layout gives it -- so the two disagree regularly. Wrapping resolved
     * that by growing the widget downward, which moves the chart under it,
     * changes the panel's height, and leaves the fifth metric alone on a line
     * where it reads as a different kind of thing from the four above.
     *
     * The arrows are shown only when there is somewhere to go, and each is
     * disabled at its own end -- a control that cannot do anything should not
     * look like it can.
     *
     * Re-evaluated on WIDTH CHANGE, because that is the whole point: the same
     * five boxes need arrows in a quarter-row widget and none in a full-width
     * one, and a widget changes width without the window doing anything (see
     * OWA.onWidthChange).
     */
    makeCarousel : function ( dom_id, con_id ) {

        var track = document.getElementById( con_id );

        if ( ! track || ! track.parentNode ) {

            return;
        }

        var carousel = track.parentNode;
        var prev = carousel.querySelector( '.owa_metricCarouselPrev' );
        var next = carousel.querySelector( '.owa_metricCarouselNext' );

        if ( ! prev || ! next ) {

            return;
        }

        var update = function () {

            // A pixel of slack: scrollWidth and clientWidth disagree by
            // sub-pixel amounts on a flex row that fits exactly.
            var overflowing = track.scrollWidth - track.clientWidth > 1;

            jQuery( carousel ).toggleClass( 'owa_metricCarouselScrolls', overflowing );

            prev.disabled = track.scrollLeft <= 1;
            next.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 1;
        };

        var step = function ( direction ) {

            var box = track.querySelector( '.owa_metricInfobox' );

            // One box at a time, so a click always lands on a boundary rather
            // than halfway through a number.
            var by = box ? box.getBoundingClientRect().width + 8 : 120;

            track.scrollLeft += direction * by;

            // scroll-behavior is smooth, so the ends are not known yet.
            setTimeout( update, 400 );
        };

        jQuery( prev ).on( 'click', function () { step( -1 ); } );
        jQuery( next ).on( 'click', function () { step( 1 ); } );
        jQuery( track ).on( 'scroll', update );

        if ( this.unwatchWidth ) {

            this.unwatchWidth();
        }

        this.unwatchWidth = OWA.onWidthChange( dom_id, update );

        update();
    }
}