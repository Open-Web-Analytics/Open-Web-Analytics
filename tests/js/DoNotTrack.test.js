import { Util as OwaUtil } from '../../modules/base/src/common/Util.js';

/**
 * Do Not Track (DNT) tests.
 *
 * The tracker honours the browser's Do Not Track signal entirely on the client,
 * BEFORE any beacon is assembled or sent. Two layers implement this and both are
 * pinned here:
 *
 *   1. The predicate. Util.isBrowserTrackable() reads navigator.doNotTrack (and
 *      the legacy navigator.msDoNotTrack) and returns false when either is the
 *      string "1". Only an explicit "1" opts out — an unset / "0" / "unspecified"
 *      value leaves the browser trackable.
 *
 *   2. The gate. tracker-dom.js — the entry module the built owa.tracker.js runs
 *      on load — wraps its ENTIRE bootstrap in `if (isBrowserTrackable())`. When
 *      DNT is on, the global owa_cmds array is never swapped for a live
 *      CommandQueue, so none of the site's queued commands (setSiteId,
 *      trackPageView, ...) ever run and not a single beacon leaves the page.
 *
 * Layer 2 is the real user-facing contract (a DNT user is not tracked); layer 1
 * is the reusable predicate the gate is built on. Testing both means a refactor
 * that keeps the predicate but drops the gate — or vice versa — can't pass.
 *
 * tracker-dom.js assigns webpack's __webpack_public_path__ magic global at import
 * time, so we define it before requiring the module (webpack provides it in the
 * real bundle). We require() the gate lazily per-test, after setting navigator,
 * with jest.resetModules() so each import re-runs the bootstrap from scratch.
 */

// webpack runtime global the gate module assigns on import (see tracker-dom.js).
global.__webpack_public_path__ = '';

const BASE_URL = 'https://owa.example.test/';

// Save/restore navigator.doNotTrack across tests: jsdom seeds it (typically
// null), and leaving an explicit "1" behind would silently opt every later
// test's page out of tracking.
let savedDnt;
let savedMsDnt;

function setNavigatorFlag(prop, value) {
    Object.defineProperty(navigator, prop, { configurable: true, get() { return value; } });
}

// Capture the tracker's `new Image(1,1); image.src = url` beacons without a
// network, so we can assert that a DNT page sends exactly none.
function installImageSpy() {
    const sent = [];
    const Orig = global.Image;
    global.Image = class {
        constructor() {}
        set src(v) { sent.push(v); }
        get src() { return this._src; }
    };
    return { sent, restore: () => { global.Image = Orig; } };
}

beforeEach(() => {
    savedDnt = Object.getOwnPropertyDescriptor(navigator, 'doNotTrack');
    savedMsDnt = Object.getOwnPropertyDescriptor(navigator, 'msDoNotTrack');
    window.owa_baseUrl = BASE_URL;
    // The lazy-init path new-ups OWATracker, whose first event reads
    // document.domain (undefined in jsdom -> crashes substr). Give it the
    // hostname a real tracked page always has. (Same rationale as
    // CommandQueue.test.js.)
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return 'site.example'; },
    });
});

afterEach(() => {
    // Restore navigator flags exactly as jsdom had them.
    if (savedDnt) { Object.defineProperty(navigator, 'doNotTrack', savedDnt); }
    else { delete navigator.doNotTrack; }
    if (savedMsDnt) { Object.defineProperty(navigator, 'msDoNotTrack', savedMsDnt); }
    else { delete navigator.msDoNotTrack; }

    delete window.owa_baseUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    jest.resetModules();
});

describe('Util.isBrowserTrackable() — the DNT predicate', () => {

    test('doNotTrack === "1" opts the browser out', () => {
        setNavigatorFlag('doNotTrack', '1');
        expect(OwaUtil.isBrowserTrackable()).toBe(false);
    });

    test('legacy msDoNotTrack === "1" opts the browser out', () => {
        // IE/old-Edge exposed the signal under this vendor-prefixed property.
        setNavigatorFlag('doNotTrack', null);
        setNavigatorFlag('msDoNotTrack', '1');
        expect(OwaUtil.isBrowserTrackable()).toBe(false);
    });

    test('doNotTrack === "0" leaves the browser trackable', () => {
        setNavigatorFlag('doNotTrack', '0');
        setNavigatorFlag('msDoNotTrack', null);
        expect(OwaUtil.isBrowserTrackable()).toBe(true);
    });

    test('an unset signal leaves the browser trackable', () => {
        setNavigatorFlag('doNotTrack', null);
        setNavigatorFlag('msDoNotTrack', null);
        expect(OwaUtil.isBrowserTrackable()).toBe(true);
    });

    test('only the exact string "1" opts out, not other truthy values', () => {
        // The check is `== "1"`, so a browser reporting "unspecified" (or "yes")
        // is still trackable — DNT is an explicit "1"-means-do-not-track contract.
        setNavigatorFlag('doNotTrack', 'unspecified');
        setNavigatorFlag('msDoNotTrack', null);
        expect(OwaUtil.isBrowserTrackable()).toBe(true);
    });
});

describe('tracker-dom.js — the DNT bootstrap gate', () => {

    test('DNT off: the owa_cmds array is swapped for a live CommandQueue and drained', () => {
        const spy = installImageSpy();
        try {
            setNavigatorFlag('doNotTrack', '0');
            setNavigatorFlag('msDoNotTrack', null);

            // The async-embed shape: the page queues commands as a plain array
            // BEFORE the tracker script arrives.
            const cmds = [
                ['setSiteId', 'dnt-off-site'],
                ['trackPageView', 'https://site.example/allowed'],
            ];
            global.owa_cmds = cmds;
            window.owa_cmds = cmds;

            require('../../modules/base/src/tracker/tracker-dom.js');

            // On load the gate opened: the array was replaced by the live queue...
            expect(Array.isArray(window.owa_cmds)).toBe(false);
            expect(window.owa_cmds.constructor.name).toBe('CommandQueue');
            // ...and the queued trackPageView actually fired a beacon carrying the
            // queued site id, proving the commands ran end-to-end.
            expect(spy.sent).toHaveLength(1);
            expect(spy.sent[0]).toContain(BASE_URL + 'log.php?');
            expect(spy.sent[0]).toContain('owa_site_id=dnt-off-site');
        } finally {
            spy.restore();
        }
    });

    test('DNT on: the bootstrap is skipped — no queue, no tracker, no beacon', () => {
        const spy = installImageSpy();
        try {
            setNavigatorFlag('doNotTrack', '1');

            const cmds = [
                ['setSiteId', 'dnt-on-site'],
                ['trackPageView', 'https://site.example/blocked'],
            ];
            global.owa_cmds = cmds;
            window.owa_cmds = cmds;

            require('../../modules/base/src/tracker/tracker-dom.js');

            // The gate stayed shut: owa_cmds is still the untouched plain array
            // (never swapped for a CommandQueue)...
            expect(Array.isArray(window.owa_cmds)).toBe(true);
            expect(window.owa_cmds).toBe(cmds);
            // ...the default tracker was never instantiated...
            expect(window.OWATracker).toBeUndefined();
            // ...and, the actual user-facing guarantee, NOT ONE beacon was sent.
            expect(spy.sent).toHaveLength(0);
        } finally {
            spy.restore();
        }
    });
});
