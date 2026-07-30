import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Transport-layer tests for the tracker's GET beacon.
 *
 * The other tracker unit tests (BeaconContract*, Tracker) stop at logEvent /
 * trackEvent -- they pin WHAT the tracker would send. This one goes one layer
 * deeper and pins that logEvent actually TURNS those properties into a real
 * request: the 1x1 pixel GET to log.php with the namespaced (owa_*) params, the
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
            // prepareRequestData prefixes every key with the ns (owa_); the GET
            // string is param=value& pairs whose VALUES are url-encoded (keys are
            // not -- see the encoding regression test below). event_type/site_id
            // contain no structural chars so they ride verbatim; the page url's
            // ':' and '/' become %3A / %2F.
            expect(url).toContain('owa_event_type=base.page_request');
            expect(url).toContain('owa_site_id=transport-site');
            expect(url).toContain('owa_page_url=' + encodeURIComponent('https://site.example/p'));
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
            expect(url).toContain('owa_event_type=ecommerce.transaction');
            // prepareRequestData flattens an array-of-objects to
            // owa_<param>[<i>][<key>]=value -- brackets ride the wire verbatim.
            expect(url).toContain('owa_ct_line_items[0][li_sku]=SKU-1');
            expect(url).toContain('owa_ct_line_items[0][li_product_name]=Widget');
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
            expect(url).not.toContain('owa_page_url=' + dirty);
            expect(url).toContain('owa_page_url=' + encodeURIComponent(dirty));
            // No literal fragment or stray delimiters survive from the value.
            expect(url).not.toContain('#frag');
            expect(url).not.toContain('a=1&b=2');
            // A param assembled after page_url still reaches the wire (proves the
            // beacon wasn't truncated at the first structural char in a value).
            expect(url).toContain('owa_site_id=transport-site');
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

    test('a URL over the character limit falls back to cdPost, not the pixel', () => {
        const spy = installImageSpy();
        try {
            const t = newTracker();
            // Force the GET path over the limit so logEvent picks the POST iframe.
            t.setOption('getRequestCharacterLimit', 10);
            const posted = [];
            t.cdPost = (data) => { posted.push(data); };

            t.trackPageView('https://site.example/p');

            expect(spy.sent).toHaveLength(0);           // no pixel
            expect(posted).toHaveLength(1);             // cdPost took over
            expect(posted[0]['owa_event_type']).toBe('base.page_request');
        } finally {
            spy.restore();
        }
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
            expect(url).toContain('owa_event_type=dom.stream');
            // The raw JSON must NOT appear -- it would mean unencoded structural chars.
            expect(url).not.toContain('owa_stream_events=[{"');
            expect(url).toContain('owa_stream_events=' + encodeURIComponent('[{'));
            // A param assembled after the blob still reached the wire (no truncation).
            expect(url).toContain('owa_stream_length=12');
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
            expect(data['owa_event_type']).toBe('dom.stream');
            // cdPost does NOT encode -- the '{' '"' ':' ride verbatim in the form value.
            expect(data['owa_stream_events']).toContain('"event_type":"dom.click"');
            expect(data['owa_stream_length']).toBe(12);
        } finally {
            spy.restore();
        }
    });
});
