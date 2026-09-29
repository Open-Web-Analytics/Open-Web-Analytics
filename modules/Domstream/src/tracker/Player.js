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
import * as jQuery from 'jquery';
import * as jGrowl from 'jgrowl';

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
        OWA_instance.registerStateStore( 'overlay', '', '', 'json' );
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
        var that   = this;

        jQuery.ajax( {
            url: params.api_url,
            // A plain cross-origin GET: the credentials ride the query string,
            // so this stays a CORS simple request.
            dataType: 'json',
            success: function ( data ) {
                that.load( data );
            },
        } );
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

        jQuery( '#owa_player_start' ).removeClass( 'active' );
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

        jQuery( '#owa-cursor' ).css( { top: y + 'px', left: x + 'px' } );
        this.setStatus( 'Pointer at ' + x + ', ' + y );
    }

    scrollViewport( y ) {

        window.scroll( 0, y );
        this.setStatus( 'Scrolled to ' + y );
    }

    /** A CSS selector for an element the recording names, or '' if it names none. */
    static selector( tag, id, name ) {

        var esc = ( value ) => ( window.CSS && CSS.escape ) ? CSS.escape( value ) : String( value ).replace( /[^\w-]/g, '\\$&' );

        if ( id ) {

            return '#' + esc( id );
        }

        if ( name ) {

            return ( tag ? esc( tag ) : '' ) + '[name="' + String( name ).replace( /"/g, '\\"' ) + '"]';
        }

        return '';
    }

    click( x, y, tag, id, name ) {

        var marker = jQuery( '<div class="owa-click-marker"></div>' ).css( {
            position: 'absolute', left: x + 'px', top: y + 'px', 'z-index': 89,
        } );

        jQuery( 'body' ).append( marker );

        var label = Player.selector( tag, id, name ) || tag || 'the page';

        this.setStatus( 'Click at ' + x + ', ' + y );
        this.showNotification( label, 'Clicked:' );
    }

    keyPressed( tag, id, name ) {

        var selector = Player.selector( tag, id, name );
        var node     = selector ? jQuery( selector ).first() : jQuery();

        if ( node.length ) {

            node.addClass( 'owa-key-pressed' );
            setTimeout( () => node.removeClass( 'owa-key-pressed' ), 300 );
        }

        this.setStatus( 'Key pressed in ' + ( selector || tag || 'the page' ) );
    }

    showPlayerControls() {

        jQuery( 'body' ).append( '<div id="owa_overlay"></div>' );
        jQuery( '#owa_overlay' )
            .append( '<div id="owa_overlay_logo"></div>' )
            .append( '<div class="owa_overlay_control" id="owa_player_start">Play</div>' )
            .append( '<div class="owa_overlay_control" id="owa_player_stop">Pause</div>' )
            .append( '<div class="owa_overlay_control" id="owa_player_close">Hide</div>' )
            .append( '<div id="owa-overlay-status">...</div>' );

        jQuery( 'body' ).append( '<div id="owa_overlay_hidden"></div>' );
        jQuery( '#owa_overlay_hidden' ).hide();

        jQuery( 'body' ).append( '<div id="owa-cursor"><img src="'
            + OWA_instance.getSetting( 'baseUrl' ) + 'public/base/i/cursor2.png"></div>' );

        jQuery( '.owa_overlay_control' ).click( function () {
            jQuery( '.owa_overlay_control' ).removeClass( 'active' );
            jQuery( this ).addClass( 'active' );
        } );

        jQuery( '#owa_overlay_logo' ).click( function () {
            jQuery( '#owa_overlay' ).slideToggle( 'fast' );
            jQuery( '#owa_overlay_hidden' ).fadeIn( 'slow' );
        } );

        jQuery( '#owa_overlay_hidden' ).click( function () {
            jQuery( '#owa_overlay' ).slideToggle( 'fast' );
            jQuery( '#owa_overlay_hidden' ).fadeOut();
        } );

        var that = this;

        jQuery( '#owa_player_start' ).on( 'click', function () { that.play(); } );
        jQuery( '#owa_player_stop' ).on( 'click', function () { that.stop(); } );
        jQuery( '#owa_player_close' ).click( function () {
            jQuery( '#owa_overlay' ).slideToggle( 'fast' );
            jQuery( '#owa_overlay_hidden' ).fadeIn( 'slow' );
        } );

        jQuery( window ).on( 'pagehide', function () { OWA_instance.endOverlaySession(); } );
    }

    setStatus( msg ) {

        jQuery( '#owa-overlay-status' ).text( msg );
    }

    showNotification( msg, header ) {

        jQuery.jGrowl( msg, { life: 250, speed: 25, position: 'center', closer: false, pool: 1, header: header } );
    }
}

export { Player };
