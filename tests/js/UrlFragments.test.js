import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * The #fragment is not part of a page's URL.
 *
 * Stripped in the TRACKER rather than at ingest, so the hash never reaches the
 * wire instead of being removed by a server that has already received it.
 *
 * The reported URL and the route comparison both leave it out, so a
 * fragment-only change is not a route change. There is no option to keep it:
 * a hash-routed site sends each route with trackPageView('/path').
 */

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.frag.example' });
    t.setSiteId('fragment-site');

    return t;
}

function captureSends(t) {
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); };
    return sent;
}

beforeEach(() => {
    OWA.setSetting('loggerPause', false);
    window.history.replaceState({}, '', '/start');
});

describe('the fragment and the current URL', () => {

    test('getCurrentUrl leaves the fragment out', () => {
        window.history.replaceState({}, '', '/pricing#faq');

        expect(newTracker().getCurrentUrl()).not.toContain('#');
        expect(newTracker().getCurrentUrl()).toContain('/pricing');
    });

    test('a URL with no fragment is untouched', () => {
        window.history.replaceState({}, '', '/pricing?plan=pro');

        const url = newTracker().getCurrentUrl();

        expect(url).toContain('/pricing');
        expect(url).toContain('plan=pro');
    });

    test('the query survives; only the fragment goes', () => {
        window.history.replaceState({}, '', '/search?q=hats#results');

        const url = newTracker().getCurrentUrl();

        expect(url).toContain('q=hats');
        expect(url).not.toContain('results');
    });

    test('an empty fragment takes the hash with it', () => {
        window.history.replaceState({}, '', '/pricing#');

        expect(newTracker().getCurrentUrl()).not.toContain('#');
    });

    test('page_location is what goes on the wire, so it has no fragment', () => {
        window.history.replaceState({}, '', '/pricing#faq');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackPageView();

        expect(sent).toHaveLength(1);
        expect(sent[0].page_location).toBeDefined();
        expect(sent[0].page_location).not.toContain('#faq');
    });
});

/** Let a route change settle, which is when its page view is sent. */
const settle = () => jest.advanceTimersByTime(OWATracker.ROUTE_SETTLE_MSEC);

describe('a hash change is not a route change', () => {

    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    test('an anchor click raises no page view', () => {
        // Already ON the page: the only thing that changes is the anchor.
        window.history.replaceState({}, '', '/pricing');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/pricing#faq');
        settle();

        expect(sent.filter(e => e.event_type === 'page_view')).toHaveLength(0);
    });

    test('but a real route change still does', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/features');
        settle();

        expect(sent.filter(e => e.event_type === 'page_view')).toHaveLength(1);
    });

    test('moving between anchors on one page raises nothing', () => {
        window.history.replaceState({}, '', '/docs#one');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/docs#two');
        window.history.pushState({}, '', '/docs#three');
        settle();

        expect(sent.filter(e => e.event_type === 'page_view')).toHaveLength(0);
    });
});

describe('a site that routes on the hash', () => {

    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    test('setTrackUrlFragments is gone, and a trackUrlFragments option keeps nothing', () => {
        expect(OWATracker.prototype.setTrackUrlFragments).toBeUndefined();

        window.history.replaceState({}, '', '/app#/settings');

        const t = newTracker();
        t.setOption('trackUrlFragments', true);

        expect(t.getCurrentUrl()).not.toContain('#');
    });

    test('a hashchange to a new route sends no page view', () => {
        window.history.replaceState({}, '', '/app#/users');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.location.hash = '#/settings';
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        window.dispatchEvent(new PopStateEvent('popstate'));
        settle();

        expect(sent.filter(e => e.event_type === 'page_view')).toHaveLength(0);
    });

    test('each route sent with trackPageView(path) is its own page view', () => {
        window.history.replaceState({}, '', '/app#/one');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackPageView('/one');
        t.trackPageView('/two');

        const urls = sent
            .filter(e => e.event_type === 'page_view')
            .map(e => e.page_location);

        expect(urls).toEqual(['/one', '/two']);
    });
});
