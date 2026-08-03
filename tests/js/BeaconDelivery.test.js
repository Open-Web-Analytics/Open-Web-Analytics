import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Transport selection and the delivery signal it produces.
 *
 * The tracker historically sent every event as `new Image(1,1); image.src = url`
 * -- fire and forget, with no way to learn whether the request survived. That is
 * the dominant way a first page view is lost: the visitor clicks through (an
 * outbound link, or even an in-page anchor) while the pixel is still in flight
 * and the browser cancels it. The `block` parameter threaded from
 * trackCustomEvent -> trackEvent -> logEvent was meant to guard against exactly
 * that, but its body had been commented out for years, so it did nothing.
 *
 * navigator.sendBeacon is the modern replacement: the browser takes ownership of
 * delivery and completes it across unload. Called with no body it issues a POST
 * with the query string intact, so log.php keeps reading $_GET unchanged.
 *
 * Its boolean return is also the first delivery signal this code has ever had,
 * and it drives whether session identity may be committed to the cookie:
 *
 *   accepted            -> commit  (the server will hear about this session)
 *   refused / errored   -> abandon (leave the cookie clean; next page starts new)
 *   neither (page torn  -> nothing is committed, which is the correct outcome
 *   down mid-flight)
 */

function installImageSpy() {
    const sent = [];
    const Orig = global.Image;
    global.Image = class {
        constructor() {}
        set src(v) { sent.push(v); }
        get src() { return this._src; }
    };
    return { sent, restore: () => { global.Image = Orig; } };
}

/** Replace navigator.sendBeacon with a stub; returns the recorded calls. */
function installBeacon(impl) {
    const sent = [];
    const had = Object.prototype.hasOwnProperty.call(navigator, 'sendBeacon');
    const orig = navigator.sendBeacon;
    Object.defineProperty(navigator, 'sendBeacon', {
        configurable: true,
        writable: true,
        value: (url) => { sent.push(url); return impl(url); },
    });
    return {
        sent,
        restore: () => {
            if (had) { navigator.sendBeacon = orig; }
            else { delete navigator.sendBeacon; }
        },
    };
}

function removeBeacon() {
    const had = Object.prototype.hasOwnProperty.call(navigator, 'sendBeacon');
    const orig = navigator.sendBeacon;
    delete navigator.sendBeacon;
    return { restore: () => { if (had) { navigator.sendBeacon = orig; } } };
}

const BASE_URL = 'https://owa.example.test/';
const URL_UNDER_TEST = BASE_URL + 'log.php?owa_event_type=base.page_request';

function newTracker() {
    window.owa_baseUrl = BASE_URL;
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('beacon-site');
    return t;
}

afterEach(() => {
    delete window.owa_baseUrl;
    jest.restoreAllMocks();
});

describe('transport selection', () => {

    test('prefers navigator.sendBeacon when it accepts the payload', () => {
        const beacon = installBeacon(() => true);
        const pixel = installImageSpy();

        const queued = newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        expect(queued).toBe(true);
        expect(beacon.sent).toEqual([URL_UNDER_TEST]);
        expect(pixel.sent).toEqual([]);          // no duplicate hit

        pixel.restore();
        beacon.restore();
    });

    test('falls back to the 1x1 pixel when sendBeacon is unavailable', () => {
        const gone = removeBeacon();
        const pixel = installImageSpy();

        const queued = newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        expect(queued).toBe(false);
        expect(pixel.sent).toEqual([URL_UNDER_TEST]);

        pixel.restore();
        gone.restore();
    });

    test('falls back to the pixel when sendBeacon refuses the payload', () => {
        // Returns false for an oversized body or when disabled by policy.
        const beacon = installBeacon(() => false);
        const pixel = installImageSpy();

        const queued = newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        expect(queued).toBe(false);
        expect(beacon.sent).toEqual([URL_UNDER_TEST]);
        expect(pixel.sent).toEqual([URL_UNDER_TEST]);   // the hit is not dropped

        pixel.restore();
        beacon.restore();
    });

    test('falls back to the pixel when sendBeacon throws', () => {
        // Some browsers throw rather than returning false.
        const beacon = installBeacon(() => { throw new Error('refused'); });
        const pixel = installImageSpy();

        const queued = newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        expect(queued).toBe(false);
        expect(pixel.sent).toEqual([URL_UNDER_TEST]);

        pixel.restore();
        beacon.restore();
    });
});

describe('the delivery signal drives session persistence', () => {

    test('a queued beacon commits deferred state immediately', () => {
        const beacon = installBeacon(() => true);
        const commit = jest.spyOn(OWA, 'commitDeferredStatePersistence');

        newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        expect(commit).toHaveBeenCalled();

        beacon.restore();
    });

    test('the pixel path commits only once the image loads', () => {
        const gone = removeBeacon();
        const commit = jest.spyOn(OWA, 'commitDeferredStatePersistence');

        // Capture the element so its handlers can be fired deliberately.
        const created = [];
        const Orig = global.Image;
        global.Image = class {
            constructor() { created.push(this); }
            set src(v) { this._src = v; }
            get src() { return this._src; }
        };

        newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        // Nothing committed yet: assignment only *initiates* the request.
        expect(commit).not.toHaveBeenCalled();

        created[0].onload();
        expect(commit).toHaveBeenCalled();

        global.Image = Orig;
        gone.restore();
    });

    test('the pixel path abandons deferred state on error', () => {
        const gone = removeBeacon();
        const abandon = jest.spyOn(OWA, 'abandonDeferredStatePersistence');

        const created = [];
        const Orig = global.Image;
        global.Image = class {
            constructor() { created.push(this); }
            set src(v) { this._src = v; }
            get src() { return this._src; }
        };

        newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');
        created[0].onerror();

        expect(abandon).toHaveBeenCalled();

        global.Image = Orig;
        gone.restore();
    });

    test('a torn-down page commits nothing (neither handler fires)', () => {
        const gone = removeBeacon();
        const commit = jest.spyOn(OWA, 'commitDeferredStatePersistence');
        const abandon = jest.spyOn(OWA, 'abandonDeferredStatePersistence');

        const pixel = installImageSpy();
        newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        // The request left, but nothing acknowledged it. Session identity stays
        // out of the cookie, so the next page starts a session the server will
        // actually create -- one lost pageview instead of a stranded session.
        expect(commit).not.toHaveBeenCalled();
        expect(abandon).not.toHaveBeenCalled();

        pixel.restore();
        gone.restore();
    });

    test('the handler is onload, not onLoad', () => {
        // The original code assigned `image.onLoad`, which is not a DOM property
        // and never fires. Harmless while nothing hung off the success path;
        // load-bearing now that it commits session identity.
        const gone = removeBeacon();
        const created = [];
        const Orig = global.Image;
        global.Image = class {
            constructor() { created.push(this); }
            set src(v) { this._src = v; }
        };

        newTracker().sendRequest(URL_UNDER_TEST, 'base.page_request');

        expect(typeof created[0].onload).toBe('function');
        expect(created[0].onLoad).toBeUndefined();

        global.Image = Orig;
        gone.restore();
    });
});
