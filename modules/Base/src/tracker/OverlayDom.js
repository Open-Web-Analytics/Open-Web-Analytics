/**
 * DOM helpers for the overlays (the heatmap and the recording player).
 *
 * The overlays run on the tracked page, so they use the DOM alone: no library
 * is added to someone else's page or meets the page's own copy of one. Every
 * element is appended to the end of the body, after the page's own, so the
 * element paths ElementAnchor hashes are unchanged.
 *
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

/** How long a notice stays, in milliseconds, unless dismissed or replaced first. */
const NOTICE_MSEC = 1500;

/**
 * An element with attributes and children. A string child is text, never
 * markup.
 *
 * @param {string} tag
 * @param {Object} [attrs]     name => value; null or false leaves it out
 * @param {Array}  [children]  nodes or strings
 * @return {HTMLElement}
 */
function element( tag, attrs, children ) {

    var el = document.createElement( tag );

    Object.keys( attrs || {} ).forEach( function ( name ) {

        var value = attrs[ name ];

        if ( value === null || value === undefined || value === false ) {

            return;
        }

        el.setAttribute( name, value === true ? '' : String( value ) );
    } );

    ( children || [] ).forEach( function ( child ) {

        el.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
    } );

    return el;
}

/**
 * Runs fn on a click, and on Enter or Space while the element has focus: a
 * div with role="button" gets the keys a button has.
 *
 * @param {HTMLElement} el
 * @param {function}    fn  given the event
 */
function onActivate( el, fn ) {

    el.addEventListener( 'click', function ( e ) {

        e.preventDefault();
        fn( e );
    } );

    el.addEventListener( 'keydown', function ( e ) {

        if ( e.key !== 'Enter' && e.key !== ' ' ) {

            return;
        }

        e.preventDefault();
        fn( e );
    } );
}

/**
 * A control of the overlay panel: a focusable div with role="button". A div,
 * not a <button>, so the page's own button styles do not reach it.
 *
 * @param {string} id
 * @param {string} label
 * @param {Object} [attrs]
 * @return {HTMLElement}
 */
function control( id, label, attrs ) {

    return element( 'div', Object.assign( {
        'class': 'owa_overlay_control',
        id: id,
        role: 'button',
        tabindex: '0'
    }, attrs || {} ), [ label ] );
}

/**
 * GETs JSON from url and passes it to onData. Only a 2xx answer that parses
 * is passed on; anything else is dropped.
 *
 * fetch() asks for JSON and gets JSON: there is no JSONP to fall into. No
 * cookies are sent -- the credential is in the query string -- so a
 * cross-origin request stays a CORS simple request with no preflight.
 *
 * @param {string}   url
 * @param {function} onData
 * @return {Promise}
 */
function getJson( url, onData ) {

    return Promise.resolve()
        .then( function () {

            return fetch( url, { method: 'GET', credentials: 'omit' } );
        } )
        .then( function ( response ) {

            return response.ok ? response.json() : null;
        } )
        .then( function ( data ) {

            if ( data ) {

                onData( data );
            }
        } )
        .catch( function () {} );
}

/**
 * The overlay's notices: one at a time, a header and a message, each shown as
 * text. The region (role="status") is in the page from the start, so a screen
 * reader announces what is put in it; each notice has a dismiss control and
 * goes after NOTICE_MSEC on its own.
 */
class OverlayNotice {

    constructor( life ) {

        this.life   = life === undefined ? NOTICE_MSEC : life;
        this.timer  = null;
        this.region = element( 'div', {
            id: 'owa_overlay_notices',
            role: 'status',
            'aria-live': 'polite',
            'data-ui': 'overlay:notices'
        } );

        document.body.appendChild( this.region );
    }

    /**
     * Shows a notice in place of the one showing.
     *
     * @param {string} header
     * @param {string} message
     */
    show( header, message ) {

        this.dismiss();

        var close = element( 'div', {
            'class': 'owa_overlay_notice_close',
            role: 'button',
            tabindex: '0',
            'aria-label': 'Dismiss'
        }, [ '×' ] );

        var notice = element( 'div', { 'class': 'owa_overlay_notice', 'data-ui': 'overlay:notice' }, [
            element( 'span', { 'class': 'owa_overlay_notice_header' }, [ String( header ) ] ),
            ' ',
            element( 'span', { 'class': 'owa_overlay_notice_message' }, [ String( message ) ] ),
            close
        ] );

        onActivate( close, () => this.dismiss() );

        this.region.appendChild( notice );
        this.timer = setTimeout( () => this.dismiss(), this.life );
    }

    /** Removes the notice showing, if there is one. */
    dismiss() {

        if ( this.timer ) {

            clearTimeout( this.timer );
            this.timer = null;
        }

        while ( this.region.firstChild ) {

            this.region.removeChild( this.region.firstChild );
        }
    }
}

export const OverlayDom = { NOTICE_MSEC, element, onActivate, control, getJson, OverlayNotice };
