import { Util } from '../common/Util.js';

/**
 * Properties the DEVICE keeps and the wire does not carry.
 *
 * `timestamp` is the tracker's own clock in seconds, stamped when the event is
 * constructed, and it is load-bearing HERE: isNewSession() compares it with the
 * previous request to decide whether the session has timed out, fsts is seeded
 * from it, and advanceLastRequestTime() writes it to the session store. All of
 * that happens before the beacon is built.
 *
 * It was also SENT, on every beacon, and the server has no use for it. `ts` is
 * the edge receipt in microseconds -- the one server clock reading, what
 * yyyymmdd derives from, and environmental so a queue drain cannot restamp it --
 * and client_ts_usec is this same client clock at higher resolution, which the
 * skew calculation subtracts. So the wire carried a third spelling of an instant
 * it already had twice.
 *
 * KEPT OFF THE WIRE HERE rather than deleted in logEvent(), for two reasons.
 * getProperties() is the one place the event becomes data, so nothing can send
 * it by another route; and the beacon contract tests capture what logEvent() is
 * HANDED, so a deletion inside logEvent() would be invisible to the fixture that
 * records what the tracker emits.
 *
 * The queued domstream events go through getProperties() too. The player replays
 * them on a fixed interval and never reads their times, so they lose nothing.
 */
const LOCAL_ONLY = { timestamp: true };

/**
 * Properties only certain EVENT TYPES carry: name -> the types that carry it.
 *
 * `HTTP_REFERER` is the page's referrer, and it rode EVERY beacon of the page --
 * so a page with a page view and six clicks sent the same referring URL seven
 * times, up to 1KB each. The page view already records it, and the server does
 * not stamp it onto the other rows: the cube's `source` and `medium` read
 * session.referer_host, which is FIRST_VALUE over the session, i.e. the landing
 * page view. Nothing downstream reads a click's copy.
 *
 * GA draws the line in the same place -- page_referrer is a page_view parameter,
 * and session_start is what carries the session's attribution.
 *
 * HERE rather than at each call site, for the reason LOCAL_ONLY is here:
 * getProperties() is the one place the event becomes data, so nothing can put it
 * back on a click by another route. The page store still holds it (scope 'page'),
 * because isNewSession() and the campaign logic read it locally.
 *
 * An event with no type is not filtered -- a caller building a raw event has not
 * said what it is, and dropping a property it deliberately set would be worse
 * than sending one byte too many.
 */
const EVENT_SCOPED = { HTTP_REFERER: { page_view: true } };

/**
 * OWA Generic Event Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */
class OwaEvent {
	
	constructor() {
		

	    this.properties = {};
		this.id = '';
		this.siteId = '';
		this.set('timestamp', Util.getCurrentUnixTimestamp() );
	}

    get(name) {

        if ( this.properties.hasOwnProperty(name) ) {

            return this.properties[name];
        }
    }

    set(name, value) {

        this.properties[name] = value;
    }

    setEventType(event_type) {

        this.set("event_type", event_type);
    }

    /**
     * What goes on the wire: every property except the device-local ones.
     *
     * A copy, so a caller cannot reach this.properties through the return value
     * and put a local-only property back on the beacon.
     */
    getProperties() {

        var out = {};
        var type = this.properties.event_type;

        for ( var name in this.properties ) {

            if ( ! this.properties.hasOwnProperty( name ) || LOCAL_ONLY[ name ] ) {

                continue;
            }

            if ( EVENT_SCOPED[ name ] && type && ! EVENT_SCOPED[ name ][ type ] ) {

                continue;
            }

            out[ name ] = this.properties[ name ];
        }

        return out;
    }

    merge(properties) {

        for( var param in properties) {

            if (properties.hasOwnProperty(param)) {

                this.set(param, properties[param]);
            }
        }
    }

    isSet( name ) {

        if ( this.properties.hasOwnProperty( name ) ) {

            return true;
        }
    }
}

export { OwaEvent };