/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p"}
 *
 * Beacon contract test for CUSTOM VARIABLES — a companion to
 * BeaconContract.test.js / BeaconContractReferred.test.js.
 *
 * setCustomVar(slot, name, value, scope) is the tracker's public API for
 * attaching arbitrary name/value pairs to events. Regardless of scope it puts
 * the pair on the wire as a single 'cv{slot}' param whose value is the joined
 * "name=value" string (Tracker.js:1789-1819); the server later splits each into
 * cv{slot}_name / cv{slot}_value (see CustomVariableIngestionTest.php). The
 * three scopes differ only in WHERE the value is persisted client-side between
 * pageviews:
 *
 *  - page:    no cookie persistence. Lives in the 'd' state store, which is
 *             memory only for the life of the page, and as a global event
 *             property.
 *  - session: the session store 's'. Held in memory until the session is
 *             settled and a request carrying it is accepted, then written to
 *             the session cookie and to it directly from then on.
 *  - visitor: the visitor cookie ('v' state store), written immediately --
 *             a visitor outlives their sessions, so nothing about the session
 *             decision governs it.
 *
 * This locks the wire shape (cv1/cv2/cv3 present, each = "name=value") into
 * tests/fixtures/beacon_contracts.json (base.page_request.customvars) so the
 * PHP CustomVariableIngestionTest can assert every field it feeds the handler is
 * one the tracker really emits — the anti-drift guarantee extends to custom
 * vars. A rename of the 'cv{n}' wire param, or a change to the "name=value"
 * encoding, breaks this test.
 */
import fs from 'fs';
import path from 'path';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { StateManager } from '../../modules/Base/src/common/StateManager.js';

const CONTRACTS = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/beacon_contracts.json'), 'utf8')
);

/**
 * Wipe cookies and give the OWA singleton a fresh state manager so a prior
 * capture's session/visitor custom vars can't leak into the next one.
 */
function resetOwaState() {
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) {
            document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        }
    });
    OWA.state = new StateManager();
}

/**
 * The page went away and another one loaded. Cookies survive; memory does not.
 *
 * A fresh state manager over the SAME cookie jar is exactly that, and it is
 * what a second-pageview test has to do now: sharing memory between the two
 * trackers would let the first one's session store answer the second one's
 * reads, and the test would pass whether or not anything was ever persisted.
 */
function nextPageLoad() {
    OWA.state = new StateManager();
}

/**
 * Set up three custom vars (one per scope), fire a fresh-state pageview, and
 * return both the sorted key set and the captured beacon so tests can assert
 * shape and the "name=value" encoding.
 */
function emitWithCustomVars() {
    resetOwaState();
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('contract-site');
    let beacon = null;
    t.logEvent = (properties) => { beacon = properties; };

    t.setCustomVar(1, 'color', 'blue', 'page');
    t.setCustomVar(2, 'plan', 'pro', 'session');
    t.setCustomVar(3, 'cohort', 'beta', 'visitor');

    t.trackPageView(location.href);

    if (!beacon) {
        throw new Error('tracker did not emit a beacon');
    }
    return { keys: Object.keys(beacon).sort(), beacon };
}

describe('tracker custom variable beacon contract', () => {
    test('a pageview with custom vars emits its contracted property set', () => {
        const expected = CONTRACTS['base.page_request.customvars'];
        expect(expected).toBeDefined();
        const { keys } = emitWithCustomVars();
        expect(keys).toEqual(expected.slice().sort());
    });

    test('each custom var rides the wire as cv{slot} = "name=value" regardless of scope', () => {
        const { beacon } = emitWithCustomVars();
        // page, session and visitor scopes all encode identically on the wire —
        // scope only changes client-side persistence, not the beacon shape.
        expect(beacon.cv1).toBe('color=blue');
        expect(beacon.cv2).toBe('plan=pro');
        expect(beacon.cv3).toBe('cohort=beta');
    });

    test('setCustomVar rejects a name=value pair longer than 65 chars', () => {
        resetOwaState();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('contract-site');
        let beacon = null;
        t.logEvent = (properties) => { beacon = properties; };

        // 'k=' + 64 chars => 66 chars, over the 65-char guard, so the slot is
        // never set and never reaches the wire.
        t.setCustomVar(1, 'k', 'x'.repeat(64), 'page');
        t.trackPageView(location.href);

        expect(beacon).not.toBeNull();
        expect(beacon).not.toHaveProperty('cv1');
    });

    test('session-scoped custom var persists across a second pageview; page-scoped does not', () => {
        resetOwaState();
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('contract-site');
        const beacons = [];
        t.logEvent = (properties) => { beacons.push({ ...properties }); };

        t.setCustomVar(1, 'color', 'blue', 'page');
        t.setCustomVar(2, 'plan', 'pro', 'session');
        t.trackPageView(location.href);
        // logEvent is stubbed, so the acceptance the transport would have
        // signalled has to come from here. Without it the session is never
        // persisted -- correctly, since nothing was ever delivered.
        t.sendAccepted();

        // A fresh tracker on the same "browser" (cookies persist) — mirrors a
        // second pageview in the same session. Page scope is gone; session
        // scope is rehydrated from the session cookie.
        nextPageLoad();
        const t2 = new OWATracker({ cookie_domain_set: true });
        t2.setSiteId('contract-site');
        t2.logEvent = (properties) => { beacons.push({ ...properties }); };
        t2.trackPageView(location.href);

        expect(beacons).toHaveLength(2);
        expect(beacons[1]).not.toHaveProperty('cv1');
        expect(beacons[1].cv2).toBe('plan=pro');
    });
});
