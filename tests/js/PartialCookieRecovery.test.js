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
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * One OWA cookie survives and the other does not.
 *
 * Visitor state ('v', ~1yr) and session state ('s') are separate cookies with
 * very different lifetimes, and a browser, an extension or a person can remove
 * either one without touching the other. Neither is evidence about the other:
 *
 *   - a visitor whose session cookie is gone is NOT a new visitor. They are a
 *     known visitor starting a new session, and counting them as new would
 *     inflate unique visitors by one for every expired or cleared session.
 *   - a session whose visitor cookie is gone is still a running session. The
 *     visitor cannot be identified any more, but that is no reason to also
 *     tear down a session the server already knows about.
 *
 * This matters more since the session store stopped hydrating on first touch:
 * the session decision now depends on reading the persisted 's' store at a
 * specific moment, and a fixture that seeds both cookies together would never
 * exercise the case where only one is there. Nothing else in the suite covers
 * either half-cleared state.
 *
 * These drive the real trackPageView pipeline so the whole chain is exercised
 * in order -- setVisitorId, setLastRequestTime, setSessionId -- rather than the
 * individual methods in isolation.
 */

const SESSION_SID = 'surviving-session-id';
const VISITOR_VID = 'surviving-visitor-id';

function eraseEverything() {
    OWA.initializeStateManager();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.sessionPersistenceReady = false;
    OWA.state.cookies = Util.readAllCookies();
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('partial-cookie-site');
    return t;
}

/**
 * Write one or both cookies as a previous page load would have left them.
 * Written to the real jar, not the state manager's cache: setVisitorId() calls
 * clearState('v'), which refreshes that cache mid-chain and would drop a
 * cache-level seed.
 */
function seed({ visitor = false, session = false } = {}) {
    const now = Util.getCurrentUnixTimestamp();
    const cdh = OWA.getSetting('hashCookiesToDomain')
        ? Util.getCookieDomainHash(OWA.getSetting('cookie_domain'))
        : undefined;
    const ns = OWA.getSetting('ns');
    const domain = OWA.getSetting('cookie_domain');

    if (visitor) {
        const v = { vid: VISITOR_VID, fsts: now - (3600 * 24 * 30), nps: 4 };
        if (cdh) { v.cdh = cdh; }
        Util.setCookie(ns + 'v', JSON.stringify(v), 364, '/', domain);
    }
    if (session) {
        const s = { sid: SESSION_SID, last_req: now, cv1: 'plan=pro' };
        if (cdh) { s.cdh = cdh; }
        Util.setCookie(ns + 's', JSON.stringify(s), 1, '/', domain);
    }
    OWA.state.cookies = Util.readAllCookies();
}

/** Run a pageview through the real pipeline and return the beacon. */
function pageView(t) {
    let beacon = null;
    t.logEvent = (p) => { beacon = { ...p }; };
    t.trackPageView(location.href);
    if (!beacon) { throw new Error('tracker did not emit a beacon'); }
    return beacon;
}

describe('one OWA cookie cleared but not the other', () => {

    beforeEach(() => {
        eraseEverything();
    });

    afterEach(() => {
        eraseEverything();
    });

    test('the session cookie is gone: a known visitor starts a new session', () => {
        seed({ visitor: true, session: false });

        const beacon = pageView(newTracker());

        // The point of the whole file. Visitor identity comes from 'v' and must
        // not be re-minted because 's' is missing.
        expect(beacon.visitor_id).toBe(VISITOR_VID);
        expect(beacon.is_new_visitor).toBeFalsy();
        // ...while the session correctly starts fresh.
        expect(beacon.is_new_session).toBe(true);
        expect(beacon.session_id).toBeTruthy();
        expect(beacon.session_id).not.toBe(SESSION_SID);
    });

    test('the visitor cookie is gone: the running session is not torn down', () => {
        seed({ visitor: false, session: true });

        const beacon = pageView(newTracker());

        // A visitor who cannot be identified is genuinely a new visitor -- there
        // is nothing to recover them from, and this is the honest answer.
        expect(beacon.is_new_visitor).toBe(true);
        expect(beacon.visitor_id).toBeTruthy();
        // But the session the server already knows about survives intact.
        expect(beacon.session_id).toBe(SESSION_SID);
        expect(beacon.is_new_session).toBeFalsy();
    });

    test('a session-scoped custom var survives the visitor cookie being cleared', () => {
        // Custom variables live in the session store, so they follow the
        // session, not the visitor.
        seed({ visitor: false, session: true });

        const beacon = pageView(newTracker());

        expect(beacon.cv1).toBe('plan=pro');
    });

    test('a session-scoped custom var does NOT survive the session cookie being cleared', () => {
        // The converse, and the reason it is not a bug: the variable was scoped
        // to a session whose state is gone. Carrying it into a new session on
        // the strength of the visitor cookie would attach it to a session it was
        // never set for.
        seed({ visitor: true, session: false });

        const beacon = pageView(newTracker());

        expect(beacon.cv1).toBeFalsy();
    });

    test('both cookies gone: a new visitor and a new session, as on a first visit', () => {
        seed({});

        const beacon = pageView(newTracker());

        expect(beacon.is_new_visitor).toBe(true);
        expect(beacon.is_new_session).toBe(true);
    });
});
