import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OwaEvent } from '../../modules/Base/src/tracker/OwaEvent.js';

/**
 * Assorted tracker helpers: URL/anchor param readers, page-property
 * convenience setters, lifecycle (pause/restart), the thirdParty campaign
 * promotion, and the manageState one-shot guard.
 *
 * The headline case here is getUrlParam(), which had a real shipping bug: the
 * constructor seeds this.urlParams to {} (truthy), so the old
 * `this.urlParams || parseUrlParams()` guard always short-circuited to that
 * empty object and NEVER parsed the URL -- getUrlParam returned false for every
 * query param. That silently broke the `?owa_state=` query form of cross-domain
 * state linking (checkForLinkedState), leaving only the `#owa_state` anchor
 * fallback working. The fix parses when the cache is genuinely empty.
 *
 * getAnchorParam parses the fragment's `key.value,key.value` format;
 * getUrlAnchorValue returns the raw fragment.
 *
 * setPageTitle / setPageType / setUserName just trim and stash a global event
 * property. pause/restart flip `active`; isPausedBySibling reflects the shared
 * loggerPause setting. setCampaignRelatedProperties (thirdParty mode) promotes
 * campaign params from the URL to their full-name globals for upstream.
 * manageState runs the identity pipeline exactly once (stateInit guard).
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

function setUrl(pathAndQuery) {
    window.history.replaceState({}, '', pathAndQuery);
}

function newTracker(options) {
    const t = new OWATracker(Object.assign(
        { cookie_domain_set: true, cookie_domain: '.cv.example' },
        options || {}
    ));
    t.setSiteId('misc-site');
    return t;
}

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    OWA.setSetting('loggerPause', false);
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
});

afterEach(() => {
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    setUrl('/');
});

describe('getUrlParam', () => {

    test('reads a query param off the current URL (regression: used to always return false)', () => {
        setUrl('/p?foo=bar&baz=qux');
        const t = newTracker();

        // The constructor seeds urlParams to {}; getUrlParam must still parse the
        // URL rather than short-circuit on the truthy-but-empty cache.
        expect(t.getUrlParam('foo')).toBe('bar');
        expect(t.getUrlParam('baz')).toBe('qux');
    });

    test('finds the owa_state cross-domain linking token on the query string', () => {
        setUrl('/landing?owa_state=abc123');
        const t = newTracker();

        // This is the read checkForLinkedState() depends on for the ?owa_state=
        // (non-anchor) form of shared state.
        expect(t.getUrlParam('owa_state')).toBe('abc123');
    });

    test('returns false for a param that is not present', () => {
        setUrl('/p?foo=bar');
        const t = newTracker();

        expect(t.getUrlParam('nope')).toBe(false);
    });
});

describe('getUrlAnchorValue / getAnchorParam', () => {

    test('getUrlAnchorValue returns the raw fragment', () => {
        setUrl('/p#a.1,b.2');
        const t = newTracker();

        expect(t.getUrlAnchorValue()).toBe('a.1,b.2');
    });

    test('getAnchorParam parses the key.value,key.value fragment format', () => {
        setUrl('/p#a.1,b.2');
        const t = newTracker();

        expect(t.getAnchorParam('a')).toBe('1');
        expect(t.getAnchorParam('b')).toBe('2');
    });

    test('getAnchorParam returns undefined for an absent key', () => {
        setUrl('/p#a.1');
        const t = newTracker();

        expect(t.getAnchorParam('z')).toBeUndefined();
    });
});

describe('page-property convenience setters', () => {

    /*
     * These used to write a global event property, private to the tracker that
     * was called. A page title is a fact about the PAGE and an identified user
     * is a fact about the VISITOR -- neither is a fact about one tracker -- so a
     * site that called the setter once had only one of the trackers on the page
     * reporting it.
     */

    test('setPageTitle trims and stores it page-scoped', () => {
        const t = newTracker();
        t.setPageTitle('  Home  ');

        expect(OWA.getState('d', 'page_title')).toBe('Home');
        expect(t.getGlobalEventProperty('page_title')).toBeFalsy();
    });

    test('setPageType trims and stores it page-scoped', () => {
        const t = newTracker();
        t.setPageType('  article ');

        expect(OWA.getState('d', 'page_type')).toBe('article');
    });

    test('setUserName trims and stores it visitor-scoped', () => {
        // An identified user outlives the page and the session. Note this now
        // reaches the visitor COOKIE, which a global event property never did.
        const t = newTracker();
        t.setUserName(' bob ');

        expect(OWA.getState('v', 'user_name')).toBe('bob');
    });

    test('a second tracker on the page reports what the first one was told', () => {
        // The reason for the move, stated as a test.
        const first = newTracker();
        first.setPageTitle('Pricing');
        first.setPageType('landing');
        first.setUserName('bob');

        const second = newTracker();
        const event = new OwaEvent();
        second.addGlobalPropertiesToEvent(event);

        expect(event.get('page_title')).toBe('Pricing');
        expect(event.get('page_type')).toBe('landing');
        expect(event.get('user_name')).toBe('bob');
    });

    test('the page store overrides the DOM, which is the base layer', () => {
        document.title = 'From The DOM';
        const t = newTracker();

        const before = new OwaEvent();
        t.addGlobalPropertiesToEvent(before);
        expect(before.get('page_title')).toBe('From The DOM');

        t.setPageTitle('From The Site');

        const after = new OwaEvent();
        t.addGlobalPropertiesToEvent(after);
        expect(after.get('page_title')).toBe('From The Site');
    });
});

describe('lifecycle: pause / restart / isPausedBySibling', () => {

    test('pause deactivates and restart reactivates the tracker', () => {
        const t = newTracker();
        expect(t.active).toBe(true);

        t.pause();
        expect(t.active).toBe(false);

        t.restart();
        expect(t.active).toBe(true);
    });

    test('isPausedBySibling reflects the shared loggerPause setting', () => {
        const t = newTracker();
        expect(t.isPausedBySibling()).toBeFalsy();

        OWA.setSetting('loggerPause', true);
        expect(t.isPausedBySibling()).toBe(true);
    });
});

describe('setCampaignRelatedProperties (thirdParty promotion)', () => {

    test('promotes campaign params from the URL to their full-name globals', () => {
        setUrl('/p?owa_source=news&owa_medium=email');
        const t = newTracker();

        t.setCampaignRelatedProperties(new OwaEvent());

        // Upstream reads the full-name globals; the private-key map resolves
        // owa_source -> source, owa_medium -> medium.
        expect(t.getGlobalEventProperty('source')).toBe('news');
        expect(t.getGlobalEventProperty('medium')).toBe('email');
    });
});

describe('state-derived properties are collected onto each event', () => {

    /*
     * These were derived once in manageState() and cached as global event
     * properties on the tracker. The cache was never wrong -- every branch that
     * set it wrote the same value to the store -- but it was a private second
     * copy of something the stores already own and every tracker on the page
     * shares.
     */

    test('visitor identity and counters come off the stores', () => {
        const t = newTracker();
        OWA.setState('v', 'vid', 'vid-123');
        OWA.setState('v', 'fsts', 1600000000);
        OWA.setState('v', 'dsfs', 12);
        OWA.setState('v', 'nps', '4');

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('visitor_id')).toBe('vid-123');
        expect(event.get('fsts')).toBe(1600000000);
        expect(event.get('dsfs')).toBe(12);
        expect(event.get('nps')).toBe('4');
    });

    test('a zero value is collected rather than dropped', () => {
        // 0 is legitimate and falsy, so a truthiness guard would silently drop
        // it -- dsfs is 0 on a visitor's first day -- and these are in the
        // beacon contract, so absence is not an option.
        const t = newTracker();
        OWA.setState('v', 'dsfs', 0);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('dsfs')).toBe(0);
    });

    test('nps of "0" survives, being a first session rather than an absent one', () => {
        const t = newTracker();
        OWA.setState('v', 'nps', '0');

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('nps')).toBe('0');
    });

    test('a second tracker reports the same identity as the first', () => {
        const first = newTracker();
        OWA.setState('v', 'vid', 'shared-vid');

        const second = newTracker();
        const event = new OwaEvent();
        second.addGlobalPropertiesToEvent(event);

        expect(event.get('visitor_id')).toBe('shared-vid');
    });
});

describe('manageState one-shot guard', () => {

    test('runs the identity pipeline once and sets stateInit', () => {
        const t = newTracker();
        const event = new OwaEvent();
        event.set('timestamp', 1700000000);

        t.manageState(event, null);

        expect(t.stateInit).toBe(true);
        expect(OWA.getState('v', 'vid')).toBeTruthy();
    });

    test('does not re-run once stateInit is true', () => {
        const t = newTracker();
        const event = new OwaEvent();
        event.set('timestamp', 1700000000);

        // Simulate a prior run and plant a sentinel the pipeline would overwrite.
        t.stateInit = true;
        OWA.setState('v', 'vid', 'SENTINEL');

        t.manageState(event, null);

        // Guard held: the pipeline was skipped, sentinel untouched.
        expect(OWA.getState('v', 'vid')).toBe('SENTINEL');
    });
});
