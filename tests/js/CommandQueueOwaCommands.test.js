import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * A command addressed to OWA is skipped, and makes no tracker (#858).
 *
 * Before the tracker became ES modules, ['OWA.addFilter', ...] reached a global
 * OWA. With no window.OWA, the queue read OWA as a tracker's name: it built a
 * second tracker called OWA, then skipped addFilter as a command that tracker
 * did not have. The queue offers no OWA commands, so the command is skipped,
 * and only that.
 */

function installImageSpy() {
    const sent = [];
    const Orig = global.Image;
    global.Image = class { set src(v) { sent.push(v); } };
    return { sent, restore: () => { global.Image = Orig; } };
}

let spy;

beforeEach(() => {
    window.owa_baseUrl = 'https://owa.example.test/';
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return 'site.example'; },
    });
    spy = installImageSpy();
});

afterEach(() => {
    spy.restore();
    delete window.owa_baseUrl;
    delete window.OWATracker;
    delete window.OWA;
    delete window.siteB;
    OWA.hooks.filters = {};
});

function run(cmds) {
    const q = new CommandQueue();
    q.loadCmds([['setSiteId', 'hook-site'], ...cmds, ['trackPageView']]);
    q.process();
}

describe('a command addressed to OWA', () => {

    test('makes no tracker named OWA', () => {
        run([['OWA.addFilter', 'tracker.log_event_properties', (props) => props, 10]]);

        expect(window.OWA).toBeUndefined();
    });

    test('registers nothing', () => {
        let called = 0;

        run([['OWA.addFilter', 'tracker.log_event_properties', (props) => { called++; return props; }]]);

        expect(called).toBe(0);
    });

    /** Only the one name is reserved: the queue still names trackers. */
    test('leaves named trackers alone', () => {
        run([['siteB.setSiteId', 'site-b'], ['siteB.trackPageView']]);

        expect(typeof window.siteB.trackPageView).toBe('function');
        expect(spy.sent.some((u) => /[?&]site_id=site-b/.test(u) && /event_type=base\.page_request/.test(u))).toBe(true);
    });

    test('does not stop the page view', () => {
        run([['OWA.addFilter', 'tracker.log_event_properties', (props) => props], ['OWA.noSuchThing', 1]]);

        expect(spy.sent.filter((u) => /event_type=base\.page_request/.test(u))).toHaveLength(1);
    });
});
