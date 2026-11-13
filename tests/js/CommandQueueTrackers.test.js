/**
 * The command queue and the trackers it creates.
 *
 * - Every tracker the queue creates starts its default features, not only
 *   OWATracker: a named tracker for a second site used to start none.
 * - A tracker created after the page's queue was processed starts them once
 *   the command that created it has run.
 * - A second copy of the bundle on the page leaves the live queue alone.
 */
import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';

global.__webpack_public_path__ = '';

let savedWebdriver;

beforeEach(() => {
    window.owa_baseUrl = 'https://owa.example.test/';
    Object.defineProperty(document, 'domain', { configurable: true, get() { return 'site.example'; } });
    savedWebdriver = Object.getOwnPropertyDescriptor(navigator, 'webdriver');
    Object.defineProperty(navigator, 'webdriver', { configurable: true, get() { return false; } });
});

afterEach(() => {
    if (savedWebdriver) { Object.defineProperty(navigator, 'webdriver', savedWebdriver); } else { delete navigator.webdriver; }
    delete window.owa_baseUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    delete window.SecondSite;
    delete window.LateSite;
    jest.restoreAllMocks();
    jest.resetModules();
});

describe('every tracker the queue creates starts its features', () => {

    test('a named tracker starts them along with OWATracker', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'first-site']);
        q.push(['SecondSite.setSiteId', 'second-site']);

        const first  = jest.spyOn(window.OWATracker, 'startFeatures').mockImplementation(() => {});
        const second = jest.spyOn(window.SecondSite, 'startFeatures').mockImplementation(() => {});

        q.startFeatures();

        expect(first).toHaveBeenCalledTimes(1);
        expect(second).toHaveBeenCalledTimes(1);
    });

    test('a tracker created afterwards starts them once its command has run', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'first-site']);
        q.startFeatures();

        q.push(['LateSite.setSiteId', 'late-site']);

        expect(window.LateSite.getSiteId()).toBe('late-site');
        expect(window.LateSite.featuresStarted).toBe(true);
    });

    test('before the page queue is processed, nothing starts early', () => {
        const q = new CommandQueue();

        q.push(['SecondSite.setSiteId', 'second-site']);

        expect(window.SecondSite.featuresStarted).toBe(false);
    });
});

describe('a second copy of the bundle on one page', () => {

    function load(cmds) {
        global.owa_cmds = cmds;
        window.owa_cmds = cmds;
        jest.isolateModules(() => {
            require('../../modules/Base/src/tracker/tracker-dom.js');
        });
    }

    test('leaves the live queue in place, and commands pushed afterwards still run', () => {
        load([['setSiteId', 'twice-loaded']]);

        const live = window.owa_cmds;
        expect(typeof live.process).toBe('function');

        // The second copy finds the queue, not an array.
        global.owa_cmds = live;
        expect(() => jest.isolateModules(() => {
            require('../../modules/Base/src/tracker/tracker-dom.js');
        })).not.toThrow();

        expect(window.owa_cmds).toBe(live);

        window.owa_cmds.push(['setSiteId', 'after-second-load']);
        expect(window.OWATracker.getSiteId()).toBe('after-second-load');
    });
});
