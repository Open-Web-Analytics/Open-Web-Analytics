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
 * A session-scoped property must be IDENTICAL on every event sharing a
 * session_id. A divergence is a regression, whatever caused it.
 *
 * This is the rule that ruled out deriving days_since_prior_session as calendar
 * days: counting date boundaries needs two absolute times, and an event carries
 * only its own timestamp plus a session-scoped interval, so the derived anchor
 * moved for every event after the one that opened the session and two hits
 * either side of a midnight disagreed about the same session.
 *
 * The list below is explicit rather than derived from collectStateProperties(),
 * because not everything that lives in the session store is session-scoped --
 * see PAGE_SCOPED. Adding a session-scoped property means adding it here.
 */

/**
 * The scopes are DECLARED ON THE TRACKER, not restated here. Being in the
 * session store does not make a property session-scoped -- prior_last_req lives
 * there and has page lifetime -- so the scope has to be its own contract, and a
 * test that kept its own copy would drift from it.
 */
const SCOPES = new OWATracker({ cookie_domain_set: true }).trackingPropertyScopes;

/** Constant across a session: session-scoped, plus visitor-scoped by implication. */
const SESSION_SCOPED = SCOPES.session.concat(SCOPES.visitor);

/** Legitimately varies within a session, each for its own reason. */
const PAGE_SCOPED = SCOPES.page.concat(SCOPES.request);

function wipe() {
    OWA.state = new StateManager();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.cookies = Util.readAllCookies();
}

/** The page went away; cookies survive, memory does not. */
function nextPageLoad() {
    OWA.state = new StateManager();
}

function newTracker(site) {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId(site || 'invariant-site');
    return t;
}

describe('session-scoped properties do not vary within a session', () => {

    beforeEach(wipe);
    afterEach(wipe);

    function sessionCookie() {
        const raw = Util.readCookie('owa_s');
        return raw ? Util.decodeCookieValue(unescape(raw)) : null;
    }

    /**
     * Beacons across: two events on one page, a second page load in the same
     * session, and a second tracker alongside it.
     */
    function trackAcrossASession(beacons) {
        // A referrer, so session_referer is actually produced -- without one the
        // assertions about it compare undefined to undefined.
        Object.defineProperty(document, 'referrer', {
            configurable: true,
            get: () => 'https://referrer.example/landing',
        });

        const first = newTracker();
        first.logEvent = (p) => beacons.push({ ...p });
        first.trackPageView('https://example.com/one');

        const action = first.makeEvent();
        action.setEventType('track.action');
        first.trackEvent(action);

        first.sendAccepted();

        nextPageLoad();
        const second = newTracker();
        second.logEvent = (p) => beacons.push({ ...p });
        second.trackPageView('https://example.com/two');

        const alongside = newTracker('other-site');
        alongside.logEvent = (p) => beacons.push({ ...p });
        alongside.trackPageView('https://example.com/two');

        return beacons;
    }

    /**
     * Two scenarios, because one cannot produce every property: a brand new
     * visitor has no prior session, and a visitor with a prior session is not
     * new. Without both, half the assertions below compare undefined to
     * undefined and pass whatever the code does.
     */
    const SCENARIOS = {

        'a brand new visitor': () => trackAcrossASession([]),

        'a returning visitor whose session expired': () => {
            // Establish and persist a session, then age it past the timeout so
            // the run below crosses a real boundary.
            const seed = newTracker();
            seed.logEvent = () => {};
            seed.trackPageView('https://example.com/earlier');
            seed.sendAccepted();

            const store = sessionCookie();
            store.last_req = store.last_req - (60 * 3600);
            Util.setCookie('owa_s', JSON.stringify(store), 1, '/', OWA.getSetting('cookie_domain'));

            nextPageLoad();
            return trackAcrossASession([]);
        },
    };

    Object.entries(SCENARIOS).forEach(([label, run]) => {

        describe(label, () => {

            test('the beacons really do share one session', () => {
                // Otherwise everything below is vacuous.
                const beacons = run();

                expect(beacons.length).toBeGreaterThan(3);
                beacons.forEach((b) => expect(b.session_id).toBe(beacons[0].session_id));
            });

            SESSION_SCOPED.forEach((prop) => {
                test(`${prop} is identical on every event`, () => {
                    const beacons = run();
                    const expected = beacons[0][prop];

                    beacons.forEach((b, i) => {
                        expect({ event: i, prop, value: b[prop] })
                            .toEqual({ event: i, prop, value: expected });
                    });
                });
            });
        });
    });

    test('every session-scoped property is actually PRESENT in some scenario', () => {
        // The guard against the assertions above passing vacuously. A property
        // that is undefined in every scenario is trivially "identical" on every
        // event and tests nothing.
        const seen = {};

        Object.values(SCENARIOS).forEach((run) => {
            wipe();
            run().forEach((b) => {
                SESSION_SCOPED.forEach((prop) => {
                    if (b[prop] !== undefined && b[prop] !== '') { seen[prop] = true; }
                });
            });
        });

        const missing = SESSION_SCOPED.filter((prop) => ! seen[prop]);
        expect(missing).toEqual([]);
    });

    test('every property the collectors emit is declared in some scope', () => {
        // The registry is only a contract if it is complete. A property added to
        // a collector without a scope would otherwise be silently unguarded.
        const t = newTracker();
        OWA.setState('s', 'session_referer', 'x');

        const emitted = Object.keys(t.collectStateProperties())
            .concat(Object.keys(t.collectPageProperties()))
            .concat(['is_new_session_start', 'is_new_visitor_created']);

        const declared = Object.values(SCOPES).reduce((all, list) => all.concat(list), []);
        const undeclared = emitted.filter((p) => ! declared.includes(p) && ! /^cv[0-9]+$/.test(p));

        expect(undeclared).toEqual([]);
    });

    test('the session scope has not silently shrunk', () => {
        /*
         * Deliberate duplication, as a tripwire.
         *
         * A suite generated from a registry shrinks SILENTLY when an entry is
         * removed -- the assertions for that property simply stop existing and
         * everything still passes. Verified: dropping two entries took this file
         * from 23 tests to 19, green.
         *
         * Naming the floor here means removing a property from the scope
         * registry has to be done twice, which makes it a decision someone took
         * rather than an omission nobody noticed.
         */
        [
            'session_id',
            'prior_session_id',
            'is_new_visitor',
            'time_since_last_session',
            'session_referer',
        ].forEach((prop) => expect(SCOPES.session).toContain(prop));

        ['visitor_id', 'fsts', 'dsfs', 'nps']
            .forEach((prop) => expect(SCOPES.visitor).toContain(prop));
    });

    test('the page-scoped ones are NOT claimed to be session-scoped', () => {
        PAGE_SCOPED.forEach((prop) => {
            expect(SESSION_SCOPED).not.toContain(prop);
        });
    });
});
