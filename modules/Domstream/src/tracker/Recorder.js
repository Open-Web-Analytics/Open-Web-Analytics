/**
 * The domstream recorder: pointer, scroll, click and keystroke samples from a
 * page load, sent as chunks of the `domstream` tracking event.
 *
 * Compiled into owa.tracker.js by the Domstream module's build manifest
 * (`contributes`) and registered with OWATracker.registerPlugin(), so the
 * tracker names nothing here. The snippet starts it with trackDomStream, a
 * command the module adds only while it is active.
 *
 * A SAMPLE is a tuple, [ms since the previous sample, type, ...]:
 *   ['m', dx, dy]              pointer moved, from the previous position
 *   ['s', y]                   page scrolled to y
 *   ['c', x, y, tag, id, name] clicked at x, y on that element
 *   ['k', tag, id, name]       a key was pressed in that element -- which key
 *                              is NEVER recorded, and nothing at all is
 *                              recorded in a password field
 *
 * A CHUNK is sent every domstreamFlushInterval, when it reaches
 * domstreamMaxSamples, and when the page is hidden or unloads. It carries the
 * recording's id (one per page load), its seq within the recording (1, 2, ...)
 * and the event_seq of the page view it belongs to. The server derives the
 * chunk's id from the recording id and seq, so a chunk delivered twice is
 * stored once.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

import { OWATracker } from '../../../Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../../Base/src/common/owa.js';
import { Util } from '../../../Base/src/common/Util.js';

const EVENT_NAME = 'domstream';

const DEFAULTS = {
    domstreamSampleRate: 100,       // percent of page loads recorded
    domstreamFlushInterval: 3000,   // ms between chunks
    domstreamMaxSamples: 200,       // samples per chunk
    domstreamMaxChars: 24000,       // serialised size per chunk, under a beacon's limit
    domstreamMaxDurationMsec: 1800000, // a recording stops after this
    domstreamMoveInterval: 100,     // ms between pointer samples
};

const MODIFIERS = [ 'Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'AltGraph' ];

class Recorder {

    constructor( tracker ) {

        this.tracker     = tracker;
        this.recordingId = '';
        this.seq         = 0;
        this.samples     = [];
        this.chars       = 0;
        this.started     = 0;
        this.last        = 0;
        this.chunkStart  = null;
        this.x           = 0;
        this.y           = 0;
        this.lastMove    = 0;
        this.scrollY     = null;
        this.timer       = null;
        this.recording   = false;
        this.pageViewSeq = null;
        this.listeners   = [];
    }

    option( name ) {

        var value = this.tracker.getOption( name );

        return value === undefined || value === null ? DEFAULTS[ name ] : value;
    }

    now() {

        return Date.now();
    }

    /** Start recording this page load, if it falls in the sample. */
    start() {

        if ( this.recording || ! this.tracker.active ) {

            return false;
        }

        if ( Math.random() * 100 >= Number( this.option( 'domstreamSampleRate' ) ) ) {

            OWA.debug( 'domstream: this page load is outside the sample.' );

            return false;
        }

        this.recording   = true;
        this.recordingId = Util.generateRandomGuid();
        this.started     = this.now();
        this.last        = this.started;

        this.listen( document, 'mousemove', ( e ) => this.onMove( e ), { passive: true, capture: true } );
        this.listen( window, 'scroll', () => this.onScroll(), { passive: true } );
        this.listen( document, 'keydown', ( e ) => this.onKey( e ), true );
        this.listen( document, 'visibilitychange', () => {

            if ( document.visibilityState === 'hidden' ) {

                this.flush();
            }
        }, false );
        this.listen( window, 'pagehide', () => this.flush(), false );

        // Clicks arrive through the tracker's own handler (the tracker.click
        // hook), which is bound only once something asks for it. Binding it
        // sends no click events unless the page also tracks clicks.
        this.tracker.bindClickEvents();

        this.timer = setInterval( () => this.flush(), Number( this.option( 'domstreamFlushInterval' ) ) );

        return true;
    }

    stop() {

        this.flush();
        this.recording = false;

        if ( this.timer ) {

            clearInterval( this.timer );
            this.timer = null;
        }

        this.listeners.forEach( ( [ target, type, fn, opts ] ) => target.removeEventListener( type, fn, opts ) );
        this.listeners = [];
    }

    listen( target, type, fn, opts ) {

        target.addEventListener( type, fn, opts );
        this.listeners.push( [ target, type, fn, opts ] );
    }

    push( sample ) {

        if ( ! this.recording ) {

            return;
        }

        var now = this.now();

        if ( now - this.started > Number( this.option( 'domstreamMaxDurationMsec' ) ) ) {

            this.stop();

            return;
        }

        if ( this.chunkStart === null ) {

            this.chunkStart = now;
        }

        var tuple = [ Math.max( 0, now - this.last ) ].concat( sample );

        this.last = now;
        this.samples.push( tuple );
        this.chars += JSON.stringify( tuple ).length + 1;

        if ( this.samples.length >= Number( this.option( 'domstreamMaxSamples' ) )
             || this.chars >= Number( this.option( 'domstreamMaxChars' ) ) ) {

            this.flush();
        }
    }

    onMove( e ) {

        var now = this.now();

        if ( now - this.lastMove < Number( this.option( 'domstreamMoveInterval' ) ) ) {

            return;
        }

        this.lastMove = now;

        var x = Math.round( Number( e.pageX ) || 0 );
        var y = Math.round( Number( e.pageY ) || 0 );

        this.push( [ 'm', x - this.x, y - this.y ] );
        this.x = x;
        this.y = y;
    }

    onScroll() {

        var y = Math.round( window.pageYOffset || document.documentElement.scrollTop || 0 );

        if ( y === this.scrollY ) {

            return;
        }

        this.scrollY = y;
        this.push( [ 's', y ] );
    }

    /**
     * THAT a key was pressed, and where -- never which key. e.key is read only
     * to leave out a lone modifier, and is not kept.
     */
    onKey( e ) {

        var target = e && e.target;

        if ( ! target || ( target.tagName === 'INPUT' && String( target.type ).toLowerCase() === 'password' ) ) {

            return;
        }

        if ( MODIFIERS.indexOf( e.key ) !== -1 ) {

            return;
        }

        this.push( [ 'k' ].concat( Recorder.element( target ) ) );
    }

    onClick( click ) {

        this.push( [ 'c',
            Math.round( Number( click.get( 'click_x' ) ) || 0 ),
            Math.round( Number( click.get( 'click_y' ) ) || 0 ),
            String( click.get( 'dom_element_tag' ) || '' ),
            String( click.get( 'dom_element_id' ) || '' ),
            String( click.get( 'dom_element_name' ) || '' ) ] );
    }

    /** @return {string[]} tag, id, name */
    static element( node ) {

        return [
            String( node.tagName || '' ).toLowerCase(),
            String( node.id || '' ),
            String( ( node.getAttribute && node.getAttribute( 'name' ) ) || '' ),
        ];
    }

    /** Send what has accrued as the next chunk. */
    flush() {

        if ( ! this.samples.length ) {

            return false;
        }

        this.seq++;

        var event    = this.tracker.makeEvent();
        var viewport = this.tracker.getViewportDimensions();

        event.setEventType( EVENT_NAME );
        event.set( 'recording_id', this.recordingId );
        event.set( 'seq', this.seq );
        event.set( 'offset_ms', Math.max( 0, this.chunkStart - this.started ) );
        event.set( 'duration_ms', Math.max( 0, this.last - this.chunkStart ) );
        event.set( 'viewport_w', viewport.width );
        event.set( 'viewport_h', viewport.height );
        event.set( 'samples', JSON.stringify( this.samples ) );

        if ( this.pageViewSeq !== null ) {

            event.set( 'page_view_seq', this.pageViewSeq );
        }

        this.samples    = [];
        this.chars      = 0;
        this.chunkStart = null;

        this.tracker.trackEvent( event );

        return true;
    }
}

const recorders = new WeakMap();

function recorderFor( tracker ) {

    if ( ! recorders.has( tracker ) ) {

        recorders.set( tracker, new Recorder( tracker ) );
    }

    return recorders.get( tracker );
}

OWATracker.registerPlugin( {

    name: 'domstream',

    reservedEventNames: [ EVENT_NAME ],

    methods: {

        /** The snippet command: start recording this page load. */
        trackDomStream() {

            return recorderFor( this ).start();
        },

        /** Percent of page loads recorded, 0-100. */
        setDomstreamSampleRate( value ) {

            this.setOption( 'domstreamSampleRate', value );
        },
    },

    init( tracker ) {

        var recorder = recorderFor( tracker );

        var seqOf = ( event ) => {

            var seq = event ? event.get( 'event_seq' ) : null;

            return seq === undefined || seq === null || seq === '' ? null : Number( seq );
        };

        // Loaded as a chunk after the page view was sent: pick that one up.
        if ( tracker.lastPageView ) {

            recorder.pageViewSeq = seqOf( tracker.lastPageView );
        }

        // The page view a chunk belongs to: the latest this tracker sent.
        OWA.addAction( 'tracker.pageView', ( o ) => {

            if ( o && o.tracker === tracker ) {

                recorder.pageViewSeq = seqOf( o.event );
            }
        } );

        OWA.addAction( 'tracker.click', ( o ) => {

            if ( o && o.tracker === tracker ) {

                recorder.onClick( o.click );
            }
        } );
    },
} );

/*
 * Playback: the overlay's `loadPlayer` action, loaded only in an overlay
 * session -- the recorder itself never downloads it.
 */
OWA.registerOverlayMode( 'loadPlayer', () => {

    Util.loadCss( OWA.getSetting( 'baseUrl' ) + 'public/base/css/owa.overlay.css', function () {} );

    import( /* webpackChunkName: "owa.player" */ './Player.js' ).then( ( { Player } ) => {

        OWA.overlay = new Player();
        OWA.overlay.init();
    } );
} );

export { Recorder, recorderFor, EVENT_NAME };
