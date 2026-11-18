import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';

/**
 * A command the tracker does not implement must not stop the ones that do.
 *
 * An unguarded window[obj][method].apply() would throw a TypeError for an
 * unknown name, and that throw would escape process(), abandoning every
 * command still queued behind it -- trackPageView, which the snippet pushes
 * LAST, among them.
 *
 * This is a version-skew problem, not a typo problem. The snippet is generated
 * server-side and changes the moment PHP is upgraded; the tracker is a
 * long-cached static file a returning visitor may hold for days. So a new
 * snippet command reaches some browsers running a tracker too old to have it.
 */

function installImageSpy() {
    const sent = [];
    const Orig = global.Image;
    global.Image = class { set src(v) { sent.push(v); } };
    return { sent, restore: () => { global.Image = Orig; } };
}

beforeEach(() => {
    window.owa_baseUrl = 'https://owa.example.test/';
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return 'site.example'; },
    });
});

afterEach(() => {
    delete window.owa_baseUrl;
    delete window.OWATracker;
});

describe('a command that throws is skipped, not fatal', () => {

    /**
     * The unknown-method guard covers one cause. An exception raised inside a
     * command the tracker DOES implement escapes process() the same way and
     * abandons the rest of the queue for the same reason, so the guarantee is
     * only real if the dispatch itself is wrapped.
     *
     * Not exotic, either: constructing the tracker can throw on a hostile
     * document.domain, a known command can throw on an argument shape it did not
     * expect, and an extension can replace a global out from under us.
     */
    test('trackPageView still runs after a known command throws', () => {
        const spy = installImageSpy();

        try {
            const q = new CommandQueue();
            q.push(['setSiteId', 'throw-site']);

            // A method that exists and blows up, which is the case the
            // typeof guard cannot catch.
            window.OWATracker.setCustomVar = () => {
                throw new TypeError('something went wrong inside a real command');
            };

            q.loadCmds([
                ['setCustomVar', 1, 'k', 'v', 'session'],
                ['trackPageView'],
            ]);
            q.process();

            const pageview = spy.sent.find((u) => /[?&]e_t=page_view/.test(u));

            expect(pageview).toBeTruthy();
            expect(pageview).toMatch(/[?&]site=throw-site/);
        } finally {
            spy.restore();
        }
    });

    test('the throwing command does not escape to the caller', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'throw-site']);

        window.OWATracker.setCustomVar = () => { throw new Error('boom'); };

        expect(() => q.push(['setCustomVar', 1, 'k', 'v', 'session'])).not.toThrow();
    });

    test('the queue is fully drained even when a command throws', () => {
        const spy = installImageSpy();

        try {
            const q = new CommandQueue();
            q.push(['setSiteId', 'throw-site']);
            window.OWATracker.setCustomVar = () => { throw new Error('boom'); };

            q.loadCmds([
                ['setCustomVar', 1, 'k', 'v', 'session'],
                ['trackPageView'],
            ]);
            q.process();

            expect(q.asyncCmds).toHaveLength(0);
        } finally {
            spy.restore();
        }
    });
});

describe('an unknown command is skipped, not fatal', () => {

    test('it does not throw', () => {
        const q = new CommandQueue();

        expect(() => q.push(['setSiteId', 'skew-site'])).not.toThrow();
        expect(() => q.push(['aCommandFromANewerSnippet', false])).not.toThrow();
    });

    test('trackPageView queued behind it still runs', () => {
        const spy = installImageSpy();

        try {
            const q = new CommandQueue();

            // A snippet from a NEWER OWA than this tracker: it carries a command
            // this build has never heard of, and then the page view.
            q.loadCmds([
                ['setSiteId', 'skew-site'],
                ['aCommandFromANewerSnippet', { v: 90 }],
                ['trackPageView'],
            ]);
            q.process();

            const pageview = spy.sent.find((u) => /[?&]e_t=page_view/.test(u));

            // The page view survived the unknown command in front of it.
            expect(pageview).toBeTruthy();
            expect(pageview).toMatch(/[?&]site=skew-site/);
        } finally {
            spy.restore();
        }
    });

    test('the queue is fully drained rather than abandoned midway', () => {
        const spy = installImageSpy();

        try {
            const q = new CommandQueue();
            q.loadCmds([
                ['setSiteId', 'skew-site'],
                ['aCommandFromANewerSnippet'],
                ['trackPageView'],
            ]);
            q.process();

            expect(q.asyncCmds).toHaveLength(0);
        } finally {
            spy.restore();
        }
    });

    test('a known command after an unknown one is still applied', () => {
        const q = new CommandQueue();

        q.loadCmds([
            ['setSiteId', 'skew-site'],
            ['aCommandFromANewerSnippet'],
            ['setCustomVar', 1, 'k', 'v', 'session'],
        ]);
        q.process();

        expect(window.OWATracker).toBeDefined();
        expect(window.OWATracker.siteId).toBe('skew-site');
    });
});
