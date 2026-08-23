/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p"}
 */
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { StateManager } from '../../modules/Base/src/common/StateManager.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * The persisted-store format version, and the one question sniffing cannot ask.
 *
 * The reader decides a stored value's format by looking at it -- a leading '{'
 * means JSON, anything else means the older assoc encoding. That answers "what
 * does this look like", which is not the question that matters. The question is
 * "which version of this tracker wrote it", and no amount of looking at the
 * bytes can answer it, because two versions can write the same-looking bytes
 * with different meanings.
 *
 * That is not hypothetical here. The stored shape has already changed under
 * live visitors twice: assoc to JSON, and a single shared session store to a
 * per-site 's_<siteId>'. Both were survivable only because someone knew the
 * change was happening and hand-wrote a migration for it. A visitor arriving
 * with the old shape and no migration would have been read confidently and
 * wrong.
 *
 * The direction that has no answer at all is a value written by a NEWER
 * tracker. It happens without anyone deploying anything twice -- a visitor
 * meets an updated site, then a cached older bundle, or a release is rolled
 * back. Sniffing sees valid JSON and hands it over. The version marker is what
 * lets the reader decline.
 *
 * Declining means treating it as absent, which puts the tracker in the
 * first-time-visitor state it already handles correctly, rather than guessing
 * at a shape from the future.
 */

const SEEDED_VID = 'a-visitor-that-already-exists';

function coldPage() {
    OWA.initializeStateManager();
    ['v', 's', 's_version-site', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.persistenceReleased = {};
    OWA.state.cookies = Util.readAllCookies();
}

/*
 * Seed a raw visitor cookie the way a tracker of some other version would have
 * left it. `version` of null means the marker is absent entirely, which is what
 * every cookie in the wild looks like today.
 */
function seedVisitorCookie(version) {
    const value = { vid: SEEDED_VID, fsts: 1600000000, nps: 3 };
    if (OWA.getSetting('hashCookiesToDomain')) {
        value.cdh = Util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
    }
    if (version !== null) {
        value.sv = version;
    }
    Util.setCookie(OWA.getSetting('ns') + 'v', JSON.stringify(value), 364, '/',
        OWA.getSetting('cookie_domain'));
    OWA.state.cookies = Util.readAllCookies();
}

function pageView(t) {
    let beacon = null;
    t.logEvent = (p) => { beacon = { ...p }; };
    t.trackPageView(location.href);
    if (!beacon) { throw new Error('tracker did not emit a beacon'); }
    return beacon;
}

function seedRawCookie(store, value) {
    if (OWA.getSetting('hashCookiesToDomain') && !value.cdh) {
        value.cdh = Util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
    }
    Util.setCookie(OWA.getSetting('ns') + store, JSON.stringify(value), 364, '/',
        OWA.getSetting('cookie_domain'));
    OWA.state.cookies = Util.readAllCookies();
}

function rawCookie(store) {
    const name = OWA.getSetting('ns') + store;
    const all = Util.readAllCookies();
    let raw = all[name];
    while (Array.isArray(raw)) { raw = raw[0]; }
    if (!raw) { return undefined; }
    try {
        return JSON.parse(decodeURIComponent(raw));
    } catch (e) {
        return JSON.parse(raw);
    }
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('version-site');
    return t;
}

describe('persisted store format version', () => {

    beforeEach(() => {
        coldPage();
    });

    test('a store this tracker writes carries the version it was written by', () => {
        const beacon = pageView(newTracker());

        const stored = rawCookie('v');

        expect(stored).toBeDefined();
        expect(stored.sv).toBe(StateManager.STORE_VERSION);
        // The marker rides ALONGSIDE the data, not instead of it.
        expect(stored.vid).toBe(beacon.visitor_id);
    });

    test('an unmarked store is read, because that is every cookie in the wild', () => {
        seedVisitorCookie(null);
        newTracker();

        // Absent means "written before the marker existed" -- a real answer, and
        // the common one. If this ever fails, the marker has been made a
        // requirement rather than an improvement, and every existing visitor
        // silently becomes a new one.
        expect(OWA.getState('v', 'vid')).toBe(SEEDED_VID);
        expect(OWA.state.versionOf({})).toBe(0);
    });

    test('a store written by a NEWER tracker is refused, not guessed at', () => {
        seedVisitorCookie(StateManager.STORE_VERSION + 1);
        newTracker();

        // The whole point: this value is well-formed JSON with a plausible vid,
        // so sniffing would take it. The reader declines because the WRITER said
        // it is a shape this code has never been taught.
        expect(OWA.getState('v', 'vid')).toBeFalsy();
        expect(OWA.getState('v', 'nps')).toBeFalsy();
    });

    test('a store written by an older marked tracker is still read', () => {
        seedVisitorCookie(StateManager.STORE_VERSION);
        newTracker();

        expect(OWA.getState('v', 'vid')).toBe(SEEDED_VID);
    });

    test('a refused store leaves the tracker in the first-visit state, not a broken one', () => {
        seedVisitorCookie(StateManager.STORE_VERSION + 1);

        // Refusing has to be survivable. The tracker mints a fresh visitor
        // rather than carrying half of someone else's shape forward.
        const beacon = pageView(newTracker());

        expect(beacon.visitor_id).toBeTruthy();
        expect(beacon.visitor_id).not.toBe(SEEDED_VID);
        expect(beacon.is_new_visitor).toBeTruthy();
    });

    test('the marker never reaches the beacon as a tracking property', () => {
        const t = newTracker();
        pageView(t);
        OWA.setState('d', 'sv', 7);
        OWA.setState('d', 'page_type', 'article');

        const props = t.collectPageProperties();

        // Whatever else the 'd' store holds, the state manager's own bookkeeping
        // is not a dimension. Sending it would file an implementation detail in
        // the fact table under its own name.
        expect(props).not.toHaveProperty('sv');
        expect(props).not.toHaveProperty('cdh');
        // ...while an actual page-scoped property still gets through, so this
        // is testing an exclusion rather than an empty collector.
        expect(props.page_type).toBe('article');
    });

    test('a collapsed legacy store lands marked by the tracker that merged it', () => {
        const t = newTracker();
        const target = t.storeName('s');

        // The legacy store is read from the COOKIE, not from memory -- a
        // migration's whole job is to normalise what is already persisted.
        seedRawCookie('b', { cv1: 'plan=pro', sv: StateManager.STORE_VERSION });

        OWA.state.collapseLegacyStores();

        const merged = rawCookie(target);

        expect(merged).toBeDefined();
        expect(merged.cv1).toBe('plan=pro');
        // The version describes the WRITER. The store that came out of this
        // merge was written HERE, so it carries this tracker's marker, not
        // whatever the store it absorbed happened to be stamped with.
        expect(merged.sv).toBe(StateManager.STORE_VERSION);
        expect(OWA.state.canRead(merged)).toBe(true);
    });

    test('a legacy store from a newer tracker is not merged into the current one', () => {
        const t = newTracker();
        const target = t.storeName('s');

        seedRawCookie('b', { cv1: 'from-the-future', sv: StateManager.STORE_VERSION + 1 });

        OWA.state.collapseLegacyStores();

        // Refusing to READ a future store is also refusing to MIGRATE it, and
        // that falls out of the guard rather than needing its own branch --
        // which is the argument for putting the check at the read. A migration
        // is just a reader that writes, and a reader that cannot understand its
        // input has nothing to write.
        const merged = rawCookie(target);
        expect(merged === undefined || merged.cv1 === undefined).toBe(true);
    });
});

describe('version marker vacuity guards', () => {

    beforeEach(() => {
        coldPage();
    });

    test('versionOf reads what is there rather than assuming current', () => {
        // A test that seeds STORE_VERSION and asserts STORE_VERSION passes even
        // if versionOf() were hardcoded to return the current version. Pin it to
        // a value that is not the current one.
        expect(OWA.state.versionOf({ sv: 3 })).toBe(3);
        expect(OWA.state.versionOf({ sv: '3' })).toBe(3);
        expect(OWA.state.versionOf({})).toBe(0);
        expect(OWA.state.versionOf(null)).toBe(0);
        expect(OWA.state.versionOf({ sv: 'nonsense' })).toBe(0);
    });

    test('canRead draws the line at greater-than, not at not-equal', () => {
        // Older is readable; that is the difference between a version marker and
        // an exact-match check that would reject every existing visitor.
        expect(OWA.state.canRead({ sv: StateManager.STORE_VERSION - 1 })).toBe(true);
        expect(OWA.state.canRead({ sv: StateManager.STORE_VERSION })).toBe(true);
        expect(OWA.state.canRead({ sv: StateManager.STORE_VERSION + 1 })).toBe(false);
        expect(OWA.state.canRead({})).toBe(true);
    });
});
