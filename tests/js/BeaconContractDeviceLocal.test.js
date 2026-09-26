import { OwaEvent } from '../../modules/Base/src/tracker/OwaEvent.js';

/**
 * A device-local property never reaches the wire.
 *
 * `timestamp` is the tracker's own clock in seconds, stamped when the event is
 * constructed. The DEVICE needs it: isNewSession() compares it with the previous
 * request to decide whether the session has timed out, fsts is seeded from it,
 * and advanceLastRequestTime() writes it to the session store.
 *
 * The SERVER never did. `ts` is the edge receipt in microseconds -- the one
 * server clock reading, and what yyyymmdd derives from -- and client_ts_usec is
 * this same client clock at higher resolution, which the skew calculation
 * subtracts. So the beacon carried a third spelling of an instant it already had
 * twice, on every event, for as long as v2 has existed.
 *
 * THE SPLIT IS IN getProperties(), which is the one place an event becomes data,
 * so no other route can put it back on a beacon. That placement is also what
 * makes the change visible to BeaconContract*.test.js: those capture what
 * logEvent() is HANDED, so deleting the key inside logEvent() would have left
 * every contract fixture still claiming it was sent.
 */
describe('device-local properties', () => {

    test('timestamp is on the event', () => {
        const event = new OwaEvent();

        expect(event.get('timestamp')).toBeTruthy();
        expect(event.isSet('timestamp')).toBe(true);
    });

    test('and not in what goes on the wire', () => {
        const event = new OwaEvent();

        expect(event.getProperties().hasOwnProperty('timestamp')).toBe(false);
    });

    /* Everything else still passes through. */
    test('an ordinary property is unaffected', () => {
        const event = new OwaEvent();
        event.set('page_title', 'Home');
        event.set('client_ts_usec', 1700000000000000);

        const wire = event.getProperties();

        expect(wire.page_title).toBe('Home');
        expect(wire.client_ts_usec).toBe(1700000000000000);
    });

    /*
     * A COPY, so a caller cannot reach this.properties through the return value
     * and put the local-only property back. getProperties() used to hand back the
     * live object.
     */
    test('the wire view is a copy, not the event internals', () => {
        const event = new OwaEvent();

        const wire = event.getProperties();
        wire.timestamp = 12345;
        wire.page_title = 'injected';

        expect(event.get('page_title')).toBeUndefined();
        expect(event.getProperties().hasOwnProperty('timestamp')).toBe(false);
    });

    /*
     * The queued domstream events are getProperties() output too -- they become
     * stream_events JSON. The player replays them on a fixed interval and never
     * reads their times, and owa_domstream.timestamp is stamped server-side from
     * `ts` now, so nothing downstream wanted the copy.
     */
    test('a queued event carries no clock either', () => {
        const event = new OwaEvent();
        event.setEventType('dom.click');

        const queued = JSON.parse(JSON.stringify(event.getProperties()));

        expect(queued.event_type).toBe('dom.click');
        expect(queued.hasOwnProperty('timestamp')).toBe(false);
    });
});
