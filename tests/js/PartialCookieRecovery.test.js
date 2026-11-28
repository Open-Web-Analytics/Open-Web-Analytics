/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p"}
 */
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * One OWA cookie survives and the other does not.
 *
 * Visitor state ('v', ~1yr) and session state ('s') are separate cookies with
 * very different lifetimes, and a browser, an extension or a person can remove
 * either one without touching the other:
 *
 *   - a visitor whose session cookie is gone is NOT a new visitor. They are a
 *     known visitor starting a new session, and counting them as new would
 *     inflate unique visitors by one for every expired or cleared session.
 *   - a session whose visitor cookie is gone ENDS. The visitor cannot be
 *     identified, so the tracker mints one, and a session belongs to one
 *     visitor. Continuing it would put a single session across two visitors: a
 *     second first_visit part way through, and no nps on the new visitor's
 *     beacons, so the server could not tell new from returning. A browser can
 *     lose the visitor cookie (written once per page) while the session cookie
 *     (rewritten by every event, the unload beacon included) comes back.
 *
 * The session store does not hydrate on first touch, so the session decision
 * depends on reading the persisted 's' store at a specific moment, and a fixture
 * that seeds both cookies together would never exercise the case where only one
 * is there.
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
    OWA.state.persistenceReleased = {};
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
    const ns = 'owa_';
    const domain = OWA.getSetting('cookie_domain');

    if (visitor) {
        const v = { vid: VISITOR_VID, fsts: now - (3600 * 24 * 30), nps: 4 };
        if (cdh) { v.cdh = cdh; }
        Util.setCookie(ns + 'v', JSON.stringify(v), 364, '/', domain);
    }
    if (session) {
        const s = { sid: SESSION_SID, sts: now - 600, last_req: now, cv1: 'plan=pro' };
        if (cdh) { s.cdh = cdh; }
        Util.setCookie(ns + 's_partial-cookie-site', JSON.stringify(s), 1, '/', domain);
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
        expect(beacon.is_new_visitor_created).toBeFalsy();
        // ...while the session correctly starts fresh.
        expect(beacon.is_new_session_start).toBe(true);
        expect(beacon.session_id).toBeTruthy();
        expect(beacon.session_id).not.toBe(SESSION_SID);
    });

    test('the visitor cookie is gone: the new visitor starts a new session', () => {
        seed({ visitor: false, session: true });

        const beacon = pageView(newTracker());

        // A visitor who cannot be identified is genuinely a new visitor -- there
        // is nothing to recover them from, and this is the honest answer.
        expect(beacon.is_new_visitor_created).toBe(true);
        expect(beacon.visitor_id).toBeTruthy();
        // The orphaned session is not continued under the new visitor.
        expect(beacon.is_new_session_start).toBe(true);
        expect(beacon.session_id).toBeTruthy();
        expect(beacon.session_id).not.toBe(SESSION_SID);
        // Its first session: nps is on the beacon, and it is 0.
        expect(beacon.nps).toBe(0);
    });

    test('the visitor cookie is gone: later events stay in the new session, and it is the one persisted', () => {
        seed({ visitor: false, session: true });

        const t = newTracker();
        const first = pageView(t);
        // logEvent is stubbed, so announce the delivery a real send would.
        t.sendAccepted();
        const second = pageView(t);

        expect(second.session_id).toBe(first.session_id);
        expect(second.visitor_id).toBe(first.visitor_id);
        expect(second.is_new_session_start).toBeFalsy();

        // The cookie as the browser now holds it, read from the jar itself.
        const raw = document.cookie.split(';').map((c) => c.trim())
            .find((c) => c.startsWith('owa_s_partial-cookie-site='));
        const stored = JSON.parse(unescape(raw.split('=').slice(1).join('=')));
        expect(stored.sid).toBe(first.session_id);
        expect(stored.sid).not.toBe(SESSION_SID);
    });

    test('both cookies gone: a new visitor and a new session, as on a first visit', () => {
        seed({});

        const beacon = pageView(newTracker());

        expect(beacon.is_new_visitor_created).toBe(true);
        expect(beacon.is_new_session_start).toBe(true);
    });
});
