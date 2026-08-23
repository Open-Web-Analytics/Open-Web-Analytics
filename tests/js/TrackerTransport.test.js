import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Transport-layer tests for the tracker's GET beacon.
 *
 * The other tracker unit tests (BeaconContract*, Tracker) stop at logEvent /
 * trackEvent -- they pin WHAT the tracker would send. This one goes one layer
 * deeper and pins that logEvent actually TURNS those properties into a real
 * request: the 1x1 pixel GET to log.php with the event's properties as params, the
 * logger-endpoint URL construction, the nested-array bracket encoding, and the
 * two guard rails (inactive tracker sends nothing; an over-long URL falls back
 * to the cdPost iframe instead of the pixel). No browser -- we stub Image and
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

    test('the beacon carries the namespaced event_type + site_id + page_url', () => {
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
            expect(url).toMatch(/[?&]event_type=base\.page_request/);
            expect(url).toMatch(/[?&]site_id=transport-site/);
            expect(url).toMatch(new RegExp('[?&]page_url=' + escapeRe(encodeURIComponent('https://site.example/p'))));
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
            expect(url).toMatch(/[?&]event_type=ecommerce\.transaction/);
            // prepareRequestData flattens an array-of-objects to
            // <param>[<i>][<key>]=value -- brackets ride the wire verbatim.
            expect(url).toContain('ct_line_items[0][li_sku]=SKU-1');
            expect(url).toContain('ct_line_items[0][li_product_name]=Widget');
        } finally {
            spy.restore();
        }
    });

    test('structural characters in a value are url-encoded, not truncated', () => {
        // Regression for the beacon-truncation bug: values with query-structural
        // characters used to ride the wire raw, so a '#' started a fragment (the
        // browser dropped everything after it) and a '&'/'=' forged a new pair.
        // A clicked link whose href held a '#' or '&' thus lost every param that
        // came after page_url (click_x, site_id, session_id). Assert the value is
        // percent-encoded AND that params queued after it still appear intact.
        const spy = installImageSpy();
        try {
            const t = newTracker();
            const dirty = 'https://site.example/p?a=1&b=2#frag';
            t.trackPageView(dirty);

            const url = spy.sent[0];
            // The raw value must NOT appear (that would mean an unencoded '#'/'&').
            expect(url).not.toContain('page_url=' + dirty);
            expect(url).toMatch(new RegExp('[?&]page_url=' + escapeRe(encodeURIComponent(dirty))));
            // No literal fragment or stray delimiters survive from the value.
            expect(url).not.toContain('#frag');
            expect(url).not.toContain('a=1&b=2');
            // A param assembled after page_url still reaches the wire (proves the
            // beacon wasn't truncated at the first structural char in a value).
            expect(url).toMatch(/[?&]site_id=transport-site/);
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
     * A payload too large for the query string goes by sendBeacon WITH A BODY,
     * and cdPost is only the fallback.
     *
     * This path used to be the odd one out: the hidden-iframe POST yields no
     * delivery signal, so it committed the session optimistically -- the only
     * transport that asserted a session on disk without knowing anything had
     * arrived. Withholding instead was not an option either, because a site
     * whose payloads always exceed the limit would then never persist a session
     * at all. sendBeacon returns whether the browser queued the payload, which
     * is the same signal the query-string path already uses, so the dilemma is
     * removed rather than decided.
     */
    describe('a payload too large for the query string', () => {

        function overTheLimit() {
            const t = newTracker();
            t.setOption('getRequestCharacterLimit', 10);
            return t;
        }

        test('goes by sendBeacon with a body, not the iframe and not the pixel', () => {
            const spy = installImageSpy();
            const sent = [];
            const origBeacon = navigator.sendBeacon;
            navigator.sendBeacon = (url, body) => { sent.push({ url, body }); return true; };
            try {
                const t = overTheLimit();
                const posted = [];
                t.cdPost = (data) => { posted.push(data); };

                t.trackPageView('https://site.example/p');

                expect(spy.sent).toHaveLength(0);   // no pixel
                expect(posted).toHaveLength(0);     // no iframe
                expect(sent).toHaveLength(1);
                expect(sent[0].body).toBeInstanceOf(Blob);
                expect(sent[0].body.type).toBe('application/x-www-form-urlencoded');
            } finally {
                navigator.sendBeacon = origBeacon;
                spy.restore();
            }
        });

        test('the session is persisted only because the browser ACCEPTED it', () => {
            const spy = installImageSpy();
            const origBeacon = navigator.sendBeacon;
            try {
                // Refused: no acceptance from the beacon, so the fallback runs.
                navigator.sendBeacon = () => false;
                const t = overTheLimit();
                const posted = [];
                t.cdPost = (data) => { posted.push(data); };

                t.trackPageView('https://site.example/p');

                expect(posted).toHaveLength(1);   // cdPost is still the fallback
            } finally {
                navigator.sendBeacon = origBeacon;
                spy.restore();
            }
        });

        test('a browser without sendBeacon still gets the iframe', () => {
            const spy = installImageSpy();
            const origBeacon = navigator.sendBeacon;
            try {
                delete navigator.sendBeacon;
                const t = overTheLimit();
                const posted = [];
                t.cdPost = (data) => { posted.push(data); };

                t.trackPageView('https://site.example/p');

                expect(posted).toHaveLength(1);
                expect(posted[0]['event_type']).toBe('base.page_request');
            } finally {
                navigator.sendBeacon = origBeacon;
                spy.restore();
            }
        });

        test('a throwing sendBeacon is treated as a refusal, not an error', () => {
            const spy = installImageSpy();
            const origBeacon = navigator.sendBeacon;
            try {
                // Some browsers throw on an oversized payload rather than
                // returning false.
                navigator.sendBeacon = () => { throw new Error('too big'); };
                const t = overTheLimit();
                const posted = [];
                t.cdPost = (data) => { posted.push(data); };

                expect(() => t.trackPageView('https://site.example/p')).not.toThrow();
                expect(posted).toHaveLength(1);
            } finally {
                navigator.sendBeacon = origBeacon;
                spy.restore();
            }
        });
    });

    // Fill the queue past domstreamEventThreshold (default 10) so logDomStream()
    // actually emits. Each entry is a click event's flattened props.
    function seedDomStream(t, n) {
        for (let i = 0; i < n; i++) {
            const e = t.makeEvent();
            e.setEventType('dom.click');
            e.set('dom_element_tag', 'a');
            t.addToEventQueue(e);
        }
    }

    test('a small domstream on the GET path rides complete + encoded, not truncated', () => {
        // Domstream packs the whole queue into stream_events = JSON.stringify(queue).
        // When that still fits under getRequestCharacterLimit it takes the GET pixel
        // path -- the exact path the value-encoding fix touches. The blob is riddled
        // with '{' '"' ':' ',' and can hold '&'/'='/'#' inside captured DOM values;
        // before the fix those rode raw and truncated the beacon. Assert the blob is
        // percent-encoded AND that stream_length (assembled AFTER it) still arrives.
        const spy = installImageSpy();
        try {
            const t = newTracker();
            seedDomStream(t, 12);
            t.logDomStream();

            expect(spy.sent).toHaveLength(1);            // small blob -> GET pixel
            const url = spy.sent[0];
            expect(url).toMatch(/[?&]event_type=dom\.stream/);
            // The raw JSON must NOT appear -- it would mean unencoded structural chars.
            expect(url).not.toContain('stream_events=[{"');
            expect(url).toMatch(new RegExp('[?&]stream_events=' + escapeRe(encodeURIComponent('[{'))));
            // A param assembled after the blob still reached the wire (no truncation).
            expect(url).toMatch(/[?&]stream_length=12/);
        } finally {
            spy.restore();
        }
    });

    test('a large domstream falls to cdPost with the RAW blob (POST path untouched)', () => {
        // A queue big enough to blow past the limit routes to cdPost (POST iframe),
        // which uses prepareRequestData -- NOT prepareRequestDataForGet -- and lets
        // the browser encode on form submit. This path is byte-for-byte unchanged by
        // the GET fix: the blob reaches cdPost verbatim, structural chars intact.
        const spy = installImageSpy();
        try {
            const t = newTracker();
            // Force the POST branch deterministically regardless of blob size.
            t.setOption('getRequestCharacterLimit', 200);
            const posted = [];
            t.cdPost = (data) => { posted.push(data); };

            seedDomStream(t, 12);
            t.logDomStream();

            expect(spy.sent).toHaveLength(0);            // never took the pixel path
            expect(posted).toHaveLength(1);              // went out via cdPost (POST)
            const data = posted[0];
            expect(data['event_type']).toBe('dom.stream');
            // cdPost does NOT encode -- the '{' '"' ':' ride verbatim in the form value.
            expect(data['stream_events']).toContain('"event_type":"dom.click"');
            expect(data['stream_length']).toBe(12);
        } finally {
            spy.restore();
        }
    });
});

/**
 * The iframe POST fallback, now that nothing branches on Internet Explorer.
 *
 * This transport carries anything too big for a pixel GET whenever sendBeacon is
 * unavailable or refuses the payload. It used to build its iframe, form and
 * inputs twice: once through document.createElement('<tag name="...">'), an IE
 * quirk that returns a parsed element rather than a tag name, and once through
 * the standard DOM. The IE half was guarded by a user-agent sniff for version
 * below 9.
 *
 * That branch was unreachable and provably so. The shipped tracker bundle is
 * emitted by webpack with no transpilation step -- webpack.config.js loads only
 * css-loader, and @babel/* is a devDependency that babel-jest uses for THESE
 * tests -- so public/base/dist/owa.tracker.js contains class, let and arrow
 * functions. Internet Explorer cannot parse that file at all, which means it
 * never runs the sniff that asks whether it is Internet Explorer. Code that
 * decides what to do in a browser that cannot load the file containing the
 * decision is not compatibility; it is a comment that costs bytes on every page
 * view.
 *
 * With the branch gone the standard DOM path is unconditional, so it is worth
 * pinning what it actually builds -- these assertions are what would have
 * failed if the wrong half had been deleted.
 */
describe('iframe POST fallback builds its form through the standard DOM', () => {

    /*
     * getIframeDocument() calls doc.open(); doc.close() on the iframe's
     * document. A real browser answers that with a fresh
     * <html><head></head><body></body>; jsdom answers with a document whose
     * documentElement is null, so nothing downstream has a body to append to.
     *
     * Stubbed rather than worked around, and stubbed at exactly that seam: the
     * code under test here is the form and input construction, which is what
     * the IE branch removal touched. Handing it a real, populated document is
     * closer to a browser than the one jsdom would have produced.
     */
    function trackerWithWritableIframeDocument() {
        const t = newTracker();
        const doc = document.implementation.createHTMLDocument('post');

        // The form is submitted and then removed on the next line, so it is
        // gone by the time the test could query for it. Capture it as it goes
        // in -- which is also the moment the browser would act on it.
        const appended = [];
        const realAppend = doc.body.appendChild.bind(doc.body);
        doc.body.appendChild = (node) => { appended.push(node); return realAppend(node); };

        t.getIframeDocument = () => doc;
        return { tracker: t, doc, appended };
    }

    test('the form carries every param as a named hidden input', () => {
        const { tracker, doc, appended } = trackerWithWritableIframeDocument();

        tracker.postFromIframe(document.createElement('iframe'), {
            event_type: 'dom.stream',
            site_id: 'transport-site',
            stream_length: 12,
        });


        const form = appended[0];

        expect(form).toBeDefined();
        expect(form.tagName).toBe('FORM');
        expect(form.getAttribute('method')).toBe('POST');
        expect(form.getAttribute('action')).toBe(tracker.getLoggerEndpoint());

        // The NAME attribute is the whole reason the IE branch existed -- the
        // quirk it worked around was that name could not be set with
        // setAttribute on an already-created element. If the surviving branch
        // had been the wrong one, every input here would be nameless and the
        // POST would arrive empty.
        const named = {};
        form.querySelectorAll('input').forEach((i) => {
            named[i.getAttribute('name')] = i.getAttribute('value');
        });

        expect(named['event_type']).toBe('dom.stream');
        expect(named['site_id']).toBe('transport-site');
        expect(named['stream_length']).toBe('12');
        expect(Object.keys(named)).not.toContain('null');
    });

    test('the form itself is named, which is how the iframe finds it to submit', () => {
        const { tracker, doc, appended } = trackerWithWritableIframeDocument();

        tracker.postFromIframe(document.createElement('iframe'), { event_type: 'dom.stream' });

        const form = appended[0];

        // Looked up as doc.forms[form_name] on the line that submits it, so an
        // unnamed form is a silently dropped beacon.
        expect(form.getAttribute('name')).toBeTruthy();
        expect(form.getAttribute('id')).toBe(form.getAttribute('name'));
    });

    test('the hidden iframe is 1x1 and named for the form to target', () => {
        const t = newTracker();

        t.generateHiddenIframe(document.body, { event_type: 'dom.stream' });

        const ifr = document.querySelector('iframe.owa-tracker-post-iframe');

        expect(ifr).not.toBeNull();
        expect(ifr.getAttribute('name')).toBe('owa-tracker-post-iframe');
        expect(ifr.getAttribute('width')).toBe('1');
        expect(ifr.getAttribute('height')).toBe('1');
        // 'scr' was the typo in the IE branch. The surviving branch sets src.
        expect(ifr.getAttribute('src')).toBe('about:blank');
        expect(ifr.getAttribute('scr')).toBeNull();
    });
});
