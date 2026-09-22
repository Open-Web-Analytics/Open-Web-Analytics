import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * setEventProperty / setUserProperty, and what setCustomVar became.
 *
 * Two scopes, and scope lives in the NAME -- `ep_` for a value describing the
 * event, `up_` for one describing the visitor. Nothing downstream has to infer
 * which bag a value belongs to, and the same name in two scopes is two
 * different things all the way to the column. GA does the same with `ep.` and
 * `up.`, which exercising their tracker confirmed.
 *
 * Both are PAGE-LIFETIME and in memory. Neither writes a cookie, which is the
 * deliberate break from v1: a visitor-scoped custom variable persisted in the
 * 'v' store and was re-sent on every later beacon, so a value could outlive the
 * thing it described. What persists now is a server record written at ingest.
 *
 * setCustomVar's three scopes collapse onto the two. Session maps to EVENT,
 * which looks like a loss and is not: a session-scoped value carried by the
 * client is something the server can derive from the session's own events and
 * something the client can get wrong -- v1's own session store kept a variable
 * past the session that set it, because nothing cleared it at the boundary.
 */
describe('custom property setters', () => {

    let tracker;

    beforeEach(() => {
        OWA.initializeStateManager();
        OWA.state.stores = {};
        tracker = new OWATracker({ cookie_domain_set: true, site_id: 'prop-site' });
    });

    test('setEventProperty prefixes the name with ep_', () => {
        tracker.setEventProperty('coupon_code', 'SPRING');

        expect(tracker.getGlobalEventProperty('ep_coupon_code')).toBe('SPRING');
        expect(tracker.getGlobalEventProperty('coupon_code')).toBeUndefined();
    });

    test('setUserProperty prefixes the name with up_', () => {
        tracker.setUserProperty('plan', 'enterprise');

        expect(tracker.getGlobalEventProperty('up_plan')).toBe('enterprise');
        expect(tracker.getGlobalEventProperty('plan')).toBeUndefined();
    });

    test('the two scopes do not collide on the same name', () => {
        tracker.setEventProperty('tier', 'event-value');
        tracker.setUserProperty('tier', 'user-value');

        expect(tracker.getGlobalEventProperty('ep_tier')).toBe('event-value');
        expect(tracker.getGlobalEventProperty('up_tier')).toBe('user-value');
    });

    test('neither writes a cookie: both are page-lifetime, as GA is', () => {
        tracker.setEventProperty('a', '1');
        tracker.setUserProperty('b', '2');

        expect(OWA.getPersistedState('v', 'up_b')).toBeFalsy();
        expect(OWA.getPersistedState('s', 'ep_a')).toBeFalsy();
        expect(OWA.getPersistedState('v', 'b')).toBeFalsy();
    });

    test('a name that could not become a column is refused', () => {
        tracker.setEventProperty('has space', 'x');
        tracker.setEventProperty('9leading', 'x');
        tracker.setEventProperty('has-hyphen', 'x');
        tracker.setUserProperty('a'.repeat(41), 'x');

        expect(tracker.getGlobalEventProperty('ep_has space')).toBeUndefined();
        expect(tracker.getGlobalEventProperty('ep_9leading')).toBeUndefined();
        expect(tracker.getGlobalEventProperty('ep_has-hyphen')).toBeUndefined();
        expect(tracker.getGlobalEventProperty('up_' + 'a'.repeat(41))).toBeUndefined();

        // And the boundary is allowed, so the guard is a limit not a ban.
        tracker.setUserProperty('a'.repeat(40), 'ok');
        expect(tracker.getGlobalEventProperty('up_' + 'a'.repeat(40))).toBe('ok');
    });

    describe('setCustomVar maps onto the two', () => {

        test('page scope becomes an event property', () => {
            tracker.setCustomVar(1, 'colour', 'blue', 'page');

            expect(tracker.getGlobalEventProperty('ep_colour')).toBe('blue');
        });

        test('SESSION scope becomes an event property, not a session one', () => {
            tracker.setCustomVar(2, 'plan', 'pro', 'session');

            expect(tracker.getGlobalEventProperty('ep_plan')).toBe('pro');
            expect(tracker.getGlobalEventProperty('up_plan')).toBeUndefined();
            expect(OWA.getPersistedState('s', 'cv2')).toBeFalsy();
        });

        test('visitor scope becomes a user property', () => {
            tracker.setCustomVar(3, 'cohort', 'beta', 'visitor');

            expect(tracker.getGlobalEventProperty('up_cohort')).toBe('beta');
            expect(tracker.getGlobalEventProperty('ep_cohort')).toBeUndefined();
        });

        test('an absent or unknown scope is an event property, as page was the default', () => {
            tracker.setCustomVar(4, 'x', '1');
            tracker.setCustomVar(5, 'y', '2', 'nonsense');

            expect(tracker.getGlobalEventProperty('ep_x')).toBe('1');
            expect(tracker.getGlobalEventProperty('ep_y')).toBe('2');
        });

        test('the slot is ignored: the same name is the same property', () => {
            tracker.setCustomVar(1, 'plan', 'first', 'page');
            tracker.setCustomVar(4, 'plan', 'second', 'page');

            expect(tracker.getGlobalEventProperty('ep_plan')).toBe('second');
            expect(tracker.getGlobalEventProperty('ep_plan_4')).toBeUndefined();
        });
    });
});
