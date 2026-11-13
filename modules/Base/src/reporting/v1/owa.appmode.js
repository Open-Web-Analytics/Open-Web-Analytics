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
 * A Home Screen app that the phone has closed in the background opens again
 * at the manifest's start_url, which names no report, period or Property.
 * So the app remembers the last report it showed, and a launch goes back to
 * it (resume()).
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

    /** Where the last report shown in app mode is kept (localStorage). */
    LAST_VIEW_KEY: 'owa_app_last_view',

    /**
     * Set for the life of one app session (sessionStorage), so a page that
     * finds it unset is the first page since the app was opened.
     */
    OPENED_KEY: 'owa_app_opened',

    /** URL parameters that name the action, with and without the prefix. */
    ACTION_PARAMS: [ 'do', 'action', 'owa_do', 'owa_action' ],

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
     * Whether a URL is a report page of this install that can be opened again
     * as it is: same origin, a base.report action, no nonce.
     */
    isResumable: function ( href, win ) {

        var url;

        try {

            url = new URL( href, win.location.href );

        } catch ( e ) {

            return false;
        }

        if ( url.origin !== new URL( win.location.href ).origin || url.searchParams.has( 'nonce' ) ) {

            return false;
        }

        return this.ACTION_PARAMS.some( function ( name ) {

            return /^base\.report/.test( url.searchParams.get( name ) || '' );
        } );
    },

    /**
     * On the first page since the app was opened, go back to the last report
     * it showed; on every other report page, remember it.
     *
     * The first report page is the one the manifest's start_url led to,
     * perhaps by way of the sign-in form, which is not a report page and so
     * does not count. It names no period, so a first page that does name one
     * was reached some other way and is kept.
     *
     * @param {Window}   win
     * @param {Function} go  called with the URL to open instead
     * @return {boolean} whether go was called
     */
    resume: function ( win, go ) {

        var store   = this.storage( win, 'localStorage' );
        var session = this.storage( win, 'sessionStorage' );
        var here    = win.location.href;

        if ( ! store || ! session || ! this.isResumable( here, win ) ) {

            return false;
        }

        var opened = session.getItem( this.OPENED_KEY ) === null;

        session.setItem( this.OPENED_KEY, '1' );

        if ( opened ) {

            var last = store.getItem( this.LAST_VIEW_KEY );

            if ( last && last !== here && this.isResumable( last, win ) && ! this.namesPeriod( here, win ) ) {

                go( last );

                return true;
            }
        }

        store.setItem( this.LAST_VIEW_KEY, here );

        return false;
    },

    /** Whether a URL names a period, with or without the prefix. */
    namesPeriod: function ( href, win ) {

        var params = new URL( href, win.location.href ).searchParams;

        return params.has( 'period' ) || params.has( 'owa_period' );
    },

    /** A Web Storage area, or null where it is missing or refuses access. */
    storage: function ( win, name ) {

        try {

            var area = win[ name ];

            if ( ! area ) {

                return null;
            }

            area.getItem( this.OPENED_KEY );

            return area;

        } catch ( e ) {

            return null;
        }
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

    // Before the page finishes loading, so a launch spends no time drawing
    // the start page.
    if ( AppMode.isStandalone( window ) ) {

        try {

            AppMode.resume( window, function ( url ) { window.location.replace( url ); } );

        } catch ( e ) {

            // Storage that refuses a write (a full quota) leaves the page as it is.
        }
    }

    var startAppMode = function () {

        AppMode.start( window, document, function () { window.location.reload(); } );
    };

    if ( document.readyState === 'loading' ) {

        document.addEventListener( 'DOMContentLoaded', startAppMode );

    } else {

        startAppMode();
    }
}
