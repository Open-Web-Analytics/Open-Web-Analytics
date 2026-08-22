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
 * and the network it belongs to. They deliberately SHARE the state stores
 * (sharableStateStores), so the visitor and the session are the visitor's and
 * the session's, not each tracker's private copy.
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
    const a = new OWATracker({ cookie_domain_set: true });
    a.setSiteId('site-a');
    const b = new OWATracker({ cookie_domain_set: true });
    b.setSiteId('site-b');

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

    test('they report ONE session, not one each', () => {
        const { beacons } = trackBoth();

        expect(beacons.a[0].session_id).toBeTruthy();
        expect(beacons.b[0].session_id).toBe(beacons.a[0].session_id);
    });

    test('only the first of them starts that session', () => {
        const { beacons } = trackBoth();

        expect(beacons.a[0].is_new_session).toBe(true);
        // The second continues it. Two new-session flags for one page view would
        // have the server create the session twice.
        expect(beacons.b[0].is_new_session).toBeFalsy();
    });

    test('they report one visitor', () => {
        const { beacons } = trackBoth();

        expect(beacons.a[0].visitor_id).toBeTruthy();
        expect(beacons.b[0].visitor_id).toBe(beacons.a[0].visitor_id);
    });

    test('the second does not overwrite the first session id in the shared store', () => {
        const { beacons } = trackBoth();

        expect(OWA.getState('s', 'sid')).toBe(beacons.a[0].session_id);
    });

    test('a custom variable set on one rides the events of both', () => {
        // Custom vars come off the shared stores now rather than from a global
        // property private to the tracker that set them, so the second tracker
        // sees a session-scoped variable the first one set.
        const a = new OWATracker({ cookie_domain_set: true });
        a.setSiteId('site-a');
        a.setCustomVar(1, 'Plan', 'Pro', 'session');

        const b = new OWATracker({ cookie_domain_set: true });
        b.setSiteId('site-b');
        const beacons = [];
        b.logEvent = (p) => beacons.push({ ...p });
        b.trackPageView('https://example.com/pricing');

        expect(beacons[0].cv1).toBe('Plan=Pro');
    });
});
