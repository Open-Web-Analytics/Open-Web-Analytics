import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * A page can add filters and actions through owa_cmds (#858).
 *
 * The 1.x form, ['OWA.addFilter', ...], stopped working with the move to ES
 * modules: there was no window.OWA, so the queue built a second tracker named
 * OWA and skipped addFilter as a command that tracker did not have. Both the
 * 1.x form and a plain ['addFilter', ...] now reach the shared OWA instance.
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
    OWA.hooks.filters = {};
    OWA.hooks.actions = {};
});

function run(cmds) {
    const q = new CommandQueue();
    q.loadCmds([['setSiteId', 'hook-site'], ...cmds, ['trackPageView']]);
    q.process();
}

describe('filters and actions from owa_cmds', () => {

    test('OWA.addFilter, the 1.x form, filters the page view', () => {
        const seen = [];

        run([['OWA.addFilter', 'tracker.log_event_properties', (props) => {
            seen.push(props.event_type);
            return props;
        }, 10]]);

        expect(seen).toContain('page_view');
        expect(spy.sent.some((u) => /[?&]e_t=page_view/.test(u))).toBe(true);
    });

    test('it makes no tracker named OWA', () => {
        run([['OWA.addFilter', 'tracker.log_event_properties', (props) => props]]);

        expect(window.OWA).toBeUndefined();
    });

    test('addFilter on the tracker reaches the same filters', () => {
        let called = 0;

        run([['addFilter', 'tracker.log_event_properties', (props) => { called++; return props; }]]);

        expect(called).toBeGreaterThan(0);
    });

    test('addAction hears the page view', () => {
        const heard = [];

        run([['addAction', 'tracker.pageView', (args) => heard.push(args.event.get('event_type'))]]);

        expect(heard).toContain('page_view');
    });

    test('an unknown OWA command is skipped, and tracking goes on', () => {
        run([['OWA.noSuchThing', 1]]);

        expect(window.OWA).toBeUndefined();
        expect(spy.sent.some((u) => /[?&]e_t=page_view/.test(u))).toBe(true);
    });
});
