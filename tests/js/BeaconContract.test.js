import fs from 'fs';
import path from 'path';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * Beacon contract test — the anti-drift anchor for the whole tracker test
 * harness.
 *
 * Every other test asserts *values*; this one asserts the *shape* of the wire
 * payload. It drives each event type through the tracker's REAL send pipeline
 * (trackEvent -> manageState -> addGlobalPropertiesToEvent -> addDefaultsToEvent
 * -> logEvent) by intercepting only the innermost logEvent(properties) call,
 * then asserts the emitted property-name set exactly matches the shared
 * contract in tests/fixtures/beacon_contracts.json.
 *
 * That JSON file is the single source of truth: the PHP ingestion tests read
 * the same file and assert every field their handler consumes is listed there.
 * So a tracker-side rename/drop of a beacon field breaks THIS test, and a
 * server-side handler reading a field the tracker no longer sends breaks the
 * PHP side — drift between the two layers can't pass silently.
 *
 * When the tracker legitimately changes what it emits, update the fixture (the
 * failure message prints the new key set) AND review the matching PHP handler.
 */

const CONTRACTS = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/beacon_contracts.json'), 'utf8')
);

/**
 * Build a tracker wired for a headless run. cookie_domain_set avoids the
 * document.domain path; setSiteId gives site_id a value.
 */
function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('contract-site');
    return t;
}

/** The state a fresh page load starts in. */
function coldPage() {
    OWA.initializeStateManager();
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.sessionPersistenceReady = false;
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    OWA.state.cookies = Util.readAllCookies();
}

/**
 * A session already in progress, as a previous page load would have left it.
 *
 * Written to the REAL cookie jar, not to the state manager's cookie cache.
 * setVisitorId() calls clearState('v'), which refreshes that cache from the jar
 * -- so a cache-level seed is silently discarded partway through the very
 * event-processing chain these tests drive, and every contract then describes a
 * new session regardless of what was seeded.
 *
 * Called AFTER the tracker is constructed: the domain hash has to be computed
 * against the cookie domain the tracker settled on, and a hash for any other
 * domain is one readPersistedStore correctly refuses to load.
 */
function seedEstablishedSession() {
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.sessionPersistenceReady = false;

    const now = Util.getCurrentUnixTimestamp();
    const cdh = OWA.getSetting('hashCookiesToDomain')
        ? Util.getCookieDomainHash(OWA.getSetting('cookie_domain'))
        : undefined;

    const session = { sid: 'established-session', last_req: now };
    // A returning visitor, not just a running session. These contracts omit
    // is_new_visitor as well as is_new_session, and a visitor who has never
    // been seen before cannot be in the middle of a session.
    const visitor = { vid: 'established-visitor', fsts: now - (3600 * 24 * 7), nps: 2 };

    if (cdh) {
        session.cdh = cdh;
        visitor.cdh = cdh;
    }

    const ns = OWA.getSetting('ns');
    const domain = OWA.getSetting('cookie_domain');
    Util.setCookie(ns + 's', JSON.stringify(session), 1, '/', domain);
    Util.setCookie(ns + 'v', JSON.stringify(visitor), 364, '/', domain);
    OWA.state.cookies = Util.readAllCookies();
}

/**
 * Run `fire` (which calls a track* method) and return the sorted list of
 * property names the tracker actually put on the wire.
 */
function emittedKeys(spec) {
    coldPage();
    const t = newTracker();
    if (spec.session === 'established') {
        seedEstablishedSession();
    }
    let beacon = null;
    t.logEvent = (properties) => { beacon = properties; };
    spec.fire(t);
    if (!beacon) {
        throw new Error('tracker did not emit a beacon');
    }
    return Object.keys(beacon).sort();
}

/**
 * Each contract, and the session it describes.
 *
 *   'new'          nothing persisted; this event starts the session, so the
 *                  payload carries is_new_session
 *   'established'  a session already in the cookie, left by a previous page
 *
 * The scenario used to be implicit, and unstated scenarios are not scenarios:
 * every emitter ran against whatever the previous test had left in the shared
 * in-memory state store, so the three non-pageview contracts omitted
 * is_new_session only because base.page_request happened to run first in file
 * order. Run alone, each of them failed -- before any of this work. Naming the
 * scenario is what makes the contract mean something.
 */
const EMITTERS = {
    'base.page_request': {
        session: 'new',
        fire: (t) => t.trackPageView('https://example.com/p'),
    },
    'track.action': {
        session: 'established',
        fire: (t) => t.trackAction('g', 'n', 'l', 5),
    },
    'dom.click': {
        session: 'established',
        fire: (t) => {
            t.setOption('logClicksAsTheyHappen', true);
            const a = document.createElement('a');
            a.id = 'x';
            a.textContent = 'y';
            document.body.appendChild(a);
            t.clickEventHandler({ target: a, pageX: 1, pageY: 2 });
            document.body.removeChild(a);
        },
    },
    'ecommerce.transaction': {
        session: 'established',
        fire: (t) => {
            t.addTransaction('o1', 'web', 1, 0, 0, 'gw');
            t.addTransactionLineItem('o1', 'sku', 'nm', 'cat', 1, 1);
            t.trackTransaction();
        },
    },
};

describe('tracker beacon contract', () => {
    for (const [eventType, spec] of Object.entries(EMITTERS)) {
        test(`${eventType} emits exactly its contracted property set`, () => {
            const expected = CONTRACTS[eventType];
            expect(expected).toBeDefined();
            const actual = emittedKeys(spec);
            // Deep-equal on the sorted key arrays: catches added, dropped or
            // renamed beacon fields. If this fails, the tracker changed what it
            // sends — sync tests/fixtures/beacon_contracts.json and the handler.
            expect(actual).toEqual(expected.slice().sort());
        });
    }

    test('every contracted event_type carries the event_type field itself', () => {
        for (const eventType of Object.keys(EMITTERS)) {
            expect(CONTRACTS[eventType]).toContain('event_type');
        }
    });
});
