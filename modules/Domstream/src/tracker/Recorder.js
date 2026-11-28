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

/*
 * What the server accepts of a chunk (Classes\Chunk): the most samples, the most
 * characters -- less a sample's room, as a chunk is sent once it reaches the
 * limit, not before -- the longest gap a sample may record, and the longest
 * text. A site's options are held to these; DomstreamLimitsTest compares them.
 *
 * domstreamMaxChars is also held to what a browser sends: the chunk goes as a
 * form-urlencoded body, about twice its characters once encoded, and a
 * keepalive fetch or sendBeacon refuses a body over 64 KiB.
 */
const LIMITS = {
    domstreamMaxSamples: 1000,
    domstreamMaxChars: 28000,
    domstreamMaxDurationMsec: 3600000,
};

const MAX_TEXT = 255;

const DEFAULTS = {
    /**
     * Percent of page loads recorded, 0-100. setDomstreamSampleRate() sets it.
     * A value that is not a number records nothing; one outside 0-100 is
     * held to it.
     *
     * @option
     */
    domstreamSampleRate: 100,
    /**
     * Milliseconds between the chunks of a recording.
     *
     * @option
     */
    domstreamFlushInterval: 3000,
    /**
     * Samples per chunk. A chunk is sent early when it reaches this.
     *
     * @option
     */
    domstreamMaxSamples: 200,
    /**
     * Maximum serialised size of a chunk, in characters.
     * A chunk is sent early when it reaches this.
     *
     * @option
     */
    domstreamMaxChars: 24000,
    /**
     * Milliseconds after which a recording stops.
     *
     * @option
     */
    domstreamMaxDurationMsec: 1800000,
    /**
     * Minimum milliseconds between two pointer samples.
     *
     * @option
     */
    domstreamMoveInterval: 100,
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
        this.sessionId   = null;
        this.listeners   = [];
    }

    option( name ) {

        var value = this.tracker.getOption( name );

        value = value === undefined || value === null ? DEFAULTS[ name ] : value;

        // A site's own value, held to what the server accepts (LIMITS).
        if ( LIMITS[ name ] !== undefined ) {

            value = Math.min( Number( value ) || DEFAULTS[ name ], LIMITS[ name ] );
        }

        return value;
    }

    now() {

        return Date.now();
    }

    /** @return {number|null} domstreamSampleRate held to 0-100, or null when not a number */
    sampleRate() {

        var value = this.option( 'domstreamSampleRate' );
        var rate  = typeof value === 'number' || ( typeof value === 'string' && value.trim() !== '' )
            ? Number( value ) : NaN;

        if ( ! isFinite( rate ) ) {

            return null;
        }

        return Math.min( 100, Math.max( 0, rate ) );
    }

    /** Start recording this page load, if it falls in the sample. */
    start() {

        if ( this.recording || ! this.tracker.active || this.tracker.overlayPaused ) {

            return false;
        }

        var rate = this.sampleRate();

        if ( rate === null ) {

            OWA.debug( 'domstream: domstreamSampleRate is not a number; nothing is recorded.' );

            return false;
        }

        if ( Math.random() * 100 >= rate ) {

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
            Recorder.text( click.get( 'dom_element_tag' ) ),
            Recorder.text( click.get( 'dom_element_id' ) ),
            Recorder.text( click.get( 'dom_element_name' ) ) ] );
    }

    /** @return {string[]} tag, id, name */
    static element( node ) {

        return [
            Recorder.text( String( node.tagName || '' ).toLowerCase() ),
            Recorder.text( node.id ),
            Recorder.text( node.getAttribute && node.getAttribute( 'name' ) ),
        ];
    }

    /**
     * A sample's text, at most MAX_TEXT characters (code points, as the server
     * counts them): one longer, a framework's generated id say, refused the
     * whole chunk (Chunk::MAX_TEXT).
     *
     * @return {string}
     */
    static text( value ) {

        return Array.from( String( value || '' ) ).slice( 0, MAX_TEXT ).join( '' );
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

        // The session of the page view the chunk belongs to, which a session
        // restarted since by another event does not change.
        if ( this.sessionId ) {

            event.set( 'session_id', this.sessionId );
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

    // A chunk carries no engagement and never starts or restarts a session.
    passiveEventNames: [ EVENT_NAME ],

    methods: {

        /**
         * Record this page load's pointer movement, scrolling, clicks and key
         * presses (never which key), if it falls in domstreamSampleRate.
         *
         * @command
         */
        trackDomStream() {

            return recorderFor( this ).start();
        },

        /**
         * Percent of page loads trackDomStream records, 0-100.
         *
         * @command
         * @param {number} value
         */
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

        var sessionOf = ( event ) => ( event && event.get( 'session_id' ) ) || null;

        // Loaded as a chunk after the page view was sent: pick that one up.
        if ( tracker.lastPageView ) {

            recorder.pageViewSeq = seqOf( tracker.lastPageView );
            recorder.sessionId   = sessionOf( tracker.lastPageView );
        }

        // The page view a chunk belongs to: the latest this tracker sent.
        OWA.addAction( 'tracker.pageView', ( o ) => {

            if ( o && o.tracker === tracker ) {

                recorder.pageViewSeq = seqOf( o.event );
                recorder.sessionId   = sessionOf( o.event );
            }
        } );

        OWA.addAction( 'tracker.click', ( o ) => {

            if ( o && o.tracker === tracker ) {

                recorder.onClick( o.click );
            }
        } );
    },
} );

export { Recorder, recorderFor, EVENT_NAME };
