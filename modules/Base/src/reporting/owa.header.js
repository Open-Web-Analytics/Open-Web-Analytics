import { OWA } from './owa.format.js';

/**
 * The header's controls (header.php): the notification bell, and the account
 * and help menus.
 *
 * THE BELL. One fetch returns the notifications, and the badge is the number
 * of unread rows drawn from what came back -- so the number and the list
 * cannot disagree, which is the failure mode of a separately-computed count.
 * Its element names its three API URLs, one per HTTP method (a nonce is
 * minted for one method), as built by makeApiLink():
 *
 *   <span data-ui="header:notifications" data-owa-notifications-read="..."
 *         data-owa-notifications-mark-read="..." data-owa-notifications-dismiss="...">
 *
 * THE MENUS behave identically -- open on the control, close on a click
 * outside, close on Escape, report state through aria-expanded -- and are
 * wired by one routine. Opening either closes the other.
 *
 * Plain DOM, no jQuery: every screen with the header loads the bundle, and
 * this is all it needs of it.
 */

/** The bell's element. */
export const BELL_SELECTOR = '[data-ui="header:notifications"]';

// An icon per TYPE. Unknown types get a neutral bell rather than nothing.
const ICONS = { release: 'fa-box', general: 'fa-bell' };

// A notice's SEVERITY, when it has one above info, wins over its type's icon
// and colours the icon's circle (is-<severity>).
const SEVERITY_ICONS = { warning: 'fa-exclamation-triangle', security: 'fa-shield-alt', critical: 'fa-exclamation-circle' };

/**
 * "3d ago" rather than a date, the way a notification list reads, in the
 * locale's own words where the browser has Intl.RelativeTimeFormat. A real
 * date past a week, where "37d" stops being useful.
 *
 * @param  {number} seconds  when, as a Unix time
 * @param  {number} [now]    the time it is, in milliseconds
 * @return {string}
 */
export function relativeTime( seconds, now ) {

    var diff = Math.floor( ( now === undefined ? Date.now() : now ) / 1000 ) - seconds;
    var unit = diff < 3600 ? [ 'minute', 60, OWA.l( '%sm', 'minutes ago, abbreviated' ) ]
        : diff < 86400 ? [ 'hour', 3600, OWA.l( '%sh', 'hours ago, abbreviated' ) ]
            : [ 'day', 86400, OWA.l( '%sd', 'days ago, abbreviated' ) ];

    if ( diff < 60 ) {
        return OWA.l( 'just now' );
    }

    if ( diff < 604800 ) {

        var n = Math.floor( diff / unit[1] );

        try {
            return new Intl.RelativeTimeFormat( OWA.locale(), { style: 'narrow', numeric: 'always' } ).format( -n, unit[0] );
        } catch ( e ) {
            return unit[2].replace( '%s', n );
        }
    }

    try {
        return OWA.util.dateFormat( { month: 'short', day: 'numeric' } ).format( new Date( seconds * 1000 ) );
    } catch ( e ) {
        return new Date( seconds * 1000 ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
    }
}

/**
 * Wire the bell.
 *
 * @param {HTMLElement} bell  the element BELL_SELECTOR finds
 */
export function startNotifications( bell ) {

    var toggle = document.getElementById( 'owa_notificationToggle' );
    var panel  = document.getElementById( 'owa_notificationPanel' );
    var badge  = document.getElementById( 'owa_notificationBadge' );
    var items  = document.getElementById( 'owa_notificationItems' );

    if ( ! toggle || ! panel || ! badge || ! items ) {
        return;
    }

    var apiUrl      = bell.getAttribute( 'data-owa-notifications-read' );
    var markReadUrl = bell.getAttribute( 'data-owa-notifications-mark-read' );
    var dismissUrl  = bell.getAttribute( 'data-owa-notifications-dismiss' );

    // The badge counts the UNREAD rows held -- a read notification stays on
    // screen without counting. Nothing unread, no badge; it overhangs the
    // button, so showing or hiding it moves nothing.
    function paintBadge() {

        var unread = items.querySelectorAll( '.owa_notification.is-unread' ).length;

        badge.textContent = unread;
        badge.hidden = unread === 0;
    }

    function markRead( id, el ) {

        if ( ! el.classList.contains( 'is-unread' ) ) {
            return;
        }

        // Unbold immediately; the row stays either way.
        el.classList.remove( 'is-unread' );
        paintBadge();

        fetch( markReadUrl + '&notificationId=' + encodeURIComponent( id ),
            { method: 'POST', credentials: 'same-origin' } )
            .catch( function () { /* the next load re-reads the truth */ } );
    }

    function dismiss( id, el ) {

        fetch( dismissUrl + '&notificationId=' + encodeURIComponent( id ),
            { method: 'DELETE', credentials: 'same-origin' } )
            .then( function ( r ) {

                if ( ! r.ok ) {
                    return;
                }

                // Removed locally: the server has just been told.
                el.remove();
                paintBadge();

                if ( ! items.querySelector( '.owa_notification' ) ) {
                    renderEmpty();
                }
            } )
            .catch( function () { /* left on screen; the next load re-reads */ } );
    }

    function renderEmpty() {

        items.innerHTML = '';
        paintBadge();

        var p = document.createElement( 'p' );

        p.className = 'info_text';
        p.textContent = OWA.l( 'Nothing new.' );
        items.appendChild( p );
    }

    function render( list ) {

        items.innerHTML = '';

        if ( ! list.length ) {
            renderEmpty();
            return;
        }

        list.forEach( function ( n ) {

            var row = document.createElement( 'div' );

            // is-unread is what the styling keys on, and what the badge counts.
            row.className = 'owa_notification' + ( n.read ? '' : ' is-unread' );

            var icon  = document.createElement( 'span' );
            var glyph = document.createElement( 'i' );

            var severe = SEVERITY_ICONS[ n.severity ];

            icon.className = 'owa_notificationIcon' + ( severe ? ' is-' + n.severity : '' );
            glyph.className = 'fas ' + ( severe || ICONS[ n.type ] || ICONS.general );
            glyph.setAttribute( 'aria-hidden', 'true' );
            icon.appendChild( glyph );
            row.appendChild( icon );

            var main  = document.createElement( 'div' );
            var title = document.createElement( 'div' );

            main.className = 'owa_notificationMain';
            title.className = 'owa_notificationTitle';

            // textContent throughout: these are other people's words, not markup.
            if ( n.url ) {

                var a = document.createElement( 'a' );

                a.href = n.url;
                // A new tab: marking read is a request of our own, and letting
                // the same click navigate away races it.
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = n.title;
                title.appendChild( a );

            } else {

                title.textContent = n.title;
            }

            main.appendChild( title );

            if ( n.excerpt ) {

                var excerpt = document.createElement( 'div' );

                excerpt.className = 'owa_notificationExcerpt';
                excerpt.textContent = n.excerpt;
                main.appendChild( excerpt );
            }

            if ( n.published_at ) {

                var when = document.createElement( 'div' );

                when.className = 'owa_notificationWhen';
                when.textContent = relativeTime( n.published_at );
                main.appendChild( when );
            }

            row.appendChild( main );

            // An x in the corner, named for what it does.
            var x = document.createElement( 'button' );

            x.type = 'button';
            x.className = 'owa_notificationDismiss';
            x.setAttribute( 'aria-label', OWA.l( 'Dismiss notification' ) );
            x.textContent = '×';
            x.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                e.stopPropagation();
                dismiss( n.id, row );
            } );
            row.appendChild( x );

            // Anywhere on the row marks it read, not only the headline.
            row.addEventListener( 'click', function () { markRead( n.id, row ); } );

            items.appendChild( row );
        } );
    }

    fetch( apiUrl, { credentials: 'same-origin' } )
        .then( function ( r ) { return r.ok ? r.json() : null; } )
        .then( function ( data ) {

            // The REST view's envelope -- {requestId, httpResponse, error, data} --
            // holds the payload one level down.
            render( ( data && data.data && data.data.notifications ) || [] );
            paintBadge();
        } )
        .catch( function () { renderEmpty(); } );

    toggle.addEventListener( 'click', function () {

        var open = ! panel.hidden;

        panel.hidden = open;
        toggle.setAttribute( 'aria-expanded', String( ! open ) );
    } );

    document.addEventListener( 'click', function ( e ) {

        if ( ! panel.hidden && ! e.target.closest( '.owa_notificationBell' ) ) {
            panel.hidden = true;
            toggle.setAttribute( 'aria-expanded', 'false' );
        }
    } );
}

/**
 * Wire the account and help menus: each a toggle, a panel and the wrapper a
 * click outside of closes it.
 */
export function startMenus() {

    var menus = [];

    function wire( toggleId, panelId, wrapperClass ) {

        var toggle = document.getElementById( toggleId );
        var panel  = document.getElementById( panelId );

        if ( ! toggle || ! panel ) {
            return;
        }

        var menu = {
            close: function () {
                panel.hidden = true;
                toggle.setAttribute( 'aria-expanded', 'false' );
            }
        };

        toggle.addEventListener( 'click', function () {

            var open = ! panel.hidden;

            // One at a time, so two panels cannot overlap in the corner of the bar.
            menus.forEach( function ( other ) {
                if ( other !== menu ) {
                    other.close();
                }
            } );

            panel.hidden = open;
            toggle.setAttribute( 'aria-expanded', String( ! open ) );
        } );

        document.addEventListener( 'click', function ( e ) {
            if ( ! panel.hidden && ! e.target.closest( '.' + wrapperClass ) ) {
                menu.close();
            }
        } );

        // Escape closes it and puts the focus back on the control that opened it.
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && ! panel.hidden ) {
                menu.close();
                toggle.focus();
            }
        } );

        menus.push( menu );
    }

    wire( 'owa_userMenuToggle', 'owa_userMenuPanel', 'owa_userMenu' );
    wire( 'owa_helpMenuToggle', 'owa_helpMenuPanel', 'owa_helpMenu' );
}

/** The header is wired once the page's markup is there. */
function startHeader() {

    var bell = document.querySelector( BELL_SELECTOR );

    if ( bell ) {
        startNotifications( bell );
    }

    startMenus();
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', startHeader );
    } else {
        startHeader();
    }
}

export { OWA };
