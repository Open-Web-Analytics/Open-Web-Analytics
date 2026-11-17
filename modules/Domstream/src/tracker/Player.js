/**
 * Domstream playback, in an overlay session on the recorded page.
 *
 * Replays a recording's samples (see Recorder.js) at the pace they were
 * recorded: each tuple's first element is the milliseconds since the one
 * before it. Pointer moves are relative, so positions are accumulated.
 *
 * A key press is shown as WHICH FIELD received it: the recording holds no
 * key, and the player never invents one.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

import { OWA_instance } from '../../../Base/src/common/owa.js';
import { OverlayDom } from '../../../Base/src/tracker/OverlayDom.js';

/** Longest pause replayed as-is; anything longer is shortened to this. */
const MAX_PAUSE_MSEC = 2000;

class Player {

    constructor() {

        this.timer   = null;
        this.step    = 0;
        this.samples = [];
        this.x       = 0;
        this.y       = 0;
        this.playing = false;
        this.notice  = null;
    }

    init() {

        this.fetchData();
        this.showPlayerControls();
    }

    /**
     * @param {object} data the REST response: data.samples, in order
     */
    load( data ) {

        var recording = ( data && data.data ) || {};

        this.samples = Array.isArray( recording.samples ) ? recording.samples : [];
        this.step    = 0;
        this.x       = 0;
        this.y       = 0;

        this.setStatus( this.samples.length ? 'Ready.' : 'This recording has no samples.' );
    }

    /** Fetches the recording from the API URL the overlay session carries. */
    fetchData() {

        var params = OWA_instance.getOverlayParams() || {};

        if ( ! OWA_instance.isOwaServerUrl( params.api_url ) ) {

            OWA_instance.debug( 'Recording not fetched: the URL is not this OWA server.' );

            return;
        }

        // A plain cross-origin GET for JSON: the credentials ride the query
        // string, so this stays a CORS simple request.
        OverlayDom.getJson( params.api_url, ( data ) => this.load( data ) );
    }

    play() {

        if ( this.playing ) {

            return;
        }

        if ( this.step >= this.samples.length ) {

            this.step = 0;
            this.x    = 0;
            this.y    = 0;
        }

        this.playing = true;
        this.setStatus( 'Playing...' );
        this.next();
    }

    stop() {

        this.playing = false;

        if ( this.timer ) {

            clearTimeout( this.timer );
            this.timer = null;
        }

        var start = document.getElementById( 'owa_player_start' );

        if ( start ) {

            start.classList.remove( 'active' );
        }

        this.setStatus( 'Ready.' );
    }

    next() {

        if ( ! this.playing ) {

            return;
        }

        if ( this.step >= this.samples.length ) {

            this.playing = false;
            this.setStatus( 'Finished.' );

            return;
        }

        var sample = this.samples[ this.step ];
        var wait   = Math.min( MAX_PAUSE_MSEC, Math.max( 0, Number( sample[ 0 ] ) || 0 ) );

        this.timer = setTimeout( () => {

            this.playSample( sample );
            this.step++;
            this.next();

        }, wait );
    }

    playSample( sample ) {

        switch ( sample[ 1 ] ) {

            case 'm':
                this.x += Number( sample[ 2 ] ) || 0;
                this.y += Number( sample[ 3 ] ) || 0;
                return this.moveCursor( this.x, this.y );

            case 's':
                return this.scrollViewport( Number( sample[ 2 ] ) || 0 );

            case 'c':
                return this.click( Number( sample[ 2 ] ) || 0, Number( sample[ 3 ] ) || 0,
                    sample[ 4 ], sample[ 5 ], sample[ 6 ] );

            case 'k':
                return this.keyPressed( sample[ 2 ], sample[ 3 ], sample[ 4 ] );
        }
    }

    moveCursor( x, y ) {

        var cursor = document.getElementById( 'owa-cursor' );

        if ( cursor ) {

            cursor.style.top  = y + 'px';
            cursor.style.left = x + 'px';
        }

        this.setStatus( 'Pointer at ' + x + ', ' + y );
    }

    scrollViewport( y ) {

        window.scroll( 0, y );
        this.setStatus( 'Scrolled to ' + y );
    }

    /** A CSS selector for an element the recording names, or '' if it names none. */
    static selector( tag, id, name ) {

        if ( id ) {

            return '#' + CSS.escape( id );
        }

        if ( name ) {

            return ( tag ? CSS.escape( tag ) : '' ) + '[name="' + CSS.escape( name ) + '"]';
        }

        return '';
    }

    click( x, y, tag, id, name ) {

        var marker = OverlayDom.element( 'div', { 'class': 'owa-click-marker', 'data-ui': 'overlay:click-marker' } );

        marker.style.position = 'absolute';
        marker.style.left     = x + 'px';
        marker.style.top      = y + 'px';
        marker.style.zIndex   = '89';
        document.body.appendChild( marker );

        var label = Player.selector( tag, id, name ) || tag || 'the page';

        this.setStatus( 'Click at ' + x + ', ' + y );
        this.showNotification( label, 'Clicked:' );
    }

    keyPressed( tag, id, name ) {

        var selector = Player.selector( tag, id, name );
        var node     = selector ? document.querySelector( selector ) : null;

        if ( node ) {

            node.classList.add( 'owa-key-pressed' );
            setTimeout( () => node.classList.remove( 'owa-key-pressed' ), 300 );
        }

        this.setStatus( 'Key pressed in ' + ( selector || tag || 'the page' ) );
    }

    /**
     * The panel: the logo, Play, Pause and Hide, and a status line. Hide (or
     * the logo) folds the panel into a tab at the same corner; the tab opens
     * it again.
     */
    showPlayerControls() {

        var panel = OverlayDom.element( 'div', { id: 'owa_overlay' }, [
            OverlayDom.element( 'div', { id: 'owa_overlay_logo' } ),
            OverlayDom.control( 'owa_player_start', 'Play' ),
            OverlayDom.control( 'owa_player_stop', 'Pause' ),
            OverlayDom.control( 'owa_player_close', 'Hide' ),
            OverlayDom.element( 'div', { id: 'owa-overlay-status', 'data-ui': 'overlay:status' }, [ '...' ] )
        ] );

        var tab = OverlayDom.element( 'div', {
            id: 'owa_overlay_hidden',
            role: 'button',
            tabindex: '0',
            'aria-label': 'Show the player',
            hidden: true
        } );

        var cursor = OverlayDom.element( 'div', { id: 'owa-cursor', 'data-ui': 'overlay:cursor' }, [
            OverlayDom.element( 'img', { src: OWA_instance.getAssetsUrl() + 'base/i/cursor2.png', alt: '' } )
        ] );

        document.body.appendChild( panel );
        document.body.appendChild( tab );
        document.body.appendChild( cursor );

        this.notice = new OverlayDom.OverlayNotice();

        var controls = panel.querySelectorAll( '.owa_overlay_control' );

        Array.prototype.forEach.call( controls, function ( el ) {

            OverlayDom.onActivate( el, function () {

                Array.prototype.forEach.call( controls, function ( c ) { c.classList.remove( 'active' ); } );
                el.classList.add( 'active' );
            } );
        } );

        // Focus follows the fold, so a keyboard reader is never left on a
        // control that has gone.
        var fold = function () {

            var hadFocus = panel.contains( document.activeElement );

            panel.hidden = true;
            tab.hidden   = false;

            if ( hadFocus ) {

                tab.focus();
            }
        };

        var unfold = function () {

            var hadFocus = document.activeElement === tab;

            panel.hidden = false;
            tab.hidden   = true;

            if ( hadFocus ) {

                controls[ 0 ].focus();
            }
        };

        panel.querySelector( '#owa_overlay_logo' ).addEventListener( 'click', fold );
        OverlayDom.onActivate( tab, unfold );
        OverlayDom.onActivate( panel.querySelector( '#owa_player_start' ), () => this.play() );
        OverlayDom.onActivate( panel.querySelector( '#owa_player_stop' ), () => this.stop() );
        OverlayDom.onActivate( panel.querySelector( '#owa_player_close' ), fold );

        window.addEventListener( 'pagehide', function () { OWA_instance.endOverlaySession(); } );
    }

    setStatus( msg ) {

        var status = document.getElementById( 'owa-overlay-status' );

        if ( status ) {

            status.textContent = msg;
        }
    }

    /**
     * The message names an element from the recording -- its tag, id or name
     * -- which whoever sent it chose, so it is shown as text.
     */
    showNotification( msg, header ) {

        if ( this.notice ) {

            this.notice.show( header, msg );
        }
    }
}

export { Player };
