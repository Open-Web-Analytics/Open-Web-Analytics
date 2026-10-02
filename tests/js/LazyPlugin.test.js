import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * A lazy plugin (OWATracker.registerLazyPlugin): its commands exist before its
 * chunk does, the first call loads the chunk, and the calls made meanwhile run
 * once it has registered -- on trackers built before it arrived.
 */

function lazy(name, onLoad) {
    let loads = 0;

    OWATracker.registerLazyPlugin({
        name: name,
        methods: ['run' + name],
        reservedEventNames: [name + '_event'],
        load: () => {
            loads++;
            onLoad();
            return Promise.resolve();
        },
    });

    return () => loads;
}

const flush = () => new Promise((r) => setTimeout(r, 0));

test('the command exists before the chunk, and a page that never calls it never loads it', () => {
    const loads = lazy('Unused', () => {});
    const tracker = new OWATracker({ cookie_domain_set: true });

    expect(typeof tracker.runUnused).toBe('function');
    expect(loads()).toBe(0);
});

test('the first call loads the chunk once, and every queued call runs after it registers', async () => {
    const calls = [];
    const inits = [];

    const loads = lazy('Queued', () => {
        OWATracker.registerPlugin({
            name: 'Queued',
            methods: { runQueued(arg) { calls.push([this, arg]); } },
            init(tracker) { inits.push(tracker); },
        });
    });

    const tracker = new OWATracker({ cookie_domain_set: true });

    tracker.runQueued('a');
    tracker.runQueued('b');

    expect(calls).toEqual([]);

    await flush();

    expect(loads()).toBe(1);
    expect(calls).toEqual([[tracker, 'a'], [tracker, 'b']]);
    expect(inits).toContain(tracker);

    // Now the real method: no queue, no second load.
    tracker.runQueued('c');
    expect(calls[2]).toEqual([tracker, 'c']);
    expect(loads()).toBe(1);
});

test('a lazy plugin reserves its event names before it loads', () => {
    lazy('Reserved', () => {});

    expect(OWATracker.RESERVED_EVENT_NAMES).toContain('Reserved_event');
});

test('a chunk that fails to load leaves the queue rather than throwing', async () => {
    OWATracker.registerLazyPlugin({
        name: 'Broken',
        methods: ['runBroken'],
        load: () => Promise.reject(new Error('offline')),
    });

    const tracker = new OWATracker({ cookie_domain_set: true });

    expect(() => tracker.runBroken()).not.toThrow();
    await flush();
    expect(OWATracker.prototype.runBroken.owaLazyStub).toBe(true);
});

test('a page view sent before the chunk arrived is visible to it', async () => {
    let seen = null;

    lazy('Late', () => {
        OWATracker.registerPlugin({
            name: 'Late',
            methods: { runLate() {} },
            init(tracker) { seen = tracker.lastPageView ? tracker.lastPageView.get('event_type') : null; },
        });
    });

    window.owa_baseUrl = 'https://owa.example.test/';
    navigator.sendBeacon = () => true;

    const tracker = new OWATracker({ cookie_domain_set: true });
    tracker.setSiteId('late-site');
    tracker.trackPageView('https://site.example/p');
    tracker.runLate();

    await flush();

    expect(seen).toBe('page_view');
    delete window.owa_baseUrl;
});
