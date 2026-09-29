jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';

/**
 * A queued setDebug is in force before the tracker it configures is built.
 *
 * The first command builds the tracker and is applied only afterwards, so
 * setDebug -- even first in the queue, where a development snippet puts it --
 * came too late for everything logged while the tracker started. Those lines
 * were the ones worth having when a site's tracking does not start.
 */
describe('setDebug in the queue', () => {

    let logged;
    let spy;

    beforeEach(() => {
        window.owa_baseUrl = 'https://owa.example.test/';
        Object.defineProperty(document, 'domain', { configurable: true, get() { return 'site.example'; } });
        OWA.setSetting('debug', false);
        logged = [];
        spy = jest.spyOn(console, 'log').mockImplementation((...args) => logged.push(args.join(' ')));
    });

    afterEach(() => {
        spy.mockRestore();
        OWA.setSetting('debug', false);
        delete window.owa_baseUrl;
        delete window.OWATracker;
    });

    test('is on while the tracker is being built', () => {
        const q = new CommandQueue();
        q.loadCmds([
            ['setDebug', true],
            ['setSiteId', 'debug-site'],
            ['trackPageView'],
        ]);

        // Nothing has run yet: the setting is in force from the load alone.
        expect(OWA.getSetting('debug')).toBe(true);

        q.process();

        // The queue logs each command's name as it applies it, and the first
        // of those is logged while the tracker is being built for setDebug.
        expect(logged.some((l) => l.includes('cmd queue object method name') && l.includes('setDebug')))
            .toBe(true);
    });

    test('found wherever it sits in the queue', () => {
        const q = new CommandQueue();
        q.loadCmds([['setSiteId', 'debug-site'], ['setDebug', true]]);

        expect(OWA.getSetting('debug')).toBe(true);
    });

    test('a queue without it leaves debug off', () => {
        const q = new CommandQueue();
        q.loadCmds([['setSiteId', 'quiet-site']]);
        q.process();

        expect(OWA.getSetting('debug')).toBe(false);
        expect(logged).toEqual([]);
    });
});
