import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Uncaught errors, as exception events.
 *
 * Two things kept these from meaning "my site is broken": Chrome's
 * ResizeObserver loop notice, which is not an error and which a crawler that
 * resizes its window raises dozens of times a page, and the same error sent
 * again every time a loop or a callback raised it.
 */

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.ex.example' });
    t.setSiteId('ex-site');
    return t;
}

function captureSends(t) {
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); };
    return sent;
}

function exceptions(sent) {
    return sent.filter(e => e.event_type === 'exception')
        .map(e => [e.eps_description, e.eps_source, e.epn_line]);
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
    window.onerror = null;
});

afterEach(() => {
    window.onerror = null;
});

describe('trackExceptions', () => {

    test('an uncaught error is sent with its message, source and line', () => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        window.onerror('Uncaught TypeError: x is not a function', 'https://ex.example/a.js', 12);

        expect(exceptions(sent)).toEqual([
            ['Uncaught TypeError: x is not a function', 'https://ex.example/a.js', 12],
        ]);
    });

    test.each([
        'ResizeObserver loop completed with undelivered notifications.',
        'ResizeObserver loop limit exceeded',
    ])('the ResizeObserver loop notice is not sent: %s', (message) => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        window.onerror(message, 'https://ex.example/', 0);

        expect(exceptions(sent)).toEqual([]);
    });

    test('an error that only mentions ResizeObserver is still sent', () => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        window.onerror('Uncaught ReferenceError: ResizeObserver is not defined', 'https://ex.example/a.js', 3);

        expect(exceptions(sent)).toHaveLength(1);
    });

    test('the same error raised again on the same page view is sent once', () => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        for (let i = 0; i < 5; i++) {
            window.onerror('Uncaught TypeError: x is not a function', 'https://ex.example/a.js', 12);
        }

        expect(exceptions(sent)).toHaveLength(1);
    });

    test('a different message, source or line is a different error', () => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        window.onerror('Error A', 'https://ex.example/a.js', 12);
        window.onerror('Error B', 'https://ex.example/a.js', 12);
        window.onerror('Error A', 'https://ex.example/b.js', 12);
        window.onerror('Error A', 'https://ex.example/a.js', 13);

        expect(exceptions(sent)).toHaveLength(4);
    });

    test('a page view sends an error again that the previous one sent', () => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        t.trackPageView('https://ex.example/one');
        window.onerror('Error A', 'https://ex.example/a.js', 12);
        window.onerror('Error A', 'https://ex.example/a.js', 12);
        t.trackPageView('https://ex.example/two');
        window.onerror('Error A', 'https://ex.example/a.js', 12);

        expect(exceptions(sent)).toHaveLength(2);
    });

    test('a missing source and line are sent as empty and 0', () => {
        const t = newTracker();
        const sent = captureSends(t);
        t.trackExceptions();

        window.onerror('Script error.');

        expect(exceptions(sent)).toEqual([['Script error.', '', 0]]);
    });

    test('the page\'s own handler still runs, for every error including skipped ones', () => {
        const calls = [];
        window.onerror = (message) => { calls.push(message); return true; };

        const t = newTracker();
        captureSends(t);
        t.trackExceptions();

        expect(window.onerror('Error A', 'https://ex.example/a.js', 1)).toBe(true);
        window.onerror('Error A', 'https://ex.example/a.js', 1);
        window.onerror('ResizeObserver loop limit exceeded', '', 0);

        expect(calls).toEqual(['Error A', 'Error A', 'ResizeObserver loop limit exceeded']);
    });
});
