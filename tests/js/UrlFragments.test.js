import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * The #fragment is not part of a page's URL.
 *
 * Stripped in the TRACKER rather than at ingest, which is where GA does it:
 * page_location defaults to location.href and "the default value excludes the
 * fragment portion of the URL". So the hash never reaches the wire instead of
 * being removed by a server that has already received it.
 *
 * THE TWO HALVES HAVE TO AGREE, and that is what most of this file is about. A
 * fragment left out of the reported URL but kept in the route comparison gives
 * a hash-routed site one page view per anchor click, every one of them
 * reporting the same URL -- which is worse than either behaviour on its own.
 * One option moves both.
 */

function newTracker(options) {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.frag.example' });
    t.setSiteId('fragment-site');

    if (options && options.fragments) {
        t.setTrackUrlFragments(true);
    }

    return t;
}

function captureSends(t) {
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); };
    return sent;
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
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

describe('a hash change is not a route change', () => {

    test('an anchor click raises no page view', () => {
        // Already ON the page: the only thing that changes is the anchor.
        window.history.replaceState({}, '', '/pricing');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/pricing#faq');

        expect(sent.filter(e => e.event_type === 'base.page_request')).toHaveLength(0);
    });

    test('but a real route change still does', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/features');

        expect(sent.filter(e => e.event_type === 'base.page_request')).toHaveLength(1);
    });

    test('moving between anchors on one page raises nothing', () => {
        window.history.replaceState({}, '', '/docs#one');

        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/docs#two');
        window.history.pushState({}, '', '/docs#three');

        expect(sent.filter(e => e.event_type === 'base.page_request')).toHaveLength(0);
    });
});

describe('a site that routes on the hash turns both halves back on', () => {

    test('the fragment comes back into the URL', () => {
        window.history.replaceState({}, '', '/app#/settings');

        expect(newTracker({ fragments: true }).getCurrentUrl()).toContain('#/settings');
    });

    test('and hash navigation becomes page views again', () => {
        window.history.replaceState({}, '', '/app#/users');

        const t = newTracker({ fragments: true });
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/app#/settings');

        const views = sent.filter(e => e.event_type === 'base.page_request');

        expect(views).toHaveLength(1);
        expect(views[0].page_location).toContain('#/settings');
    });

    test('the page views can be told apart, which is the point of moving both together', () => {
        window.history.replaceState({}, '', '/app#/one');

        const t = newTracker({ fragments: true });
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/app#/two');
        window.history.pushState({}, '', '/app#/three');

        const urls = sent
            .filter(e => e.event_type === 'base.page_request')
            .map(e => e.page_location);

        expect(urls).toHaveLength(2);
        expect(new Set(urls).size).toBe(2);
    });
});
