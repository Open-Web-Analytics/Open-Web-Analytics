import fs from 'fs';
import path from 'path';
import { OWATracker } from '../../modules/base/src/tracker/Tracker.js';

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

/**
 * Run `fire` (which calls a track* method) and return the sorted list of
 * property names the tracker actually put on the wire.
 */
function emittedKeys(fire) {
    const t = newTracker();
    let beacon = null;
    t.logEvent = (properties) => { beacon = properties; };
    fire(t);
    if (!beacon) {
        throw new Error('tracker did not emit a beacon');
    }
    return Object.keys(beacon).sort();
}

const EMITTERS = {
    'base.page_request': (t) => t.trackPageView('https://example.com/p'),
    'track.action': (t) => t.trackAction('g', 'n', 'l', 5),
    'dom.click': (t) => {
        t.setOption('logClicksAsTheyHappen', true);
        const a = document.createElement('a');
        a.id = 'x';
        a.textContent = 'y';
        document.body.appendChild(a);
        t.clickEventHandler({ target: a, pageX: 1, pageY: 2 });
        document.body.removeChild(a);
    },
    'ecommerce.transaction': (t) => {
        t.addTransaction('o1', 'web', 1, 0, 0, 'gw');
        t.addTransactionLineItem('o1', 'sku', 'nm', 'cat', 1, 1);
        t.trackTransaction();
    },
};

describe('tracker beacon contract', () => {
    for (const [eventType, fire] of Object.entries(EMITTERS)) {
        test(`${eventType} emits exactly its contracted property set`, () => {
            const expected = CONTRACTS[eventType];
            expect(expected).toBeDefined();
            const actual = emittedKeys(fire);
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
