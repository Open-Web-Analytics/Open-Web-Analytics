import { CommandQueue } from '../../modules/base/src/tracker/CommandQueue.js';

/**
 * Command-queue (owa_cmds) invocation tests.
 *
 * The documented public embed API is NOT "new OWATracker()" -- it is the async
 * owa_cmds command queue (see the OWA wiki, "Javascript Tracker"): a site pushes
 * command arrays like ['setSiteId','x'] / ['trackPageView'] onto a global array
 * BEFORE the tracker script arrives, and on load the tracker swaps that array for
 * a CommandQueue and drains it. Every real tracked page drives the tracker through
 * this indirection, yet the other jest tests call tracker methods directly and
 * never exercise it. These tests pin the queue's contract:
 *
 *   - a bare command (no '.') routes to the default global 'OWATracker', creating
 *     it on first use;
 *   - the command's tail elements are applied as method arguments;
 *   - a dotted command ('Name.method') routes to -- and lazily creates -- a
 *     differently named global tracker (the wiki's multi-tracker pattern);
 *   - loadCmds()+process() drains a pre-seeded array in FIFO order (the async
 *     embed's "queue before load, replay after load" behaviour);
 *   - 'pause-owa' / 'unpause-owa' gate dispatch on/off.
 *
 * We stub Image so the beacons the drained commands assemble never hit the network;
 * a couple of tests still assert on the captured pixel src to prove the command
 * actually reached the tracker and carried its arguments through to the wire.
 */

// The tracker's beacon is `new Image(1,1); image.src = url`. Capture the src sets
// so a drained trackPageView/etc. can be observed without a network.
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

const BASE_URL = 'https://owa.example.test/';

beforeEach(() => {
    window.owa_baseUrl = BASE_URL;
    // The queue's auto-instantiation path creates the tracker with
    // `new OWATracker({globalObjectName})` -- deliberately WITHOUT the
    // cookie_domain_set escape hatch the direct-call jest tests use. On its first
    // event the tracker runs setCookieDomain(undefined), which reads
    // document.domain. jsdom returns undefined for document.domain (unlike a real
    // browser, where it's the page hostname), which crashes substr. Give it the
    // hostname a real tracked page always has, rather than injecting
    // cookie_domain_set and short-circuiting the very lazy-init path under test.
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return 'site.example'; },
    });
});

afterEach(() => {
    // The queue creates trackers as named window globals; scrub them so tests
    // don't leak instances (and their queued site ids) into one another.
    delete window.owa_baseUrl;
    delete window.OWATracker;
    delete window.OWATracker2;
});

describe('CommandQueue (owa_cmds) invocation', () => {

    test('a bare command lazily creates the default OWATracker and dispatches to it', () => {
        expect(window.OWATracker).toBeUndefined();

        const q = new CommandQueue();
        q.push(['setSiteId', 'queue-site']);

        // First bare command must have instantiated the default global tracker...
        expect(window.OWATracker).toBeDefined();
        // ...and actually invoked the method with the pushed argument.
        expect(window.OWATracker.siteId).toBe('queue-site');
    });

    test('a queued trackPageView drives a real beacon carrying the queued site id', () => {
        const spy = installImageSpy();
        try {
            const q = new CommandQueue();
            q.push(['setSiteId', 'queue-site']);
            q.push(['trackPageView', 'https://site.example/queued']);

            expect(spy.sent).toHaveLength(1);
            const url = spy.sent[0];
            expect(url).toContain(BASE_URL + 'log.php?');
            expect(url).toContain('owa_event_type=base.page_request');
            // Proves the setSiteId command's argument survived the queue indirection
            // all the way onto the wire.
            expect(url).toContain('owa_site_id=queue-site');
            // Values are url-encoded on the wire (the ':' / '/' become %3A / %2F).
            expect(url).toContain('owa_page_url=' + encodeURIComponent('https://site.example/queued'));
        } finally {
            spy.restore();
        }
    });

    test('a dotted command routes to -- and lazily creates -- a named tracker', () => {
        // The wiki's multi-tracker pattern: ['OWATracker2.trackPageView'] must
        // create OWATracker2 (NOT OWATracker) and dispatch on it.
        expect(window.OWATracker2).toBeUndefined();

        const q = new CommandQueue();
        q.push(['OWATracker2.setSiteId', 'second-tracker-site']);

        expect(window.OWATracker2).toBeDefined();
        expect(window.OWATracker2.siteId).toBe('second-tracker-site');
        // The default tracker must not have been created as a side effect.
        expect(window.OWATracker).toBeUndefined();
    });

    test('two trackers coexist on one page with independent config; beacons route to the right one', () => {
        // The wiki's "Using Multiple Trackers on the Same Web Page" contract:
        // a bare command drives the default OWATracker while a dotted
        // 'OWATracker2.*' command drives a second, independently-configured
        // tracker -- and each one's events carry ITS OWN site id to the wire.
        const spy = installImageSpy();
        try {
            expect(window.OWATracker).toBeUndefined();
            expect(window.OWATracker2).toBeUndefined();

            const q = new CommandQueue();
            q.push(['setSiteId', 'site-A']);                                 // default tracker
            q.push(['OWATracker2.setSiteId', 'site-B']);                     // second tracker
            q.push(['trackPageView', 'https://a.example/on-default']);       // -> default
            q.push(['OWATracker2.trackPageView', 'https://b.example/on-2']); // -> second

            // Both trackers exist and hold their OWN, un-cross-contaminated site id.
            expect(window.OWATracker).toBeDefined();
            expect(window.OWATracker2).toBeDefined();
            expect(window.OWATracker.siteId).toBe('site-A');
            expect(window.OWATracker2.siteId).toBe('site-B');
            expect(window.OWATracker).not.toBe(window.OWATracker2);

            // Each pageview produced a beacon carrying that tracker's site id and
            // its own page url -- proving the routing kept the two streams separate.
            expect(spy.sent).toHaveLength(2);

            const beaconA = spy.sent.find(u => u.includes('owa_site_id=site-A'));
            const beaconB = spy.sent.find(u => u.includes('owa_site_id=site-B'));
            expect(beaconA).toBeDefined();
            expect(beaconB).toBeDefined();
            // The default tracker's beacon carries the default tracker's url (and
            // NOT site-B), and vice versa -- no cross-routing between trackers.
            expect(beaconA).toContain('owa_page_url=' + encodeURIComponent('https://a.example/on-default'));
            expect(beaconA).not.toContain('site-B');
            expect(beaconB).toContain('owa_page_url=' + encodeURIComponent('https://b.example/on-2'));
            expect(beaconB).not.toContain('site-A');
        } finally {
            spy.restore();
        }
    });

    test('loadCmds()+process() drains a pre-seeded array in FIFO order', () => {
        // This is the exact async-embed shape: the page fills a plain array before
        // the tracker exists; on load the tracker hands it to the queue and drains.
        const calls = [];
        window.OWATracker = {
            setSiteId(id) { calls.push('setSiteId:' + id); },
            trackPageView() { calls.push('trackPageView'); },
            trackClicks() { calls.push('trackClicks'); },
        };

        const q = new CommandQueue();
        q.loadCmds([
            ['setSiteId', 'fifo-site'],
            ['trackPageView'],
            ['trackClicks'],
        ]);
        q.process();

        expect(calls).toEqual(['setSiteId:fifo-site', 'trackPageView', 'trackClicks']);
        // Queue fully drained.
        expect(q.asyncCmds).toHaveLength(0);
    });

    test('pause-owa gates dispatch; unpause-owa resumes it', () => {
        const calls = [];
        window.OWATracker = {
            trackPageView() { calls.push('trackPageView'); },
            ['pause-owa']() {},   // never invoked directly; the queue handles pausing
            ['unpause-owa']() {},
        };

        const q = new CommandQueue();
        q.push(['pause-owa']);
        q.push(['trackPageView']);         // suppressed while paused
        expect(calls).toEqual([]);
        expect(q.is_paused).toBe(true);

        q.push(['unpause-owa']);
        q.push(['trackPageView']);         // dispatched again
        expect(calls).toEqual(['trackPageView']);
        expect(q.is_paused).toBe(false);
    });

    test('push() fires an optional completion callback', () => {
        const q = new CommandQueue();
        let done = false;
        q.push(['setSiteId', 'cb-site'], () => { done = true; });
        expect(done).toBe(true);
    });
});

/**
 * pause-owa / unpause-owa (the queue's stop / start controls).
 *
 * These are the only two queue-LEVEL commands the CommandQueue special-cases:
 * they are matched by method name and flip is_paused rather than being
 * dispatched to a tracker (there is no tracker method by these names). They are
 * the documented way a site temporarily stops and resumes tracking without
 * tearing down the tracker. The single pause/unpause test above covers the happy
 * gate/resume path; these pin the subtler contracts a refactor could quietly
 * break:
 *
 *   - a paused queue is fully inert — it doesn't even lazily create a tracker;
 *   - commands issued while paused are DROPPED, not buffered — unpausing does not
 *     replay them;
 *   - pausing mid-drain halts the rest of a process() run;
 *   - the pause/unpause method name is honored even under a dotted, named-tracker
 *     command, so it gates the whole queue regardless of the object prefix.
 */
describe('CommandQueue pause-owa / unpause-owa (stop / start)', () => {

    afterEach(() => {
        delete window.OWATracker;
        delete window.OWATracker2;
    });

    test('pause-owa as the first command does not even instantiate a tracker', () => {
        // A paused queue is inert. pause-owa is handled before the lazy-init
        // block, and that block is skipped while paused — so no global tracker is
        // created (which matters: there is no OWATracker.pause-owa method to call).
        expect(window.OWATracker).toBeUndefined();

        const q = new CommandQueue();
        q.push(['pause-owa']);

        expect(q.is_paused).toBe(true);
        expect(window.OWATracker).toBeUndefined();
    });

    test('commands issued while paused are dropped, not buffered for replay', () => {
        const calls = [];
        window.OWATracker = {
            setSiteId(id) { calls.push('setSiteId:' + id); },
            trackPageView() { calls.push('trackPageView'); },
        };

        const q = new CommandQueue();
        q.push(['pause-owa']);
        q.push(['setSiteId', 'while-paused']);   // suppressed
        q.push(['trackPageView']);               // suppressed
        expect(calls).toEqual([]);
        // The suppressed commands were discarded, not queued onto asyncCmds...
        expect(q.asyncCmds).toHaveLength(0);

        // ...so unpausing resumes tracking but does NOT replay what was dropped.
        q.push(['unpause-owa']);
        expect(calls).toEqual([]);
        q.push(['trackPageView']);
        expect(calls).toEqual(['trackPageView']);
    });

    test('pausing mid-array halts the remainder of a process() drain', () => {
        const calls = [];
        window.OWATracker = {
            setSiteId(id) { calls.push('setSiteId:' + id); },
            trackPageView() { calls.push('trackPageView'); },
            trackClicks() { calls.push('trackClicks'); },
        };

        const q = new CommandQueue();
        // pause-owa sits between the first and later commands: everything after it
        // in the drain is suppressed because the queue is now paused.
        q.loadCmds([
            ['setSiteId', 'drain-site'],
            ['pause-owa'],
            ['trackPageView'],
            ['trackClicks'],
        ]);
        q.process();

        expect(calls).toEqual(['setSiteId:drain-site']);
        expect(q.is_paused).toBe(true);
        // The whole queue was consumed by the drain even though later commands
        // were gated out (they were shifted off and dropped, not left pending).
        expect(q.asyncCmds).toHaveLength(0);
    });

    test('a dotted Name.pause-owa gates the whole queue and creates no tracker', () => {
        // The queue parses the method name out of a dotted command before the
        // pause check, so 'OWATracker2.pause-owa' pauses globally — and because a
        // paused queue skips lazy-init, the named tracker is never created either.
        expect(window.OWATracker2).toBeUndefined();

        const q = new CommandQueue();
        q.push(['OWATracker2.pause-owa']);

        expect(q.is_paused).toBe(true);
        expect(window.OWATracker2).toBeUndefined();
    });
});
