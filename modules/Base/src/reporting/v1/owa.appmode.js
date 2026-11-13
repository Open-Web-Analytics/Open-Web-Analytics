/**
 * OWA opened from a phone's Home Screen: pull down to refresh, and a refresh
 * on coming back after a while.
 *
 * Opened as an app (head.php's manifest), the page has no browser interface,
 * so the browser's own pull-to-refresh is not there. This puts one back, and
 * reloads a page that has been in the background long enough for its numbers
 * to be stale. Both reload the page: its period, Property and filters are in
 * the URL, so it comes back to the same view.
 *
 * Only in app mode. In a browser tab nothing here is bound, and the
 * browser's own refresh applies.
 */
import { OWA } from './owa.js';

export var AppMode = {

    /** How far, in pixels, a pull has to travel to refresh. */
    PULL_THRESHOLD: 70,

    /** The furthest the spinner follows the finger. */
    MAX_PULL: 110,

    /** A pull travels half as far as the finger, as the browser's does. */
    RESISTANCE: 0.5,

    /** Away this long, and the page reloads when it is shown again. */
    STALE_AFTER_MS: 5 * 60 * 1000,

    /** Whether the page is running as a Home Screen app. */
    isStandalone: function ( win ) {

        if ( win.navigator && win.navigator.standalone === true ) {

            return true;
        }

        return typeof win.matchMedia === 'function'
            && win.matchMedia( '(display-mode: standalone)' ).matches;
    },

    /**
     * How far the page is scrolled: the content column where it scrolls on
     * its own (the desktop layout), else the window.
     */
    scrollTop: function ( win, doc ) {

        var column = doc.querySelector( '.owa_contentScroll' );

        if ( column && column.scrollHeight > column.clientHeight ) {

            return column.scrollTop;
        }

        return win.scrollY || doc.documentElement.scrollTop || 0;
    },

    /**
     * Bind the gesture and the stale-page reload, in app mode only.
     *
     * @param {Window}   win
     * @param {Document} doc
     * @param {Function} reload  called to refresh
     * @param {Function} [now]   the clock, for tests
     * @return {boolean} whether anything was bound
     */
    start: function ( win, doc, reload, now ) {

        if ( ! this.isStandalone( win ) || ! doc.body ) {

            return false;
        }

        now = now || Date.now;

        var that      = this;
        var indicator = this.indicator( doc );
        var startY    = null;
        var pull      = 0;
        var hiddenAt  = null;

        doc.documentElement.classList.add( 'owa_appMode' );

        var show = function ( distance ) {

            pull = distance;
            indicator.style.transform = 'translateY(' + ( distance - 48 ) + 'px) rotate(' + ( distance * 3 ) + 'deg)';
            indicator.style.opacity = String( Math.min( 1, distance / that.PULL_THRESHOLD ) );
            indicator.classList.toggle( 'is-ready', distance >= that.PULL_THRESHOLD );
        };

        doc.addEventListener( 'touchstart', function ( e ) {

            startY = e.touches.length === 1 && that.scrollTop( win, doc ) <= 0
                ? e.touches[0].clientY : null;
        }, { passive: true } );

        doc.addEventListener( 'touchmove', function ( e ) {

            if ( startY === null ) {

                return;
            }

            var dy = e.touches[0].clientY - startY;

            if ( dy <= 0 ) {

                show( 0 );

                return;
            }

            show( Math.min( that.MAX_PULL, dy * that.RESISTANCE ) );

            // The pull is ours: the page does not also scroll or bounce.
            if ( e.cancelable ) {

                e.preventDefault();
            }
        }, { passive: false } );

        doc.addEventListener( 'touchend', function () {

            var ready = startY !== null && pull >= that.PULL_THRESHOLD;

            startY = null;

            if ( ready ) {

                indicator.classList.add( 'is-refreshing' );
                reload();

                return;
            }

            show( 0 );
        }, { passive: true } );

        doc.addEventListener( 'visibilitychange', function () {

            if ( doc.visibilityState === 'hidden' ) {

                hiddenAt = now();

                return;
            }

            if ( hiddenAt !== null && now() - hiddenAt >= that.STALE_AFTER_MS ) {

                reload();
            }

            hiddenAt = null;
        } );

        return true;
    },

    /** The spinner that follows the pull, hidden above the top edge until then. */
    indicator: function ( doc ) {

        var el = doc.createElement( 'div' );

        el.className = 'owa_pullRefresh';
        el.setAttribute( 'aria-hidden', 'true' );
        el.innerHTML = '<i class="fas fa-sync-alt"></i>';
        doc.body.appendChild( el );

        return el;
    }
};

OWA.appMode = AppMode;

if ( typeof window !== 'undefined' && typeof document !== 'undefined' ) {

    var startAppMode = function () {

        AppMode.start( window, document, function () { window.location.reload(); } );
    };

    if ( document.readyState === 'loading' ) {

        document.addEventListener( 'DOMContentLoaded', startAppMode );

    } else {

        startAppMode();
    }
}
