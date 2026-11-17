/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p"}
 */
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * A visitor cookie lost while the page is open is written back from memory.
 *
 * The session store is written on every event, so a lost session cookie comes
 * back with the next one. The visitor store is written on a page's first event
 * only, so before this a lost visitor cookie stayed lost and the next page
 * minted a new visitor. Safari has been seen dropping both cookies mid-page.
 *
 * Every event now checks the jar and writes the visitor store back only when
 * its cookie is missing: not when it holds something else, and not when the
 * page has recorded consent as denied.
 *
 * These drive the real pipeline; a "reload" empties the state manager's memory
 * and keeps the cookie jar, as a page load does.
 */

const SITE = 'restore-site';
const SESSION = 's_' + SITE;

function ns(name) { return OWA.getSetting('ns') + name; }

function eraseEverything() {
    OWA.initializeStateManager();
    ['v', 's', 'c', 'b', 'd', SESSION].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    coldMemory();
}

/** A page load: nothing in memory, the cookie jar as the browser has it. */
function coldMemory() {
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.persistenceReleased = {};
    OWA.state.cookies = Util.readAllCookies();
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId(SITE);
    return t;
}

/** A page view, delivered: logEvent is stubbed, so announce acceptance. */
function pageView(t) {
    let beacon = null;
    t.logEvent = (p) => { beacon = { ...p }; };
    t.trackPageView(location.href);
    t.sendAccepted();
    if (!beacon) { throw new Error('tracker did not emit a beacon'); }
    return beacon;
}

/** A later event on the same page, as the unload beacon is. */
function engagement(t) {
    let beacon = null;
    t.logEvent = (p) => { beacon = { ...p }; };
    const e = t.makeEvent();
    e.setEventType('user_engagement');
    t.trackEvent(e);
    return beacon;
}

/** The store as the browser now holds it, read from the jar itself. */
function stored(store) {
    const values = (Util.readAllCookies() || {})[ns(store)];
    return values ? JSON.parse(unescape(values[0])) : null;
}

/** The browser losing a cookie, as Safari did. */
function lose(store) {
    Util.eraseCookie(ns(store));
}

describe('a visitor cookie lost while the page is open', () => {

    beforeEach(() => { eraseEverything(); });
    afterEach(() => { eraseEverything(); });

    test('both lost mid-page: the next event writes both back, and the reload keeps the visitor and the session', () => {
        const t = newTracker();
        const first = pageView(t);

        lose('v');
        lose(SESSION);
        expect(stored('v')).toBeNull();
        expect(stored(SESSION)).toBeNull();

        engagement(t);

        expect(stored('v').vid).toBe(first.visitor_id);
        expect(stored(SESSION).sid).toBe(first.session_id);

        coldMemory();
        const reload = pageView(newTracker());

        expect(reload.visitor_id).toBe(first.visitor_id);
        expect(reload.session_id).toBe(first.session_id);
        expect(reload.is_new_visitor_created).toBeFalsy();
        expect(reload.is_new_session_start).toBeFalsy();
    });

    test('only the visitor cookie lost: written back with its first-visit time and prior-session count', () => {
        const t = newTracker();
        pageView(t);
        const before = stored('v');

        lose('v');
        engagement(t);

        expect(stored('v')).toMatchObject({ vid: before.vid, fsts: before.fsts, nps: before.nps });
    });

    test('a visitor cookie holding another id is left alone', () => {
        const t = newTracker();
        pageView(t);

        // Another tab, or a newer page, has written its own visitor.
        const other = { vid: 'other-visitor', cdh: Util.getCookieDomainHash(OWA.getSetting('cookie_domain')) };
        Util.setCookie(ns('v'), JSON.stringify(other), 364, '/', OWA.getSetting('cookie_domain') || document.domain);

        engagement(t);

        expect(stored('v').vid).toBe('other-visitor');
    });

    test('consent recorded as denied: a lost visitor cookie stays lost', () => {
        const t = newTracker();
        pageView(t);
        t.setConsentState('denied');

        lose('v');
        engagement(t);

        expect(stored('v')).toBeNull();
    });

    test('nothing is written while the cookie is there', () => {
        const t = newTracker();
        pageView(t);

        const spy = jest.spyOn(Util, 'setCookie');
        try {
            engagement(t);
            const visitorWrites = spy.mock.calls.filter((call) => call[0] === ns('v'));
            expect(visitorWrites).toHaveLength(0);
        } finally {
            spy.mockRestore();
        }
    });
});
