import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Engagement deltas and the page lifecycle.
 *
 * The tracker bound NONE of these events before -- visibilitychange, pagehide,
 * pageshow, popstate and hashchange had zero occurrences in Tracker.js -- so
 * there was no engagement measurement, no SPA page view and no bfcache
 * handling. These pin the three decisions that are easy to undo: that what goes
 * on the wire is a DELTA, that hidden time does not count, and that a route
 * change is a page view while a bfcache restore is not.
 */

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.eng.example' });
    t.setSiteId('engagement-site');
    return t;
}

/** Capture what reaches the innermost send, without a transport. */
function captureSends(t) {
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); };
    return sent;
}

/** Move the tracker's clock without waiting for real time to pass. */
function atTime(t, ms) {
    t.getTime = () => ms;
}

let hidden;

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);

    hidden = 'visible';
    // jsdom answers false; a page being read has focus.
    document.hasFocus = () => true;
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        get() { return hidden; },
    });
});

describe('engagement deltas', () => {

    test('the delta is consumed, so the same milliseconds cannot ride two beacons', () => {
        const t = newTracker();

        atTime(t, 1000);
        t.resetEngagement();

        atTime(t, 4000);
        expect(t.consumeEngagementDelta()).toBe(3000);

        // Nothing new has accrued.
        expect(t.consumeEngagementDelta()).toBe(0);

        atTime(t, 4500);
        expect(t.consumeEngagementDelta()).toBe(500);
    });

    test('hidden time does not count', () => {
        const t = newTracker();

        atTime(t, 1000);
        t.resetEngagement();

        atTime(t, 3000);
        t.pauseEngagement();

        // An hour in a background tab.
        atTime(t, 3000 + 3600000);
        t.startEngagement();

        atTime(t, 3000 + 3600000 + 500);

        expect(t.consumeEngagementDelta()).toBe(2500,
            'Two seconds before the hide and half a second after it -- not the hour between.');
    });

    test('the residue is delivered once, at hide, as a user_engagement event', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 1000);
        t.resetEngagement();
        atTime(t, 9000);

        t.trackEngagement();

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('user_engagement');
        expect(sent[0].engagement_msec).toBe(8000);

        // A second hide with nothing accrued sends nothing: the residue is
        // delivered once, and an empty one is not an event.
        t.trackEngagement();
        expect(sent).toHaveLength(1);
    });

    test('an ordinary event carries the delta too, so losing one beacon costs one increment', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 1000);
        t.resetEngagement();
        atTime(t, 2500);

        t.trackCustomEvent('signup');

        expect(sent[0].engagement_msec).toBe(1500);
    });

    /*
     * What accrues before the first page view is the gap between the tracker
     * being built and the snippet asking for one -- not reading time. The clock
     * restarts there.
     */
    test('the first page view carries no engagement, and the clock restarts at it', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 1000);
        t.resetEngagement();
        atTime(t, 2500);

        t.trackPageView('https://eng.example/a');
        expect(sent[0].engagement_msec).toBeUndefined();

        atTime(t, 4000);
        t.trackCustomEvent('signup');
        expect(sent[1].engagement_msec).toBe(1500, 'counted from the page view, not from 1000');
    });

    test('a later page view carries what accrued since the last report', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();
        t.trackPageView('https://eng.example/a');

        atTime(t, 3000);
        t.trackPageView('https://eng.example/b');

        expect(sent[1].engagement_msec).toBe(3000);
    });

    /*
     * UNDER A SECOND IS NOT AN EVENT. The residue is not dropped, though: it
     * stays unreported and the next event carries it.
     */
    test('a residue under one second sends nothing, and rides the next event', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();

        atTime(t, 500);
        t.trackEngagement();
        expect(sent).toHaveLength(0);

        t.startEngagement();
        atTime(t, 1500);
        t.trackCustomEvent('signup');

        expect(sent[0].engagement_msec).toBe(1500, 'the 500 ms before the hide, and the second after it');
    });

    test('one second exactly is sent', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();
        atTime(t, 1000);
        t.trackEngagement();

        expect(sent).toHaveLength(1);
        expect(sent[0].engagement_msec).toBe(1000);
    });

    test('an event carries no engagement when none has accrued', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 1000);
        t.resetEngagement();

        t.trackPageView('https://eng.example/a');

        expect(sent[0].engagement_msec).toBeUndefined();
    });
});

describe('page lifecycle', () => {

    /*
     * A visible window behind another application, or on a second monitor, is
     * not being read. Blur pauses the clock and sends nothing; focus resumes it.
     */
    test('blur pauses the clock without sending; focus resumes it', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();

        atTime(t, 3000);
        window.dispatchEvent(new Event('blur'));
        expect(sent).toHaveLength(0, 'a blur is a pause, not a report');

        atTime(t, 13000);
        window.dispatchEvent(new Event('focus'));

        atTime(t, 15000);
        hidden = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));

        expect(sent).toHaveLength(1);
        expect(sent[0].engagement_msec).toBe(5000, 'three seconds before the blur and two after the focus');
    });

    test('a page loaded without focus does not count until it gets focus', () => {
        document.hasFocus = () => false;

        let now = 0;
        const clock = jest.spyOn(OWATracker.prototype, 'getTime').mockImplementation(() => now);

        try {
            const t = newTracker();
            const sent = captureSends(t);

            now = 10000;
            window.dispatchEvent(new Event('focus'));

            now = 12000;
            hidden = 'hidden';
            document.dispatchEvent(new Event('visibilitychange'));

            expect(sent[0].engagement_msec).toBe(2000, 'counted from the focus, not from the load');
        } finally {
            clock.mockRestore();
        }
    });

    /*
     * THE PRODUCTION PATH. A tracker built the way a page builds it -- the
     * constructor and the snippet's commands, nothing else -- must measure
     * engagement. Nothing called bindPageLifecycleEvents() outside these tests,
     * which called it by hand, so on a real page the clock never started and no
     * user_engagement was ever sent.
     */
    test('a tracker built the way a page builds it sends the residue on hide', () => {
        let now = 1000;
        const clock = jest.spyOn(OWATracker.prototype, 'getTime').mockImplementation(() => now);

        try {
            const t = newTracker();
            const sent = captureSends(t);

            for (const cmd of ['trackPageView', 'trackClicks', 'trackForms', 'trackScroll', 'trackSiteSearch']) {
                t[cmd]();
            }

            now = 31000;
            hidden = 'hidden';
            document.dispatchEvent(new Event('visibilitychange'));

            const engagement = sent.filter(e => e.event_type === 'user_engagement');
            expect(engagement).toHaveLength(1);
            expect(engagement[0].engagement_msec).toBe(30000);
        } finally {
            clock.mockRestore();
        }
    });

    test('binding is once per tracker, so a hide is not sent twice', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();
        t.bindPageLifecycleEvents();
        t.bindPageLifecycleEvents();

        atTime(t, 4000);
        hidden = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));

        expect(sent.filter(e => e.event_type === 'user_engagement')).toHaveLength(1);
    });

    test('hiding the page delivers the residue; showing it restarts the clock', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();

        atTime(t, 5000);
        hidden = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('user_engagement');
        expect(sent[0].engagement_msec).toBe(5000);

        // Away for a while, then back.
        atTime(t, 60000);
        hidden = 'visible';
        document.dispatchEvent(new Event('visibilitychange'));

        atTime(t, 62000);
        hidden = 'hidden';
        document.dispatchEvent(new Event('visibilitychange'));

        expect(sent).toHaveLength(2);
        expect(sent[1].engagement_msec).toBe(2000,
            'The 55 seconds the page was hidden are not reading time.');
    });

    test('a bfcache restore resumes the clock and does NOT count a page view', () => {
        const t = newTracker();
        const sent = captureSends(t);

        atTime(t, 0);
        t.resetEngagement();

        atTime(t, 1000);
        window.dispatchEvent(new Event('pagehide'));
        sent.length = 0;

        const restore = new Event('pageshow');
        Object.defineProperty(restore, 'persisted', { value: true });

        atTime(t, 30000);
        window.dispatchEvent(restore);

        expect(sent).toHaveLength(0,
            'The URL has not changed and the session has not restarted -- counting a page '
          + 'view here would inflate pageviews on every back button.');

        atTime(t, 33000);
        expect(t.consumeEngagementDelta()).toBe(3000,
            'The clock restarted at the restore, not at the hide.');
    });

    test('a fresh pageshow that is not a restore does nothing', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.resetEngagement();
        window.dispatchEvent(new Event('pageshow'));

        expect(sent).toHaveLength(0);
    });
});

describe('SPA route changes', () => {

    test('pushState is a page view', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.trackRouteChanges();

        window.history.pushState({}, '', '/route-b');

        const views = sent.filter(e => e.event_type === 'page_view');
        expect(views).toHaveLength(1);
        expect(views[0].page_url).toContain('/route-b');
    });

    test('a replaceState that does not change the URL is not a route change', () => {
        const t = newTracker();

        window.history.pushState({}, '', '/route-c');

        const sent = captureSends(t);
        t.trackRouteChanges();

        window.history.replaceState({ filter: 'x' }, '', '/route-c');

        expect(sent.filter(e => e.event_type === 'page_view')).toHaveLength(0,
            'replaceState is used to store UI state, and each of those is not a page view.');
    });

    test('the engagement of the route being LEFT lands before the new page view', () => {
        const t = newTracker();

        window.history.pushState({}, '', '/route-d');

        const sent = captureSends(t);
        atTime(t, 0);
        t.resetEngagement();
        t.trackRouteChanges();

        atTime(t, 7000);
        window.history.pushState({}, '', '/route-e');

        expect(sent[0].event_type).toBe('user_engagement');
        expect(sent[0].engagement_msec).toBe(7000);
        expect(sent[1].event_type).toBe('page_view');

        atTime(t, 7500);
        expect(t.consumeEngagementDelta()).toBe(500,
            'The new route starts its own clock at zero.');
    });

    test('binding twice does not double every route change', () => {
        const t = newTracker();

        window.history.pushState({}, '', '/route-f');

        const sent = captureSends(t);
        t.trackRouteChanges();
        t.trackRouteChanges();

        window.history.pushState({}, '', '/route-g');

        expect(sent.filter(e => e.event_type === 'page_view')).toHaveLength(1);
    });
});
