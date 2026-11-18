import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Two trackers on one page.
 *
 * A page can carry more than one tracker -- two site ids, typically a sub-site
 * and the network it belongs to. They share the VISITOR and keep their own
 * SESSION: the visitor store is shared across every site, the session store
 * is one per site.
 *
 * That sharing is only real if the second tracker can see what the first one
 * derived. It runs its own manageState -- stateInit is per tracker -- so it
 * re-derives everything, and anything it reads from the wrong place it will get
 * wrong.
 *
 * setLastRequestTime reads memory first: the first tracker's last_req is not in
 * the cookie yet, because the session store waits for a beacon to be accepted
 * before it persists, and memory holds what this page load set.
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

        // is_new_session_start marks THIS EVENT as the one that created a new
        // session. It is a fact about the page load, not about which tracker
        // happened to derive it first, so it lives in the page store and both
        // trackers see it.
        expect(beacons.a[0].is_new_session_start).toBe(true);
        expect(beacons.b[0].is_new_session_start).toBe(true);
    });

    test('each site owns the session its facts point at', () => {
        /*
         * The session store is scoped to a site: the visitor is shared across
         * every site, and session state is held per site.
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
        a.setEventProperty('Plan', 'Pro');

        const b = new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });
        const beacons = [];
        b.logEvent = (p) => beacons.push({ ...p });
        b.trackPageView('https://example.com/pricing');

        // Named, not just absent: asserting on a key that exists nowhere would
        // pass whatever the tracker did.
        expect(beacons[0].eps_Plan).toBeUndefined();
        expect(a.getGlobalEventProperty('eps_Plan')).toBe('Pro');
    });

    test('VISITOR-scoped state still rides the events of both', () => {
        // The visitor is shared, so anything scoped to the visitor is shared with
        // it. user_id is the example: setUserId() writes the 'v' store, and every
        // tracker collects it from there. setUserProperty() is page-lifetime by
        // design and so is per-tracker rather than shared.
        const a = new OWATracker({ cookie_domain_set: true, site_id: 'site-a' });
        a.setUserId('u-shared');

        const b = new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });
        const beacons = [];
        b.logEvent = (p) => beacons.push({ ...p });
        b.trackPageView('https://example.com/pricing');

        expect(beacons[0].user_id).toBe('u-shared');
    });
});
