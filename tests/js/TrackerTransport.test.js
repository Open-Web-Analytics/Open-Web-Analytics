import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Transport-layer tests for the tracker's GET beacon.
 *
 * The other tracker unit tests (BeaconContract*, Tracker) stop at logEvent /
 * trackEvent -- they pin WHAT the tracker would send. This one goes one layer
 * deeper and pins that logEvent actually TURNS those properties into a real
 * request: the 1x1 pixel GET to log.php with the event's properties as params, the
 * logger-endpoint URL construction, the nested-array bracket encoding, and the
 * two guard rails (inactive tracker sends nothing; an over-long URL goes as a
 * form body instead of the pixel). No browser -- we stub Image and
 * capture the src the tracker assigns.
 */

// Capture every URL assigned to a 1x1 pixel. The tracker does `new Image(1,1)`
// then `image.src = url`; we replace Image with a class that records the set.
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

// A tracker wired for a headless run with a known endpoint. cookie_domain_set
// avoids the document.domain branch; owa_baseUrl is what the constructor reads
// to derive the logger endpoint (baseUrl + 'log.php').
const BASE_URL = 'https://owa.example.test/';

function newTracker(opts) {
    window.owa_baseUrl = BASE_URL;
    const t = new OWATracker(Object.assign({ cookie_domain_set: true }, opts));
    t.setSiteId('transport-site');
    return t;
}

afterEach(() => {
    delete window.owa_baseUrl;
});

// Anchors a param assertion to a '?' or '&' so it cannot also match the
// namespaced spelling: 'site_id=x' is a substring of 'owa_site_id=x', so a
// bare toContain() passed with OR without the prefix and tested nothing.
const escapeRe = (v) => String(v).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

describe('tracker GET transport (1x1 pixel beacon)', () => {

    test('trackPageView fires exactly one beacon to log.php', () => {
        const spy = installImageSpy();
        try {
            newTracker().trackPageView('https://site.example/p');
            expect(spy.sent).toHaveLength(1);
            expect(spy.sent[0]).toContain(BASE_URL + 'log.php?');
        } finally {
            spy.restore();
        }
    });

    test('the beacon carries e_t + site + p_l', () => {
        const spy = installImageSpy();
        try {
            newTracker().trackPageView('https://site.example/p');
            const url = spy.sent[0];
            // prepareRequestData emits each key under the APP namespace, which
            // is empty -- log.php's query string is OWA's own. The GET
            // string is param=value& pairs whose VALUES are url-encoded (keys are
            // not -- see the encoding regression test below). event_type/site_id
            // contain no structural chars so they ride verbatim; the page url's
            // ':' and '/' become %3A / %2F.
            expect(url).toMatch(/[?&]e_t=page_view/);
            expect(url).toMatch(/[?&]site=transport-site/);
            expect(url).toMatch(new RegExp('[?&]p_l=' + escapeRe(encodeURIComponent('https://site.example/p'))));
        } finally {
            spy.restore();
        }
    });

    test('the logger endpoint honours an explicit setLoggerEndpoint override', () => {
        const spy = installImageSpy();
        try {
            const t = newTracker();
            t.setLoggerEndpoint('https://collector.example.net/');
            t.trackPageView();
            expect(spy.sent[0]).toContain('https://collector.example.net/log.php?');
        } finally {
            spy.restore();
        }
    });

    test('a transaction beacon carries the nested line-item bracket params', () => {
        const spy = installImageSpy();
        try {
            const t = newTracker();
            t.addTransaction('o1', 'web', 42.5, 2.5, 5, 'stripe');
            t.addTransactionLineItem('o1', 'SKU-1', 'Widget', 'widgets', 20, 2);
            t.trackTransaction();

            expect(spy.sent).toHaveLength(1);
            const url = spy.sent[0];
            expect(url).toMatch(/[?&]e_t=purchase/);
            // prepareRequestData flattens an array-of-objects to
            // <param>[<i>][<key>]=value -- brackets ride the wire verbatim.
            expect(url).toContain('o_items[0][item_id]=SKU-1');
            expect(url).toContain('o_items[0][item_name]=Widget');
        } finally {
            spy.restore();
        }
    });

    test('structural characters in a value are url-encoded, not truncated', () => {
        // Regression for the beacon-truncation bug: values with query-structural
        // characters used to ride the wire raw, so a '#' started a fragment (the
        // browser dropped everything after it) and a '&'/'=' forged a new pair.
        // A clicked link whose href held a '#' or '&' thus lost every param that
        // came after the page URL (c_x, site, s_id). Assert the value is
        // percent-encoded AND that params queued after it still appear intact.
        const spy = installImageSpy();
        try {
            const t = newTracker();
            const dirty = 'https://site.example/p?a=1&b=2#frag';
            t.trackPageView(dirty);

            const url = spy.sent[0];
            // The raw value must NOT appear (that would mean an unencoded '#'/'&').
            expect(url).not.toContain('p_l=' + dirty);
            expect(url).toMatch(new RegExp('[?&]p_l=' + escapeRe(encodeURIComponent(dirty))));
            // No literal fragment or stray delimiters survive from the value.
            expect(url).not.toContain('#frag');
            expect(url).not.toContain('a=1&b=2');
            // A param assembled after the page URL still reaches the wire (proves the
            // beacon wasn't truncated at the first structural char in a value).
            expect(url).toMatch(/[?&]site=transport-site/);
        } finally {
            spy.restore();
        }
    });

    test('an inactive tracker sends no beacon', () => {
        const spy = installImageSpy();
        try {
            const t = newTracker();
            t.active = false;
            t.trackPageView('https://site.example/p');
            expect(spy.sent).toHaveLength(0);
        } finally {
            spy.restore();
        }
    });

    /**
     * A payload too large for the query string goes by sendBeacon WITH A BODY.
     *
     * There is no iframe fallback any more: every browser that runs this
     * tracker has sendBeacon, and the largest payload OWA makes is a domstream
     * chunk well inside its limit. A payload the browser refuses is dropped, and
     * the session is not persisted on it -- acceptance is what persists one.
     */
    describe('a payload too large for the query string', () => {

        function overTheLimit() {
            const t = newTracker();
            t.setOption('getRequestCharacterLimit', 10);
            return t;
        }

        test('goes by sendBeacon with a body, not the pixel', () => {
            const spy = installImageSpy();
            const sent = [];
            const origBeacon = navigator.sendBeacon;
            navigator.sendBeacon = (url, body) => { sent.push({ url, body }); return true; };
            try {
                const t = overTheLimit();

                t.trackPageView('https://site.example/p');

                expect(spy.sent).toHaveLength(0);   // no pixel
                expect(sent).toHaveLength(1);
                expect(sent[0].body).toBeInstanceOf(Blob);
                expect(sent[0].body.type).toBe('application/x-www-form-urlencoded');
            } finally {
                navigator.sendBeacon = origBeacon;
                spy.restore();
            }
        });

        test.each([
            ['refused', () => false],
            ['throwing', () => { throw new Error('too big'); }],
            ['missing', undefined],
        ])('a %s sendBeacon sends nothing and persists no session', (_, beacon) => {
            const spy = installImageSpy();
            const origBeacon = navigator.sendBeacon;
            try {
                if (beacon) {
                    navigator.sendBeacon = beacon;
                } else {
                    delete navigator.sendBeacon;
                }
                const t = overTheLimit();
                let accepted = 0;
                t.sendAccepted = () => { accepted++; };

                expect(() => t.trackPageView('https://site.example/p')).not.toThrow();
                expect(accepted).toBe(0);
                expect(spy.sent).toHaveLength(0);
            } finally {
                navigator.sendBeacon = origBeacon;
                spy.restore();
            }
        });
    });

    /**
     * WITHOUT COOKIES, where the browser can. A collector on the site's own
     * domain gets every cookie set for it, OWA's state cookies included, on every
     * beacon; ingest reads none of them. fetch with keepalive survives unload as
     * sendBeacon does, and credentials: 'omit' leaves them off.
     */
    describe('the cookie-less transport', () => {

        let calls, origFetch, origRequest, origBeacon;

        beforeEach(() => {
            calls = [];
            origFetch = global.fetch;
            origRequest = global.Request;
            origBeacon = navigator.sendBeacon;
            global.Request = function () {};
            global.Request.prototype.keepalive = false;
        });

        afterEach(() => {
            global.fetch = origFetch;
            global.Request = origRequest;
            navigator.sendBeacon = origBeacon;
        });

        test('a beacon goes by fetch, keepalive, credentials omitted, and is accepted at once', () => {
            const beacons = [];
            navigator.sendBeacon = (url) => { beacons.push(url); return true; };
            global.fetch = (url, init) => { calls.push({ url, init }); return Promise.resolve({}); };

            const t = newTracker();
            let accepted = 0;
            t.sendAccepted = () => { accepted++; };

            t.trackPageView('https://site.example/p');

            expect(calls).toHaveLength(1);
            expect(calls[0].url).toMatch(/log\.php\?.*[?&]e_t=page_view/);
            expect(calls[0].init).toMatchObject({ method: 'POST', keepalive: true, credentials: 'omit', mode: 'no-cors' });
            expect(beacons).toHaveLength(0);
            expect(accepted).toBe(1);
        });

        test('a large payload goes by fetch with its form body', () => {
            global.fetch = (url, init) => { calls.push({ url, init }); return Promise.resolve({}); };

            const t = newTracker();
            t.setOption('getRequestCharacterLimit', 10);
            t.trackPageView('https://site.example/p');

            expect(calls).toHaveLength(1);
            expect(calls[0].init.body).toBeInstanceOf(Blob);
            expect(calls[0].init.body.type).toBe('application/x-www-form-urlencoded');
            expect(calls[0].init.credentials).toBe('omit');
        });

        // A keepalive fetch in flight at navigation rejects in the departing
        // page while the browser still delivers it; a re-send was a duplicate.
        test('a rejected fetch is not re-sent', async () => {
            const beacons = [];
            navigator.sendBeacon = (url) => { beacons.push(url); return true; };
            global.fetch = (url, init) => { calls.push({ url, init }); return Promise.reject(new TypeError('Failed to fetch')); };

            newTracker().trackPageView('https://site.example/p');
            await new Promise((r) => setTimeout(r, 0));

            expect(calls).toHaveLength(1);
            expect(beacons).toHaveLength(0);
        });

        test('a fetch that throws falls back to sendBeacon', () => {
            const beacons = [];
            navigator.sendBeacon = (url) => { beacons.push(url); return true; };
            global.fetch = () => { throw new TypeError('blocked'); };

            newTracker().trackPageView('https://site.example/p');

            expect(beacons).toHaveLength(1);
            expect(beacons[0]).toMatch(/[?&]e_t=page_view/);
        });

        test('without keepalive support it falls back to sendBeacon', () => {
            delete global.Request.prototype.keepalive;
            const beacons = [];
            navigator.sendBeacon = (url) => { beacons.push(url); return true; };
            global.fetch = (url, init) => { calls.push({ url, init }); return Promise.resolve({}); };

            newTracker().trackPageView('https://site.example/p');

            expect(calls).toHaveLength(0);
            expect(beacons).toHaveLength(1);
        });

        test('the URL rides once, as p_l', () => {
            global.fetch = (url, init) => { calls.push({ url, init }); return Promise.resolve({}); };

            newTracker().trackPageView('https://site.example/p');

            expect(calls[0].url).toMatch(/[?&]p_l=https%3A%2F%2Fsite\.example%2Fp/);
            expect(calls[0].url).not.toMatch(/[?&]page_url=/);
            expect(calls[0].url).not.toMatch(/[?&]page_location=/);
        });
    });

    // A recording chunk: the largest payload the tracker sends, a JSON blob in
    // one property. Built directly -- the transport is under test, not the
    // recorder that makes these (DomstreamRecorder.test.js).
    function sendChunk(t, n) {
        const samples = [];
        for (let i = 0; i < n; i++) {
            samples.push([10, 'c', i, i, 'a', 'x&y=#' + i, '']);
        }
        const e = t.makeEvent();
        e.setEventType('domstream');
        e.set('samples', JSON.stringify(samples));
        e.set('seq', n);
        t.trackEvent(e);
    }

    test('a small chunk on the GET path rides complete + encoded, not truncated', () => {
        // When the blob still fits under getRequestCharacterLimit it takes the GET
        // pixel path -- the exact path the value-encoding fix touches. The blob is
        // riddled with '[' '"' ':' ',' and holds '&'/'='/'#' inside captured DOM
        // values; before the fix those rode raw and truncated the beacon. Assert
        // the blob is percent-encoded AND that seq (assembled AFTER it) arrives.
        const spy = installImageSpy();
        try {
            const t = newTracker();
            sendChunk(t, 12);

            expect(spy.sent).toHaveLength(1);            // small blob -> GET pixel
            const url = spy.sent[0];
            expect(url).toMatch(/[?&]e_t=domstream/);
            // The raw JSON must NOT appear -- it would mean unencoded structural chars.
            expect(url).not.toContain('samples=[[');
            expect(url).toMatch(new RegExp('[?&]samples=' + escapeRe(encodeURIComponent('[['))));
            // A param assembled after the blob still reached the wire (no truncation).
            expect(url).toMatch(/[?&]seq=12/);
        } finally {
            spy.restore();
        }
    });

    test('a large chunk goes as a form body with the RAW blob', () => {
        // A queue big enough to blow past the limit routes to sendLargeRequest,
        // which uses prepareRequestData -- NOT prepareRequestDataForGet -- and
        // encodes once when it builds the body. The blob reaches it verbatim,
        // structural characters intact.
        const spy = installImageSpy();
        try {
            const t = newTracker();
            // Force the POST branch deterministically regardless of blob size.
            t.setOption('getRequestCharacterLimit', 200);
            const posted = [];
            t.sendLargeRequest = (data) => { posted.push(data); };

            sendChunk(t, 12);

            expect(spy.sent).toHaveLength(0);            // never took the pixel path
            expect(posted).toHaveLength(1);              // went out as a body
            const data = posted[0];
            expect(data['e_t']).toBe('domstream');
            // Not GET-encoded -- the '[' '"' ',' ride verbatim into the body builder.
            expect(data['samples']).toContain('[10,"c",0,0,"a","x&y=#0",""]');
            expect(data['seq']).toBe(12);
        } finally {
            spy.restore();
        }
    });
});
