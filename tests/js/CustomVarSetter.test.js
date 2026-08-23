import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OwaEvent } from '../../modules/Base/src/tracker/OwaEvent.js';

/**
 * Custom variables, sender side (setCustomVar / getCustomVar / deleteCustomVar).
 *
 * A custom variable is a name=value pair a site attaches to a slot (cv1..cvN,
 * maxCustomVars defaults to 5) so it rides along on the events the tracker
 * sends. The interesting part is its SCOPE, which decides how long the value
 * sticks to the visitor:
 *
 *   - page    -- the 'd' store, which is memory only for the life of the page,
 *                plus a global event property on this tracker. It is never
 *                written to a cookie, so it vanishes when the page unloads.
 *   - session -- the session store 's', so it rides along on every event for the
 *                rest of the visit. Held in memory until the session is settled
 *                and accepted; see CustomVarStorage.test.js.
 *   - visitor -- the visitor store 'v' (long-lived), AND the narrower copies of
 *                that slot are cleared, so promoting a slot from session to
 *                visitor doesn't leave a stale value that would shadow the
 *                visitor one on getCustomVar's lookup path.
 *
 * getCustomVar reads back in shadowing order: page global -> 'd' -> 's' -> 'b'
 * (legacy) -> 'v', first hit wins. The scoped getters -- getCustomPageVar,
 * getCustomSessionVar, getCustomVisitorVar -- read one scope each and do not
 * shadow, which is the point of having them. deleteCustomVar clears the slot
 * from every scope. addGlobalPropertiesToEvent pulls every set slot onto an
 * outgoing event.
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
    ['v', 's', 'c', 'b', 'd'].forEach(store => OWA.clearState(store));
});

afterEach(() => {
    ['v', 's', 'c', 'b', 'd'].forEach(store => OWA.clearState(store));
});

describe('setCustomVar scope: page', () => {

    test('writes no global event property -- the store is the only copy', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free', 'page');

        expect(t.getCustomVar(1)).toBe('Plan=Free');
        // setCustomVar used to cache the value here as well. A cached copy is a
        // second source of truth: it went stale when the slot was re-scoped,
        // and it hid the store from any reader that was not the tracker that
        // made the call.
        expect(t.getGlobalEventProperty('cv1')).toBeFalsy();
        // ...and it is never persisted to the session or visitor stores.
        expect(OWA.getState('s', 'cv1')).toBeFalsy();
        expect(OWA.getState('v', 'cv1')).toBeFalsy();
    });

    test('writes the slot to the page store', () => {
        // Page scope used to be the ABSENCE of a case in setCustomVar(): the
        // value fell through to the global event property below the switch and
        // happened to work. It has a store of its own now, and without this
        // assertion the whole branch can be deleted with every test still
        // passing -- the global property alone would carry it.
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free', 'page');

        expect(OWA.getState('d', 'cv1')).toBe('Plan=Free');
    });

    test('a page-scoped value reaches an event without the setter tracker present', () => {
        // The 'd' store only does work when the global event property is
        // absent, because setCustomVar() sets both. A second tracker on the
        // page is that case -- and it is the case that proves the store is
        // carrying the value rather than the global property.
        const first = newTracker();
        first.setCustomVar(1, 'Plan', 'Free', 'page');

        const second = newTracker();
        const event = new OwaEvent();
        second.addGlobalPropertiesToEvent(event);

        expect(event.get('cv1')).toBe('Plan=Free');
    });

    test('the page store outlives the tracker that set it, but not the page', () => {
        // 'd' is per-page, not per-tracker: a second tracker on the same page
        // sees it, which a global event property on the first tracker would not
        // give you. Nothing persists it, so the next page load starts empty.
        const first = newTracker();
        first.setCustomVar(1, 'Plan', 'Free', 'page');

        const second = newTracker();

        expect(second.getCustomPageVar(1)).toBe('Plan=Free');
        expect(OWA.state.behaviourOf('d').persist).toBe('never');
    });

    test('an absent or unrecognised scope behaves like page', () => {
        // Page is the switch's default. It used to be its FALLTHROUGH -- there
        // was no 'page' case, so anything unrecognised landed on the global
        // event property at the tail and nowhere else. With that tail gone the
        // default has to be explicit, or an unscoped call would silently do
        // nothing at all.
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free');
        t.setCustomVar(2, 'Tier', 'Gold', 'nonsense');

        expect(OWA.getState('d', 'cv1')).toBe('Plan=Free');
        expect(OWA.getState('d', 'cv2')).toBe('Tier=Gold');
        expect(OWA.getState('s', 'cv1')).toBeFalsy();
        expect(OWA.getState('v', 'cv1')).toBeFalsy();
    });
});

describe('setCustomVar scope: session', () => {

    test('persists the slot in the session (s) store', () => {
        const t = newTracker();
        t.setCustomVar(2, 'Tier', 'Silver', 'session');

        expect(OWA.getState('s', 'cv2')).toBe('Tier=Silver');
        // Session scope does not touch the visitor store.
        expect(OWA.getState('v', 'cv2')).toBeFalsy();
        expect(t.getCustomVar(2)).toBe('Tier=Silver');
    });
});

describe('custom vars are collected from the stores for each event', () => {

    test('applied widest scope first, so page wins the slot', () => {
        const t = newTracker();
        OWA.setState('v', 'cv1', 'From=Visitor');
        OWA.setState('s', 'cv1', 'From=Session');
        OWA.setState('d', 'cv1', 'From=Page');

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('cv1')).toBe('From=Page');
    });

    test('session beats visitor when there is no page value', () => {
        const t = newTracker();
        OWA.setState('v', 'cv1', 'From=Visitor');
        OWA.setState('s', 'cv1', 'From=Session');

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('cv1')).toBe('From=Session');
    });

    test('slots from different scopes all ride the same event', () => {
        const t = newTracker();
        OWA.setState('v', 'cv1', 'Cohort=Beta');
        OWA.setState('s', 'cv2', 'Tier=Gold');
        OWA.setState('d', 'cv3', 'Plan=Free');

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('cv1')).toBe('Cohort=Beta');
        expect(event.get('cv2')).toBe('Tier=Gold');
        expect(event.get('cv3')).toBe('Plan=Free');
    });

    /**
     * The drift the old cache caused, pinned. setCustomVar wrote the value to a
     * global event property, and the rehydration loop skipped any slot that
     * already had one -- so a slot re-scoped mid-page kept sending whatever the
     * first call had cached.
     */
    test('a slot re-scoped mid-page takes effect on the next event', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free', 'page');

        const first = new OwaEvent();
        t.addGlobalPropertiesToEvent(first);
        expect(first.get('cv1')).toBe('Plan=Free');

        t.setCustomVar(1, 'Plan', 'Pro', 'visitor');

        const second = new OwaEvent();
        t.addGlobalPropertiesToEvent(second);
        expect(second.get('cv1')).toBe('Plan=Pro');
    });

    test('a deleted slot stops riding events', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Free', 'session');
        t.deleteCustomVar(1);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.isSet('cv1')).toBeFalsy();
    });
});

describe('re-scoping a slot', () => {

    /*
     * A slot lives in exactly one scope's store. Promoting it upwards clears
     * the narrower copy; overriding it downwards does not, because a
     * page-scoped value set over a session one is a deliberate per-page
     * override of something longer-lived, not a promotion.
     *
     * Without the clear, the superseded copy SHADOWS the new value for any
     * reader that does not have the setting tracker's global event property --
     * getCustomVar() checks 'd' before 's'. Two trackers on one page then
     * disagree about the same slot.
     */

    test('page -> session clears the page copy', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'PageValue', 'page');
        t.setCustomVar(1, 'Plan', 'SessionValue', 'session');

        expect(OWA.getState('d', 'cv1')).toBeFalsy();
        expect(OWA.getState('s', 'cv1')).toBe('Plan=SessionValue');
    });

    test('...so a second tracker on the page agrees with the first', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'PageValue', 'page');
        t.setCustomVar(1, 'Plan', 'SessionValue', 'session');

        const other = newTracker();

        expect(other.getCustomVar(1)).toBe('Plan=SessionValue');
        expect(t.getCustomVar(1)).toBe('Plan=SessionValue');
    });

    test('page -> visitor clears both narrower copies', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'PageValue', 'page');
        t.setCustomVar(1, 'Plan', 'SessionValue', 'session');
        t.setCustomVar(1, 'Plan', 'VisitorValue', 'visitor');

        expect(OWA.getState('d', 'cv1')).toBeFalsy();
        expect(OWA.getState('s', 'cv1')).toBeFalsy();
        expect(OWA.getState('v', 'cv1')).toBe('Plan=VisitorValue');
    });

    test('session -> page does NOT clear the session copy: it is an override', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'SessionValue', 'session');
        t.setCustomVar(1, 'Plan', 'PageValue', 'page');

        // the page value wins for this page...
        expect(t.getCustomVar(1)).toBe('Plan=PageValue');
        // ...and the session value is still there for the next one
        expect(OWA.getState('s', 'cv1')).toBe('Plan=SessionValue');
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
        expect(OWA.getState('s', 'cv3')).toBe('Tier=Gold');

        t.setCustomVar(3, 'Tier', 'Platinum', 'visitor');

        expect(OWA.getState('v', 'cv3')).toBe('Tier=Platinum');
        // The session copy is gone, so the visitor value is what surfaces.
        expect(OWA.getState('s', 'cv3')).toBeFalsy();
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
        OWA.setState('s', 'cv4', 'Tier=FromSession');

        // 's' is checked before 'v'.
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
        OWA.setState('d', 'cv2', 'Plan=Page');
        OWA.setState('s', 'cv2', 'Plan=Session');
        OWA.setState('b', 'cv2', 'Plan=Legacy');
        OWA.setState('v', 'cv2', 'Plan=Visitor');

        t.deleteCustomVar(2);

        expect(t.getGlobalEventProperty('cv2')).toBeFalsy();
        expect(OWA.getState('d', 'cv2')).toBeFalsy();
        expect(OWA.getState('s', 'cv2')).toBeFalsy();
        expect(OWA.getState('b', 'cv2')).toBeFalsy();
        expect(OWA.getState('v', 'cv2')).toBeFalsy();
        expect(t.getCustomVar(2)).toBeFalsy();
    });
});

describe('the scoped getters read one scope each', () => {

    /*
     * getCustomVar answers "what is this slot's effective value", collapsing the
     * scopes in shadowing order. That is the right answer to a question the
     * caller often is not asking: a site that set a visitor-scoped variable and
     * wants to know whether it is still there gets told about a page-scoped one
     * instead, with no way to tell the difference.
     */

    function setAllThree(t) {
        t.setCustomVar(1, 'Plan', 'Page', 'page');
        OWA.setState('s', 'cv1', 'Plan=Session');
        OWA.setState('v', 'cv1', 'Plan=Visitor');
        return t;
    }

    test('each getter answers for its own scope on the same slot', () => {
        const t = setAllThree(newTracker());

        expect(t.getCustomPageVar(1)).toBe('Plan=Page');
        expect(t.getCustomSessionVar(1)).toBe('Plan=Session');
        expect(t.getCustomVisitorVar(1)).toBe('Plan=Visitor');
    });

    test('while getCustomVar still collapses them to the narrowest', () => {
        const t = setAllThree(newTracker());

        expect(t.getCustomVar(1)).toBe('Plan=Page');
    });

    test('a getter does not see a value set in another scope', () => {
        const t = newTracker();
        OWA.setState('s', 'cv2', 'Plan=Session');

        expect(t.getCustomPageVar(2)).toBeFalsy();
        expect(t.getCustomVisitorVar(2)).toBeFalsy();
        expect(t.getCustomSessionVar(2)).toBe('Plan=Session');
    });

    test('getCustomSessionVar does not read the legacy store', () => {
        // There is no fallback to 'b', on purpose. The collapse migration moves
        // its values into 's' before anything reads, so a fallback would be
        // dead weight afterwards -- and before it, it would hand back a
        // previous session's value with none of the settling that decides
        // whether that value still applies, which is exactly what this method's
        // contract rules out.
        //
        // The window is real rather than theoretical: on a real page the
        // migration runs on the FIRST TRACKED EVENT, because the cookie domain
        // is not known before then. CommandQueue constructs the tracker without
        // cookie_domain_set, so nothing settles the domain until trackEvent().
        // A site calling this before trackPageView() would otherwise be told
        // about a variable from a session that had already ended.
        //
        // The migration itself is covered in CustomVarStorage.test.js.
        const t = newTracker();
        OWA.setState('b', 'cv3', 'legacy=value');

        expect(t.getCustomSessionVar(3)).toBeFalsy();
    });

    test('every getter returns falsy for a slot that was never set', () => {
        const t = newTracker();

        expect(t.getCustomPageVar(5)).toBeFalsy();
        expect(t.getCustomSessionVar(5)).toBeFalsy();
        expect(t.getCustomVisitorVar(5)).toBeFalsy();
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
        expect(OWA.getState('s', 'cv1')).toBeFalsy();
    });

    test('keeps a name=value pair at the boundary length', () => {
        const t = newTracker();
        // Exactly 65 chars is allowed (guard is strictly > 65).
        const value = 'y'.repeat(65 - 'Ok='.length);
        const pair = 'Ok=' + value;
        expect(pair.length).toBe(65);

        t.setCustomVar(1, 'Ok', value, 'session');
        expect(OWA.getState('s', 'cv1')).toBe(pair);
    });
});

describe('addGlobalPropertiesToEvent pulls stored custom vars onto an event', () => {

    test('copies set slots (across scopes) onto an outgoing event', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Pro', 'page');
        t.setCustomVar(2, 'Tier', 'Gold', 'session');
        t.setCustomVar(3, 'Cohort', 'Beta', 'visitor');

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('cv1')).toBe('Plan=Pro');
        expect(event.get('cv2')).toBe('Tier=Gold');
        expect(event.get('cv3')).toBe('Cohort=Beta');
    });

    test('does not overwrite a custom var already set locally on the event', () => {
        const t = newTracker();
        t.setCustomVar(1, 'Plan', 'Global', 'session');

        const event = new OwaEvent();
        event.set('cv1', 'Plan=Local');
        t.addGlobalPropertiesToEvent(event);

        // A property already on the event wins over the global.
        expect(event.get('cv1')).toBe('Plan=Local');
    });
});
