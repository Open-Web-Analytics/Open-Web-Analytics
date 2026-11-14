// OWA is defined by owa.js; this module augments it (OWA.kpiBox = ...). jQuery was
// supplied by webpack.ProvidePlugin before the ESM renovation -- now imported explicitly.
import * as jQuery from 'jquery';
import { OWA } from './owa.js';
import { describeChange } from './owa.periodpicker.js';
import { describeMetric, metricDescription } from './owa.metricinfo.js';

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

/**
 * The line under a box's number when the report is compared to another
 * period: the change, up or down. The dates it is against and the
 * comparison's own value are in the title; the picker and the chart's key
 * already name the dates.
 *
 * A metric with no comparison value (nothing in the compared period) or
 * nothing to divide by (a zero) shows no percentage.
 */
OWA.kpiBox.compareHtml = function ( item, compare ) {

    var d = describeChange( item, compare );

    return '<p class="owa_metricInfoboxCompare" title="' + OWA.util.escapeHtml( d.title ) + '">'
        + '<span class="' + d.cls + '">' + OWA.util.escapeHtml( d.text ) + '</span></p>';
};

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
            new OWA.uri( resultSet.self ).getQueryParam( 'metrics' ) || '' );

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
            + 'aria-label="%2$s" data-ui="kpi-prev"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>'
            + '<div id="%1$s" class="metricInfoboxesContainer" data-ui="kpi-row"></div>'
            + '<button type="button" class="owa_metricCarouselArrow owa_metricCarouselNext" '
            + 'aria-label="%3$s" data-ui="kpi-next"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>'
            + '</div><div style="clear:both;"></div>', con_id,
            OWA.util.escapeHtml( OWA.l( 'Show earlier metrics' ) ), OWA.util.escapeHtml( OWA.l( 'Show later metrics' ) ) ) );
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
             *
             * The sizes are custom properties rather than min-width and
             * width, so the stylesheet decides where they apply: a phone
             * sizes the boxes by the row instead (owa.report.css).
             */
            var html = OWA.util.sprintf(
                '<div id ="%s" class="owa_metricInfobox%s" data-metric="%s" data-ui="kpi:%s" style="--owa-kpi-min-width:%s;--owa-kpi-width:%s">',
                item.dom_id, width === 'auto' ? '' : ' owa_metricInfoboxFixed', item.name,
                OWA.util.escapeHtml( item.name ), this.options.minWidth, width );
            var description = metricDescription( resultSet, item.name );

            // A name with a description is the button that opens it
            // (owa.metricinfo.js); the rest of the box charts the metric.
            html += OWA.util.sprintf( description
                ? '<p class="owa_metricInfoboxLabel"><button type="button">%s</button></p>'
                : '<p class="owa_metricInfoboxLabel">%s</p>', item.label );
            html += OWA.util.sprintf('<p class="owa_metricInfoboxLargeNumber">%s</p>',
                OWA.util.escapeHtml( OWA.util.metricText( item ) ) );

            if ( resultSet.compare && item.hasOwnProperty( 'compare_value' ) ) {

                html += OWA.kpiBox.compareHtml( item, resultSet.compare );
            }

            html += '</div>';

            jQuery('#' + con_id ).append( html );

            if ( description ) {

                describeMetric( jQuery( '#' + item.dom_id + ' > .owa_metricInfoboxLabel > button' )[0], description );
            }

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
     * The row is a native scroller: a finger swipes it, a trackpad scrolls it,
     * and it snaps to the start of a box (owa.report.css). The arrows are for a
     * mouse; a touch screen hides them and shows part of the next box instead.
     *
     * Which ends hide a box is read from the scroller itself, on every scroll
     * and whenever the row or a box changes size -- an arrow, a swipe, a
     * keyboard or a widget changing width all move it.
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

            var ends = OWA.kpiBox.hiddenEnds( track.scrollLeft, track.scrollWidth, track.clientWidth );

            jQuery( carousel )
                .toggleClass( 'owa_canScrollBack', ends.back )
                .toggleClass( 'owa_canScrollForward', ends.forward );

            /*
             * Hidden from the keyboard too, not just from view. An arrow that
             * is display:none is already unreachable by tab, but a button that
             * cannot act should say so however it is reached.
             */
            prev.disabled = ! ends.back;
            next.disabled = ! ends.forward;
        };

        var step = function ( direction ) {

            var starts = Array.prototype.map.call(
                track.querySelectorAll( '.owa_metricInfobox' ),
                function ( box ) { return box.offsetLeft; } );

            // The room left for the back arrow, and the scroll behaviour
            // (smooth, unless the reader asked for less motion), are the
            // stylesheet's.
            var pad = parseFloat( window.getComputedStyle( track ).scrollPaddingLeft ) || 0;

            track.scrollLeft = OWA.kpiBox.nextStop(
                starts, track.scrollLeft, direction, track.scrollWidth - track.clientWidth, pad );
        };

        jQuery( prev ).on( 'click', function () { step( -1 ); } );
        jQuery( next ).on( 'click', function () { step( 1 ); } );
        track.addEventListener( 'scroll', update, { passive: true } );

        /*
         * A swipe is not a tap. A finger that scrolled the row and lifted over
         * a box must not choose that box, so a click after the row moved more
         * than a few pixels since the press stops here, before the box's own
         * handler sees it.
         */
        var pressedAt = null;

        track.addEventListener( 'pointerdown', function () {

            pressedAt = track.scrollLeft;
        }, true );

        track.addEventListener( 'click', function ( e ) {

            if ( pressedAt !== null && Math.abs( track.scrollLeft - pressedAt ) > OWA.kpiBox.SWIPE_SLOP ) {

                e.stopPropagation();
                e.preventDefault();
            }

            pressedAt = null;
        }, true );

        if ( this.unwatchWidth ) {

            this.unwatchWidth();
        }

        if ( typeof ResizeObserver !== 'undefined' ) {

            var ro = new ResizeObserver( function () { update(); } );

            ro.observe( track );

            Array.prototype.forEach.call( track.querySelectorAll( '.owa_metricInfobox' ), function ( box ) {

                ro.observe( box );
            } );

            this.unwatchWidth = function () { ro.disconnect(); };

        } else {

            this.unwatchWidth = OWA.onWidthChange( dom_id, update );
        }

        update();
    }
};

/** Pixels the row may move under a press and it still counts as a tap. */
OWA.kpiBox.SWIPE_SLOP = 8;

/**
 * Whether boxes are hidden past each end of the row.
 *
 * The two ends are independent questions: at the start nothing is to the left,
 * so there is no back arrow -- not a greyed one. A pixel of slack on each, as
 * scrollWidth, clientWidth and scrollLeft disagree by sub-pixel amounts on a
 * flex row. The magnitude of scrollLeft, which is negative in a right-to-left
 * page.
 *
 * @return object {back: bool, forward: bool}
 */
OWA.kpiBox.hiddenEnds = function ( scrollLeft, scrollWidth, clientWidth ) {

    var at   = Math.abs( scrollLeft );
    var over = scrollWidth - clientWidth;

    return {
        back: over > 1 && at > 1,
        forward: over > 1 && at < over - 1
    };
};

/**
 * Where an arrow scrolls the row to: the next box past the left edge going
 * forward, or the last one before it going back, so a click always lands on a
 * boundary rather than halfway through a number. A box lands `pad` in from
 * the edge, where the snap puts it, clear of the back arrow. Never past
 * either end.
 *
 * @param array  starts     each box's left edge in the row, in order
 * @param number at         the row's scroll position
 * @param int    direction  1 forward, -1 back
 * @param number max        the furthest the row scrolls
 * @param number pad        the row's scroll-padding-left
 * @return number
 */
OWA.kpiBox.nextStop = function ( starts, at, direction, max, pad ) {

    var first = starts.length ? starts[ 0 ] : 0;
    var stop  = direction > 0 ? max : 0;

    pad = pad || 0;

    for ( var i = 0; i < starts.length; i++ ) {

        var x = starts[ i ] - first - pad;

        if ( direction > 0 && x > at + 1 ) {
            stop = x;
            break;
        }

        if ( direction < 0 && x < at - 1 ) {
            stop = x;
        }
    }

    return Math.max( 0, Math.min( max, stop ) );
};
