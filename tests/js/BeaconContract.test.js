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
 *
 * THE ENGAGEMENT CLOCK IS FROZEN. It starts in the constructor, so on a real
 * clock the milliseconds between construction and the first event put
 * engagement_msec on the beacon or not depending on how fast the machine is.
 * engagement_msec is conditional -- sent only when time accrued -- and its shape
 * is pinned in EngagementAndLifecycle.test.js with an advancing clock.
 */
function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('contract-site');
    t.getTime = () => 0;
    t.resetEngagement();
    // jsdom's screen is 0x0, which sends nothing; every browser has one.
    t.getScreenResolution = () => '1920x1080';
    return t;
}

/** The state a fresh page load starts in. */
function coldPage() {
    OWA.initializeStateManager();
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.persistenceReleased = {};
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
    OWA.state.persistenceReleased = {};

    const now = Util.getCurrentUnixTimestamp();
    const cdh = OWA.getSetting('hashCookiesToDomain')
        ? Util.getCookieDomainHash(OWA.getSetting('cookie_domain'))
        : undefined;

    const session = {
        sid: 'established-session',
        last_req: now,
        // A session created by the current tracker carries its own start and
        // date; sessionization does not re-run for a continuing session, so
        // these arrive by hydration rather than being derived.
        sts: now,
        session_date: new Date(now * 1000).getFullYear()
            + ('0' + (new Date(now * 1000).getMonth() + 1)).slice(-2)
            + ('0' + new Date(now * 1000).getDate()).slice(-2),
    };
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
    Util.setCookie(ns + 's_contract-site', JSON.stringify(session), 1, '/', domain);
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
 * is_new_session only because page_view happened to run first in file
 * order. Run alone, each of them failed -- before any of this work. Naming the
 * scenario is what makes the contract mean something.
 */
const EMITTERS = {
    'page_view': {
        session: 'new',
        fire: (t) => t.trackPageView('https://example.com/p'),
    },
    /*
     * A SITE-NAMED custom event, which is what replaced custom_event.
     *
     * v1 had one event type for everything a site tracked -- custom_event, told
     * apart by an action_name field -- and v2 retires it: the name IS the name, and
     * the label and value are `eps_` parameters describing it. So the key here is a
     * representative name rather than a fixed one, because a site chooses it; what
     * the contract pins is the property SET such an event carries.
     */
    'my_custom_event': {
        session: 'established',
        fire: (t) => t.trackCustomEvent( 'my_custom_event', { eps_label: 'x', epn_value: 5 } ),
    },
    'click': {
        session: 'established',
        fire: (t) => {
            t.setOption('logClicksAsTheyHappen', true);
            // Every attribute a click can report, so the contract lists them
            // all: an absent id, name or class is not sent.
            const a = document.createElement('a');
            a.id = 'x';
            a.className = 'c';
            a.setAttribute('name', 'n');
            a.href = 'https://x.example/y';
            a.textContent = 'y';
            document.body.appendChild(a);
            t.clickEventHandler({ target: a, pageX: 1, pageY: 2 });
            document.body.removeChild(a);
        },
    },
    'purchase': {
        session: 'established',
        fire: (t) => {
            t.addTransaction('o1', 'web', 1, 0, 0, 'gw');
            t.addTransactionLineItem('o1', 'sku', 'nm', 'cat', 1, 1);
            t.trackTransaction();
        },
    },
    // The one-call API, with every field it takes.
    'refund': {
        session: 'established',
        fire: (t) => t.trackRefund({
            transaction_id: 'T-1', value: 5, currency: 'usd',
            items: [{ item_id: 'sku', price: 5, quantity: 1 }],
        }),
    },
    // Raised by a click on a download link; the click itself is not sent here,
    // so the one beacon is the download.
    'file_download': {
        session: 'established',
        fire: (t) => {
            t.setOption('logClicksAsTheyHappen', false);
            const a = document.createElement('a');
            a.id = 'annual';
            a.href = 'https://x.example/files/report.pdf';
            a.textContent = 'Annual report';
            document.body.appendChild(a);
            t.clickEventHandler({ target: a, pageX: 1, pageY: 2 });
            document.body.removeChild(a);
        },
    },
    'purchase.trackPurchase': {
        session: 'established',
        fire: (t) => t.trackPurchase({
            transaction_id: 'T-1', value: 10, currency: 'usd', tax: 1, shipping: 2,
            coupon: 'C', affiliation: 'web',
            items: [{ item_id: 'sku', item_name: 'nm', price: 10, quantity: 1 }],
        }),
    },
};

describe('tracker beacon contract', () => {
    for (const [eventType, spec] of Object.entries(EMITTERS)) {
        test(`${eventType} emits exactly its contracted property set`, () => {
            const expected = CONTRACTS['2'][eventType];
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
            expect(CONTRACTS['2'][eventType]).toContain('event_type');
        }
    });
});

/**
 * THE REFERRER RIDES EVERY BEACON, and that is the point rather than an oversight.
 *
 * A click's referer_url is the PRIOR PAGE THAT DROVE THE EVENT. It cannot be
 * recovered by joining the click to the page view for the same page, because one
 * session can reach the same page twice from different referrers -- so dropping it
 * from anything but the page view would discard evidence the raw store exists to
 * keep.
 *
 * It WAS briefly scoped to the page view, on the grounds that no report read a
 * click's copy. That was a statement about today's reports, not about what the
 * data is for. Asserted here so the saving is not made again by someone counting
 * bytes: the per-event cost comes from sending one beacon per event, and batching
 * several events into one request is the answer to it.
 */
describe('the referrer rides every beacon', () => {

    beforeEach(() => {
        Object.defineProperty(document, 'referrer', {
            value: 'https://news.example.org/story', configurable: true });
    });

    test('a page view, a click, a purchase and a custom event all carry it', () => {
        for (const name of ['page_view', 'click', 'purchase', 'my_custom_event']) {
            expect(emittedKeys(EMITTERS[name])).toContain('HTTP_REFERER');
        }
    });
});
