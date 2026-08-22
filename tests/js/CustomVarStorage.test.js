jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * Custom variables live in the session store, and every store is JSON.
 *
 * TWO COOKIES FOR ONE CONCEPT
 * Session-scoped custom variables were kept in a store called 'b', sitting
 * beside 's', which IS the session store. So a variable scoped to the session
 * did not share the lifetime of the session it was scoped to, and every request
 * carried an extra cookie to say so.
 *
 * ONE ENCODING
 * 'v' and 's' were serialized as key=>value|||key=>value with no escaping of
 * either separator, so a value containing '=>' or '|||' silently corrupted the
 * whole store. All four stores are JSON now.
 *
 * Both changes are safe under an existing installation because the loader
 * SNIFFS what it reads -- a leading '{' means JSON, anything else means assoc --
 * so an old cookie is parsed in its own format and rewritten in the new one.
 * These tests assert that compatibility explicitly, since it is the whole
 * reason the change can ship without a migration.
 */
describe('custom variable storage', () => {

    let tracker;

    beforeEach(() => {
        document.cookie.split(';').forEach((c) => {
            const name = c.split('=')[0].trim();
            if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
        });
        OWA.state.stores = {};
        OWA.state.storeFormats = {};
        // cookie_domain_set, as the other tracker suites do: without it
        // trackPageView() tries to derive a cookie domain and jsdom has none.
        tracker = new OWATracker({ cookie_domain_set: true });
        tracker.setSiteId('cv-storage-test');
    });

    test('a session-scoped variable is stored in the session store', () => {
        tracker.setCustomVar(1, 'plan', 'pro', 'session');

        expect(OWA.getState('s', 'cv1')).toBe('plan=pro');
        expect(OWA.getState('b', 'cv1')).toBeFalsy();
    });

    /**
     * The bug that made this design necessary, pinned so it cannot come back.
     *
     * The usual call order is setCustomVar() then trackPageView(). If that
     * pageview starts a NEW session, resetSessionState() clears the session
     * store -- so a value written at setCustomVar() time is wiped by the very
     * session it was set for, and every later pageview in that session loses
     * it. Writing after the session is resolved is what fixes it.
     */
    test('a variable set before the first pageview survives into that session', () => {
        // Slot within maxCustomVars (5): only those slots are rehydrated onto a
        // later event, so a higher slot would appear to work on the first
        // beacon and silently vanish afterwards.
        tracker.setCustomVar(2, 'plan', 'pro', 'session');

        const beacons = [];
        tracker.logEvent = (p) => beacons.push({ ...p });
        tracker.trackPageView(location.href);

        expect(beacons[0].is_new_session).toBe(true);
        expect(beacons[0].cv2).toBe('plan=pro');

        // a second pageview in the SAME session must still carry it
        const next = new OWATracker({ cookie_domain_set: true });
        next.setSiteId(tracker.getSiteId());
        next.logEvent = (p) => beacons.push({ ...p });
        next.trackPageView(location.href);

        expect(beacons[1].is_new_session).toBeFalsy();
        expect(beacons[1].cv2).toBe('plan=pro');
    });

    test('a visitor-scoped variable still goes to the visitor store', () => {
        tracker.setCustomVar(2, 'tier', 'gold', 'visitor');

        expect(OWA.getState('v', 'cv2')).toBe('tier=gold');
        expect(OWA.getState('s', 'cv2')).toBeFalsy();
    });

    test('promoting a variable from session to visitor leaves no session copy', () => {
        tracker.setCustomVar(3, 'x', '1', 'session');
        tracker.setCustomVar(3, 'x', '2', 'visitor');

        expect(OWA.getState('v', 'cv3')).toBe('x=2');
        expect(OWA.getState('s', 'cv3')).toBeFalsy();
        expect(tracker.getCustomVar(3)).toBe('x=2');
    });

    /**
     * The compatibility case: a visitor who was mid-session when this shipped
     * still has values in the old store, and must keep seeing them.
     */
    test('a value left in the old store is still readable', () => {
        OWA.setState('b', 'cv4', 'legacy=value');
        tracker.deleteGlobalEventProperty('cv4');

        expect(tracker.getCustomVar(4)).toBe('legacy=value');
    });

    test('a new write wins over a value left in the old store', () => {
        OWA.setState('b', 'cv5', 'stale=old');
        tracker.setCustomVar(5, 'fresh', 'new', 'session');
        tracker.deleteGlobalEventProperty('cv5');

        expect(tracker.getCustomVar(5)).toBe('fresh=new');
    });

    test('deleting clears the current, legacy and visitor stores', () => {
        OWA.setState('b', 'cv6', 'legacy=value');
        tracker.setCustomVar(6, 'a', 'b', 'visitor');
        tracker.deleteCustomVar(6);

        expect(OWA.getState('s', 'cv6')).toBeFalsy();
        expect(OWA.getState('b', 'cv6')).toBeFalsy();
        expect(OWA.getState('v', 'cv6')).toBeFalsy();
        expect(tracker.getCustomVar(6)).toBeFalsy();
    });

    describe('storage format', () => {

        test('every registered store serializes as JSON', () => {
            ['v', 's', 'c', 'b'].forEach((store) => {
                expect(OWA.state.getFormat(store)).toBe('json');
            });
        });

        /**
         * The reason the old format had to go: it separated keys with '=>' and
         * '|||' and escaped neither.
         */
        test('a value containing the old separators survives a round trip', () => {
            const nasty = 'a=>b|||c';

            OWA.setState('s', 'cv7', nasty);

            expect(OWA.getState('s', 'cv7')).toBe(nasty);
        });

        /**
         * The migration seam. An existing visitor's cookie is in the old
         * format, and must still be read -- otherwise this change would log
         * everyone out of their own state.
         */
        test('a cookie written in the old format is still parsed', () => {
            expect(Util.getCookieValueFormat('sid=>abc123|||last_req=>999')).toBe('assoc');
            expect(Util.getCookieValueFormat('{"sid":"abc123"}')).toBe('json');
        });
    });

    describe('across a session boundary', () => {

        /**
         * Set before the first pageview, and that pageview starts a session.
         * The value must survive: it was set FOR the session that is starting.
         */
        test('a variable set on this page load survives the session that starts', () => {
            tracker.setCustomVar(2, 'plan', 'pro', 'session');

            const beacons = [];
            tracker.logEvent = (p) => beacons.push({ ...p });
            tracker.trackPageView(location.href);

            expect(beacons[0].is_new_session).toBe(true);
            expect(OWA.getState('s', 'cv2')).toBe('plan=pro');

            // and a later pageview in the SAME session still carries it
            const next = new OWATracker({ cookie_domain_set: true });
            next.setSiteId('cv-storage-test');
            next.logEvent = (p) => beacons.push({ ...p });
            next.trackPageView(location.href);

            expect(beacons[1].is_new_session).toBeFalsy();
            expect(beacons[1].cv2).toBe('plan=pro');
        });

        /**
         * The other half. A value left by an EARLIER session must not be
         * inherited by a new one -- that is the leak the old 'b' cookie had,
         * and moving to 's' would have carried it over if the reset only
         * re-applied without clearing.
         */
        test('a variable from a previous session is not inherited', () => {
            // a value in the store that this page load did NOT set
            OWA.setState('s', 'cv3', 'stale=lastweek');

            const fresh = new OWATracker({ cookie_domain_set: true });
            fresh.setSiteId('cv-storage-test');
            fresh.logEvent = () => {};
            fresh.resetSessionState();

            expect(OWA.getState('s', 'cv3')).toBeFalsy();
        });

        /**
         * And the reason the value is also written immediately rather than only
         * held: a browser closed before any pageview must not lose it.
         */
        test('the value is in the store before any pageview is tracked', () => {
            tracker.setCustomVar(4, 'plan', 'pro', 'session');

            expect(OWA.getState('s', 'cv4')).toBe('plan=pro');
        });

        /**
         * last_req is the one thing that outlives the reset, because the
         * boundary is computed from it.
         */
        test('last_req survives the reset', () => {
            OWA.setState('s', 'last_req', 1766000000);
            OWA.setState('s', 'referer', 'https://example.com/');

            tracker.resetSessionState();

            expect(OWA.getState('s', 'last_req')).toBe(1766000000);
            expect(OWA.getState('s', 'referer')).toBeFalsy();
        });
    });
});
