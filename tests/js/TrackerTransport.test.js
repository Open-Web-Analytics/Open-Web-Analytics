import { OWATracker } from '../../modules/base/src/tracker/Tracker.js';

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
            // string is param=value& pairs (NOT url-encoded by the tracker).
            expect(url).toContain('owa_event_type=base.page_request');
            expect(url).toContain('owa_site_id=transport-site');
            expect(url).toContain('owa_page_url=https://site.example/p');
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
});
