import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Event } from '../../modules/Base/src/tracker/Event.js';

/**
 * Custom variables, sender side (setCustomVar / getCustomVar / deleteCustomVar).
 *
 * A custom variable is a name=value pair a site attaches to a slot (cv1..cvN,
 * maxCustomVars defaults to 5) so it rides along on the events the tracker
 * sends. The interesting part is its SCOPE, which decides how long the value
 * sticks to the visitor:
 *
 *   - page    -- lives only as a global event property on this tracker; it is
 *                not written to any persistent state store, so it vanishes when
 *                the page unloads.
 *   - session -- persisted in the session-scoped 'b' store, so it rides along on
 *                every event for the rest of the visit.
 *   - visitor -- persisted in the visitor-scoped 'v' store (long-lived), AND the
 *                session ('b') copy of that slot is cleared, so promoting a slot
 *                from session to visitor doesn't leave a stale session value
 *                that would shadow the visitor one on getCustomVar's lookup path.
 *
 * getCustomVar reads back in that shadowing order: page global -> session 'b'
 * -> visitor 'v' (first hit wins). deleteCustomVar clears the slot from all
 * three. addGlobalPropertiesToEvent pulls every set slot onto an outgoing event.
 *
 * The visitor-promotion clear exercises StateManager.clear(store, key), which
 * had a real bug -- `delete state['key']` deleted a literal "key" property
 * instead of the slot -- so before the fix a promoted var kept its session copy
 * and getCustomVar returned the STALE session value. The promotion test below
 * pins that.
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

// A tracker with cookie plumbing pinned so state writes are deterministic and
// hashing doesn't gate reads. cookie_domain_set skips the first-event auto
// domain detect.
function newTracker() {
    return new OWATracker({ cookie_domain_set: true, cookie_domain: '.cv.example' });
}

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    ['v', 's', 'c', 'b'].forEach(store => OWA.clearState(store));
});

afterEach(() => {
    ['v', 's', 'c', 'b'].forEach(store => OWA.clearState(store));
});

describe('setCustomVar scope: page', () => {

    test('sets a global event property but writes no persistent state store', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free', 'page');

        // Readable via getCustomVar (page global is the first lookup)...
        expect(t.getCustomVar(1)).toBe('Plan=Free');
        expect(t.getGlobalEventProperty('cv1')).toBe('Plan=Free');
        // ...but never persisted to the session or visitor stores.
        expect(OWA.getState('b', 'cv1')).toBeFalsy();
        expect(OWA.getState('v', 'cv1')).toBeFalsy();
    });

    test('an unknown scope behaves like page (global property only, no store)', () => {
        // The switch has no default, so anything that isn't session/visitor falls
        // through to just the setGlobalEventProperty at the tail.
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free');

        expect(t.getGlobalEventProperty('cv1')).toBe('Plan=Free');
        expect(OWA.getState('b', 'cv1')).toBeFalsy();
        expect(OWA.getState('v', 'cv1')).toBeFalsy();
    });
});

describe('setCustomVar scope: session', () => {

    test('persists the slot in the session (b) store', () => {
        const t = newTracker();
        t.setCustomVar(2, 'Tier', 'Silver', 'session');

        expect(OWA.getState('b', 'cv2')).toBe('Tier=Silver');
        // Session scope does not touch the visitor store.
        expect(OWA.getState('v', 'cv2')).toBeFalsy();
        expect(t.getCustomVar(2)).toBe('Tier=Silver');
    });
});

describe('setCustomVar scope: visitor', () => {

    test('persists the slot in the visitor (v) store', () => {
        const t = newTracker();
        t.setCustomVar(3, 'Tier', 'Gold', 'visitor');

        expect(OWA.getState('v', 'cv3')).toBe('Tier=Gold');
        expect(t.getCustomVar(3)).toBe('Tier=Gold');
    });

    test('promoting a slot from session to visitor clears the stale session copy', () => {
        // Regression for StateManager.clear(store, key): promoting a slot must
        // remove its session ('b') copy, otherwise getCustomVar -- which checks
        // 'b' BEFORE 'v' -- would keep returning the stale session value forever.
        const t = newTracker();
        t.setCustomVar(3, 'Tier', 'Gold', 'session');
        expect(OWA.getState('b', 'cv3')).toBe('Tier=Gold');

        t.setCustomVar(3, 'Tier', 'Platinum', 'visitor');

        expect(OWA.getState('v', 'cv3')).toBe('Tier=Platinum');
        // The session copy is gone, so the visitor value is what surfaces.
        expect(OWA.getState('b', 'cv3')).toBeFalsy();
    });
});

describe('getCustomVar lookup order (page -> session -> visitor)', () => {

    test('a page-level value shadows a persisted session value for the same slot', () => {
        const t = newTracker();
        // Seed a session value directly, then set a page-level global on the same
        // slot: getCustomVar checks the page global first.
        OWA.setState('b', 'cv4', 'Tier=FromSession');
        t.setGlobalEventProperty('cv4', 'Tier=FromPage');

        expect(t.getCustomVar(4)).toBe('Tier=FromPage');
    });

    test('a session value shadows a visitor value for the same slot', () => {
        const t = newTracker();
        OWA.setState('v', 'cv4', 'Tier=FromVisitor');
        OWA.setState('b', 'cv4', 'Tier=FromSession');

        // 'b' is checked before 'v'.
        expect(t.getCustomVar(4)).toBe('Tier=FromSession');
    });

    test('returns empty for a slot that was never set', () => {
        const t = newTracker();
        expect(t.getCustomVar(5)).toBeFalsy();
    });
});

describe('deleteCustomVar', () => {

    test('clears the slot from page, session, and visitor scopes', () => {
        const t = newTracker();
        t.setGlobalEventProperty('cv2', 'Plan=Page');
        OWA.setState('b', 'cv2', 'Plan=Session');
        OWA.setState('v', 'cv2', 'Plan=Visitor');

        t.deleteCustomVar(2);

        expect(t.getGlobalEventProperty('cv2')).toBeFalsy();
        expect(OWA.getState('b', 'cv2')).toBeFalsy();
        expect(OWA.getState('v', 'cv2')).toBeFalsy();
        expect(t.getCustomVar(2)).toBeFalsy();
    });
});

describe('setCustomVar length guard', () => {

    test('drops a name=value pair longer than 65 characters', () => {
        const t = newTracker();
        // name=value string length must be <= 65; build one that is 66.
        const bigValue = 'x'.repeat(66 - 'Big='.length + 1); // pushes total to 66
        const pair = 'Big=' + bigValue;
        expect(pair.length).toBeGreaterThan(65);

        t.setCustomVar(1, 'Big', bigValue, 'session');

        // Nothing was stored anywhere -- the method returned before the switch.
        expect(t.getGlobalEventProperty('cv1')).toBeFalsy();
        expect(OWA.getState('b', 'cv1')).toBeFalsy();
    });

    test('keeps a name=value pair at the boundary length', () => {
        const t = newTracker();
        // Exactly 65 chars is allowed (guard is strictly > 65).
        const value = 'y'.repeat(65 - 'Ok='.length);
        const pair = 'Ok=' + value;
        expect(pair.length).toBe(65);

        t.setCustomVar(1, 'Ok', value, 'session');
        expect(OWA.getState('b', 'cv1')).toBe(pair);
    });
});

describe('addGlobalPropertiesToEvent pulls stored custom vars onto an event', () => {

    test('copies set slots (across scopes) onto an outgoing event', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Pro', 'page');
        t.setCustomVar(2, 'Tier', 'Gold', 'session');
        t.setCustomVar(3, 'Cohort', 'Beta', 'visitor');

        const event = new Event();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('cv1')).toBe('Plan=Pro');
        expect(event.get('cv2')).toBe('Tier=Gold');
        expect(event.get('cv3')).toBe('Cohort=Beta');
    });

    test('does not overwrite a custom var already set locally on the event', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Global', 'session');

        const event = new Event();
        event.set('cv1', 'Plan=Local');
        t.addGlobalPropertiesToEvent(event);

        // A property already on the event wins over the global.
        expect(event.get('cv1')).toBe('Plan=Local');
    });
});
