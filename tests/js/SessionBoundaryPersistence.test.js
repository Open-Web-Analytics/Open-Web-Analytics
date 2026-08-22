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
 * What happens to the PERSISTED session at a session boundary.
 *
 * Crossing a boundary settles the store by discarding the persisted session
 * rather than merging it -- see hydrate() / discardPersisted(). "Discard" means
 * marking it settled so nothing merges; it deliberately does NOT erase the
 * cookie there, for two reasons this file pins.
 *
 * Nothing is destroyed until something is delivered. The new session reaches
 * the cookie on beacon acceptance, and that write REPLACES the cookie wholesale
 * -- so the erase is redundant in the normal path, and in the path where the
 * beacon never lands it would leave the visitor with no session cookie at all,
 * having had one a moment earlier. Measured, GA behaves the same way: a tag that
 * loads and sends nothing writes no cookies, so it never destroys what was there.
 *
 * And the persisted values have to stay readable for the rest of the page load.
 * setLastRequestTime() runs AFTER the session decision and reads the persisted
 * last_req to report as the prior request.
 */

const HOUR = 3600;

function coldPage() {
    OWA.state = new StateManager();
}

function wipe() {
    coldPage();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.cookies = Util.readAllCookies();
}

function sessionCookie() {
    const raw = Util.readCookie('owa_s');
    return raw ? Util.decodeCookieValue(unescape(raw)) : null;
}

function writeSessionCookie(store) {
    Util.setCookie('owa_s', JSON.stringify(store), 1, '/', OWA.getSetting('cookie_domain'));
    OWA.state.cookies = Util.readAllCookies();
}

/** A delivered page load, leaving a persisted session behind. */
function establishSession() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('boundary-site');
    t.logEvent = () => {};
    t.trackPageView('https://example.com/first');
    t.sendAccepted();
    return sessionCookie();
}

/** Age the persisted session past the timeout so the next page crosses a boundary. */
function expire(store) {
    const aged = Object.assign({}, store, { last_req: store.last_req - (2 * HOUR) });
    writeSessionCookie(aged);
    return aged;
}

describe('crossing a session boundary', () => {

    beforeEach(wipe);
    afterEach(wipe);

    test('the new session reports the PRIOR session last request', () => {
        // Regression. setLastRequestTime() runs after the session decision, so
        // erasing the cookie at that decision made this read find nothing --
        // every new session reported an empty last_req, and the server derived
        // no prior_session_lastreq and none of the prior_session_* date parts
        // from it.
        const first = establishSession();
        const aged = expire(first);

        coldPage();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('boundary-site');
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });
        t.trackPageView('https://example.com/second');

        expect(beacons[0].is_new_session).toBe(true);
        expect(beacons[0].last_req).toBe(aged.last_req);
        expect(beacons[0].prior_session_id).toBe(first.sid);
    });

    test('the previous session cookie survives until a beacon is accepted', () => {
        const first = establishSession();
        expire(first);

        coldPage();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('boundary-site');
        t.logEvent = () => {};
        t.trackPageView('https://example.com/second');

        // Decision made, memory holds the new session -- but nothing delivered,
        // so nothing on disk has been destroyed.
        expect(sessionCookie().sid).toBe(first.sid);
    });

    test('...and is replaced wholesale once one is', () => {
        const first = establishSession();
        expire(first);

        coldPage();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('boundary-site');
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });
        t.trackPageView('https://example.com/second');

        t.sendAccepted();

        const after = sessionCookie();
        expect(after.sid).not.toBe(first.sid);
        expect(after.sid).toBe(beacons[0].session_id);
    });

    test('a page that never delivers leaves the visitor their old cookie', () => {
        // The reason the erase is not eager. Erasing at the decision would take
        // the old session away and put nothing back, so a visitor whose beacon
        // was blocked would end the page with no session cookie at all.
        const first = establishSession();
        expire(first);

        coldPage();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('boundary-site');
        t.logEvent = () => {};
        t.trackPageView('https://example.com/second');
        // no sendAccepted(): the beacon was blocked or the page went away

        const after = sessionCookie();
        expect(after).not.toBeNull();
        expect(after.sid).toBe(first.sid);
    });

    test('the ended session values still do not reach the new session', () => {
        // Leaving the cookie readable must not let it bleed through: the
        // discard marks the store settled, so hydrate() never merges it.
        const first = establishSession();
        const aged = expire(first);
        writeSessionCookie(Object.assign({}, aged, { cv1: 'plan=fromEndedSession' }));

        coldPage();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('boundary-site');
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });
        t.trackPageView('https://example.com/second');

        expect(beacons[0].is_new_session).toBe(true);
        expect(beacons[0].cv1).toBeFalsy();
        expect(OWA.getState('s', 'cv1')).toBeFalsy();
    });
});
