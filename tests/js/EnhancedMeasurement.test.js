import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * The collection surfaces v1 never had: scroll depth, downloads, forms, site
 * search, and a selector that actually identifies what was clicked.
 *
 * The scroll ones matter most. 1.x's handler queued an event on EVERY scroll
 * tick, and `last_scroll` was assigned and never read, so nothing throttled it
 * and a timestamp throttle would not have helped -- it would still be a stream.
 * Depth thresholds make it one event per page per mark.
 */

function newTracker(options) {
    const t = new OWATracker(Object.assign(
        { cookie_domain_set: true, cookie_domain: '.em.example' }, options || {}));
    t.setSiteId('em-site');
    return t;
}

function captureSends(t) {
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); };
    return sent;
}

/** jsdom lays nothing out, so the tracker is told what it would have measured. */
function pageOf(t, { height, viewport, scrolled }) {
    Object.defineProperty(document.documentElement, 'scrollHeight',
        { configurable: true, get: () => height });
    t.getViewportDimensions = () => ({ width: 1000, height: viewport });
    t.getScrollingPosition = () => ({ x: 0, y: scrolled });
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
    document.body.innerHTML = '';
});

describe('scroll depth', () => {

    test('one event when the page passes the threshold, and not again', () => {
        const t = newTracker();
        const sent = captureSends(t);

        pageOf(t, { height: 2000, viewport: 800, scrolled: 0 });
        t.checkScrollDepth();
        expect(sent).toHaveLength(0);

        // bottom of the viewport at 1900 of 2000 = 95%
        pageOf(t, { height: 2000, viewport: 800, scrolled: 1100 });
        t.checkScrollDepth();

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('scroll');
        expect(sent[0].scroll_depth).toBe(90);

        // Scrolling further, and scrolling back and down again, is still one.
        pageOf(t, { height: 2000, viewport: 800, scrolled: 1200 });
        t.checkScrollDepth();
        t.checkScrollDepth();

        expect(sent).toHaveLength(1);
    });

    test('quartiles raise one event each, in order, and never repeat', () => {
        const t = newTracker({ scrollThresholds: [25, 50, 75, 100] });
        const sent = captureSends(t);

        for (const scrolled of [0, 200, 700, 1200, 1200]) {
            pageOf(t, { height: 2000, viewport: 800, scrolled });
            t.checkScrollDepth();
        }

        expect(sent.map(e => e.scroll_depth)).toEqual([25, 50, 75, 100]);
    });

    test('a page that fits on one screen is fully read from the start', () => {
        const t = newTracker();
        const sent = captureSends(t);

        pageOf(t, { height: 600, viewport: 800, scrolled: 0 });
        t.checkScrollDepth();

        expect(sent).toHaveLength(1);
    });

    test('a document with no measurable height raises nothing', () => {
        const t = newTracker();
        const sent = captureSends(t);

        pageOf(t, { height: 0, viewport: 800, scrolled: 0 });
        t.checkScrollDepth();

        expect(sent).toHaveLength(0);
    });
});

describe('downloads and outbound links', () => {

    test('a listed extension is a download', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.classifyClickTarget('https://cdn.example.org/docs/guide.pdf?v=2');

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('file_download');
        expect(sent[0].file_extension).toBe('pdf');
        expect(sent[0].file_name).toBe('guide.pdf');
    });

    test('an ordinary page is not a download, whatever dots the path contains', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.classifyClickTarget('https://example.org/docs/v1.2/index.html');
        t.classifyClickTarget('https://example.org/about');

        expect(sent).toHaveLength(0);
    });

    test('outbound is decided on the host, so apex and www are both internal', () => {
        const t = newTracker();

        expect(t.isOutboundUrl('https://elsewhere.example/page')).toBe(true);
        expect(t.isOutboundUrl('/relative/path')).toBe(false);
        expect(t.isOutboundUrl('')).toBe(false);
    });
});

describe('element path', () => {

    test('an id ends the path, because an id is unique by definition', () => {
        document.body.innerHTML = '<div><section id="hero"><a><span id="x">go</span></a></section></div>';

        expect(newTracker().getElementPath(document.getElementById('x'))).toBe('#x');
    });

    test('without an id it walks up, distinguishing siblings by position', () => {
        document.body.innerHTML =
            '<nav><ul><li><a>one</a></li><li><a>two</a></li></ul></nav>';

        const second = document.querySelectorAll('nav li')[1].querySelector('a');

        expect(newTracker().getElementPath(second))
            .toBe('nav > ul > li:nth-of-type(2) > a');
    });

    test('a path is produced for an element nobody gave an id', () => {
        document.body.innerHTML = '<main><p><button>buy</button></p></main>';

        const path = newTracker().getElementPath(document.querySelector('button'));

        expect(path).toBe('main > p > button');
        expect(path).not.toBe('');
    });
});

describe('forms', () => {

    test('form_start fires once per form, form_submit on send', () => {
        document.body.innerHTML =
            '<form id="signup" name="Newsletter"><input id="e"><input id="f"></form>';

        const t = newTracker();
        const sent = captureSends(t);

        t.trackForms();

        document.getElementById('e').dispatchEvent(new Event('focusin', { bubbles: true }));
        document.getElementById('f').dispatchEvent(new Event('focusin', { bubbles: true }));

        expect(sent.filter(e => e.event_type === 'form_start')).toHaveLength(1,
            'A second field is the same form, and start/submit is only a funnel if start is once.');

        document.getElementById('signup')
            .dispatchEvent(new Event('submit', { bubbles: true }));

        const submits = sent.filter(e => e.event_type === 'form_submit');
        expect(submits).toHaveLength(1);
        expect(submits[0].form_id).toBe('signup');
        expect(submits[0].form_name).toBe('Newsletter');
    });

    test('two forms on one page each get their own start', () => {
        document.body.innerHTML =
            '<form id="a"><input id="ai"></form><form id="b"><input id="bi"></form>';

        const t = newTracker();
        const sent = captureSends(t);
        t.trackForms();

        document.getElementById('ai').dispatchEvent(new Event('focusin', { bubbles: true }));
        document.getElementById('bi').dispatchEvent(new Event('focusin', { bubbles: true }));

        expect(sent.filter(e => e.event_type === 'form_start').map(e => e.form_id))
            .toEqual(['a', 'b']);
    });

    test('focus outside a form is not a form start', () => {
        document.body.innerHTML = '<input id="loose">';

        const t = newTracker();
        const sent = captureSends(t);
        t.trackForms();

        document.getElementById('loose').dispatchEvent(new Event('focusin', { bubbles: true }));

        expect(sent).toHaveLength(0);
    });
});

describe('site search', () => {

    test('a configured parameter raises view_search_results', () => {
        const t = newTracker({ siteSearchParams: ['q', 'query'] });
        const sent = captureSends(t);

        t.getUrlParam = (name) => (name === 'q' ? 'partitioning' : false);
        t.trackSiteSearch();

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('view_search_results');
        expect(sent[0].search_term).toBe('partitioning');
    });

    test('nothing is raised when no parameter is configured', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.getUrlParam = () => 'anything';
        t.trackSiteSearch();

        expect(sent).toHaveLength(0,
            'There is no convention for the parameter name, so a default would guess wrong.');
    });
});

describe('custom events', () => {

    test('trackCustomEvent is the whole custom-event API: a name plus params', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.trackCustomEvent('newsletter_signup', { plan: 'pro', seats: 3 });

        expect(sent[0].event_type).toBe('newsletter_signup');
        expect(sent[0].plan).toBe('pro');
        expect(sent[0].seats).toBe(3);
    });
});
