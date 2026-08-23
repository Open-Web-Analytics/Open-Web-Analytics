jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Two trackers on one page.
 *
 * A page can carry more than one tracker -- two site ids, typically a sub-site
 * and the network it belongs to. They share the VISITOR and keep their own
 * SESSION, which is the split GA makes: _ga carries the client id across every
 * property, _ga_<property> carries session state per property.
 *
 * They used to share both, and this file used to assert that as correct. It was
 * not: a session row is loaded by session_id alone, so one shared id could not
 * represent two sites, and the second site's facts ended up pointing at the
 * first site's session row.
 *
 * That sharing is only real if the second tracker can see what the first one
 * derived. It runs its own manageState -- stateInit is per tracker -- so it
 * re-derives everything, and anything it reads from the wrong place it will get
 * wrong.
 *
 * The case that broke: setLastRequestTime read last_req straight from the
 * COOKIE. The first tracker's value is not in the cookie yet, because the
 * session store waits for a beacon to be accepted before it persists. So the
 * second tracker saw no last_req, declared a NEW session, minted a second sid
 * for a single page view, and overwrote the first tracker's sid in the shared
 * store. Reading memory first fixes it: memory holds what this page load set,
 * which is exactly what the second tracker needs.
 */

function reset() {
    OWA.initializeStateManager();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.persistenceReleased = {};
}

/** Two trackers, each capturing its own beacons, both tracking the same page. */
function trackBoth() {
    const a = new OWATracker({ cookie_domain_set: true, site_id: 'site-a' });
    const b = new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });

    const beacons = { a: [], b: [] };
    a.logEvent = (p) => beacons.a.push({ ...p });
    b.logEvent = (p) => beacons.b.push({ ...p });

    a.trackPageView('https://example.com/pricing');
    b.trackPageView('https://example.com/pricing');

    return { a, b, beacons };
}

describe('two trackers sharing the state stores', () => {

    beforeEach(reset);
    afterEach(reset);

    test('each site gets its OWN session', () => {
        const { beacons } = trackBoth();

        expect(beacons.a[0].session_id).toBeTruthy();
        expect(beacons.b[0].session_id).toBeTruthy();
        expect(beacons.b[0].session_id).not.toBe(beacons.a[0].session_id);
    });

    test('both report the session as starting here, because it did', () => {
        const { beacons } = trackBoth();

        // is_new_session marks THIS EVENT as occurring at the start of a new
        // session -- which is what resolveEntryPage() reads it as server-side.
        // It is a fact about the page load, not about which tracker happened to
        // derive it first, so it lives in the page store and both trackers see
        // it.
        //
        // This test previously asserted the opposite -- that only the first
        // tracker reports it -- on the reasoning that two new-session flags
        // would have the server create the session twice. It does not: the
        // second logSession() finds the row already persisted and skips. What
        // actually happens is that the second site gets no session row of its
        // own, which is the per-site limitation below, not something this flag
        // causes.
        expect(beacons.a[0].is_new_session).toBe(true);
        expect(beacons.b[0].is_new_session).toBe(true);
    });

    test('each site owns the session its facts point at', () => {
        /*
         * This was pinned as a KNOWN LIMITATION and is now fixed.
         *
         * A session row is keyed by session_id ALONE -- SessionHandlers::
         * logSession() does load($event->get('session_id'), 'id') with no
         * site_id -- so one shared session id could not represent two sites.
         * Measured against a real install before the fix: both trackers sent
         * the same session_id, site A got a session row, site B got none, and
         * site B's request facts referenced site A's session. Reporting for B
         * that joined the session read A's data, silently.
         *
         * The store is scoped to a site now, which is where GA splits too:
         * _ga holds the client id across every property, _ga_<property> holds
         * session state per property. Measured on a live GA tag with two
         * properties configured -- one _ga, two _ga_<id> cookies.
         */
        const { beacons } = trackBoth();

        expect(beacons.a[0].site_id).not.toBe(beacons.b[0].site_id);
        expect(beacons.a[0].session_id).not.toBe(beacons.b[0].session_id);
    });

    test('they report one visitor', () => {
        const { beacons } = trackBoth();

        expect(beacons.a[0].visitor_id).toBeTruthy();
        expect(beacons.b[0].visitor_id).toBe(beacons.a[0].visitor_id);
    });

    test('each tracker keeps its session id in its own store', () => {
        const { a, b, beacons } = trackBoth();

        expect(OWA.getState(a.storeName('s'), 'sid')).toBe(beacons.a[0].session_id);
        expect(OWA.getState(b.storeName('s'), 'sid')).toBe(beacons.b[0].session_id);

        // and the stores really are different places
        expect(a.storeName('s')).not.toBe(b.storeName('s'));
    });

    test('a SESSION-scoped custom var stays with the site that set it', () => {
        /*
         * A consequence of scoping the session store, and the right one: a
         * session-scoped value cannot outlive or escape its session, and
         * sessions belong to a site now. Site B is in a different session, so a
         * variable set on site A's session is not part of it.
         *
         * Visitor scope is the axis that still crosses trackers -- see below.
         */
        const a = new OWATracker({ cookie_domain_set: true, site_id: 'site-a' });
        a.setCustomVar(1, 'Plan', 'Pro', 'session');

        const b = new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });
        const beacons = [];
        b.logEvent = (p) => beacons.push({ ...p });
        b.trackPageView('https://example.com/pricing');

        expect(beacons[0].cv1).toBeUndefined();
    });

    test('a VISITOR-scoped custom var still rides the events of both', () => {
        // The visitor is shared -- GA's _ga -- so anything scoped to the
        // visitor is shared with it.
        const a = new OWATracker({ cookie_domain_set: true, site_id: 'site-a' });
        a.setCustomVar(2, 'Tier', 'Gold', 'visitor');

        const b = new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });
        const beacons = [];
        b.logEvent = (p) => beacons.push({ ...p });
        b.trackPageView('https://example.com/pricing');

        expect(beacons[0].cv2).toBe('Tier=Gold');
    });
});
