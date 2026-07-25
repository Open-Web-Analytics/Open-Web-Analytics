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
            expect(url).toContain('owa_page_url=https://site.example/queued');
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
