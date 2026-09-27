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
        const t = newTracker({ scrollThresholds: [90] });
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
        const t = newTracker({ scrollThresholds: [90] });
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
        expect(sent[0].file_name).toBe('/docs/guide.pdf');
    });

    /*
     * THE NAME IS THE PATH, and it reaches a COLUMN rather than the params bag, so
     * what the tracker cuts is what a downloads report groups by -- see Update054.
     */
    test('the file name is the path, without host, query or fragment', () => {
        const t = newTracker();

        expect(t.getDownloadFileName('https://example.org/a/b/menu.pdf?v=2#page3'))
            .toBe('/a/b/menu.pdf');

        // Two files of the same NAME in different folders stay APART. As the
        // basename they collapsed into one row of a downloads report.
        expect(t.getDownloadFileName('https://example.org/2024/report.pdf'))
            .not.toBe(t.getDownloadFileName('https://example.org/2025/report.pdf'));

        // The HOST is dropped: the same document served from the site and from a
        // CDN is one row, and target_host answers where it came from.
        expect(t.getDownloadFileName('https://cdn.example.org/docs/guide.pdf'))
            .toBe(t.getDownloadFileName('https://example.org/docs/guide.pdf'));

        // A relative href resolves against the page rather than being read as a
        // path that happens to start with a letter.
        expect(t.getDownloadFileName('/files/x.zip')).toBe('/files/x.zip');
        expect(t.getDownloadFileName('files/x.zip')).toMatch(/\/files\/x\.zip$/);

        // Left encoded: decodeURIComponent throws on a malformed sequence, and
        // this runs on whatever href the page carries.
        expect(t.getDownloadFileName('https://example.org/my%20file.pdf'))
            .toBe('/my%20file.pdf');
    });

    test('an ordinary page is not a download, whatever dots the path contains', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.classifyClickTarget('https://example.org/docs/v1.2/index.html');
        t.classifyClickTarget('https://example.org/about');

        expect(sent).toHaveLength(0);
    });

    /*
     * OUTBOUND IS DECIDED HERE, and the rules are these. It was briefly a server
     * derivation off target_url and page_location -- which only serves a sender
     * shaped like a browser, and cannot see a DOM.
     */
    test('a different host is outbound and the same host is not', () => {
        const t = newTracker();

        expect(t.isOutboundUrl('https://elsewhere.example/page')).toBe(true);
        expect(t.isOutboundUrl(window.location.href)).toBe(false);
    });

    test('a relative URL resolves against the page, so it is internal', () => {
        const t = newTracker();

        expect(t.isOutboundUrl('/relative/path')).toBe(false);
        expect(t.isOutboundUrl('relative/path')).toBe(false);
    });

    test('nothing to compare is not a claim that the click left', () => {
        const t = newTracker();

        expect(t.isOutboundUrl('')).toBe(false);
        expect(t.isOutboundUrl(undefined)).toBe(false);
        // Unparseable against any base: false rather than an exception.
        expect(t.isOutboundUrl('http://')).toBe(false);
    });

    /*
     * COMPARED ON HOST, against THIS PAGE's host rather than a configured domain.
     * A site reached at both apex and www would otherwise report half its own
     * links as outbound -- so a genuine www->apex link DOES read as outbound, and
     * that is the accepted cost of having no canonical-domain setting.
     */
    test('the comparison is the host, so a port or a path does not matter', () => {
        const t = newTracker();
        const here = window.location.hostname;

        expect(t.isOutboundUrl('https://' + here + '/somewhere/else?a=1#x')).toBe(false);
        expect(t.isOutboundUrl('https://www.' + here + '/same/path')).toBe(true);
    });

    test('a click carries the verdict as is_outbound', () => {
        const sent = [];
        const t = newTracker();
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.setOption('logClicksAsTheyHappen', true);

        const a = document.createElement('a');
        a.id = 'x';
        a.href = 'https://elsewhere.example/page';
        document.body.appendChild(a);
        t.clickEventHandler({ target: a, pageX: 1, pageY: 2 });
        document.body.removeChild(a);

        const click = sent.find((p) => p.event_type === 'click');

        expect(click).toBeDefined();
        expect(click.is_outbound).toBe(1);
    });

    test('a file_download does not carry it -- the click answers that', () => {
        const sent = [];
        const t = newTracker();
        t.logEvent = (properties) => { sent.push(properties); return true; };

        t.classifyClickTarget('https://cdn.example.org/docs/guide.pdf');

        const download = sent.find((p) => p.event_type === 'file_download');

        expect(download).toBeDefined();
        expect(download.is_outbound).toBeUndefined();
    });
});

/*
 * THE ELEMENT-PATH SUITE IS GONE with getElementPath(). It built a CSS selector
 * that no report, widget or overlay read, and that a template edit renumbers
 * wholesale -- see Update053.
 */

describe('forms', () => {

    test('form_start fires once per form, form_submit on send', () => {
        document.body.innerHTML =
            '<form id="signup" name="Newsletter"><input id="e"><input id="f"></form>';

        const t = newTracker();
        const sent = captureSends(t);

        t.trackForms();

        document.getElementById('e').dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('f').dispatchEvent(new Event('change', { bubbles: true }));

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

        document.getElementById('ai').dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('bi').dispatchEvent(new Event('change', { bubbles: true }));

        expect(sent.filter(e => e.event_type === 'form_start').map(e => e.form_id))
            .toEqual(['a', 'b']);
    });

    test('a change outside a form is not a form start', () => {
        document.body.innerHTML = '<input id="loose">';

        const t = newTracker();
        const sent = captureSends(t);
        t.trackForms();

        document.getElementById('loose').dispatchEvent(new Event('change', { bubbles: true }));

        expect(sent).toHaveLength(0);
    });

    /*
     * Clicking into a form, or tabbing through it, is not having begun it. A start
     * needs a changed value.
     */
    test('focus alone is not a form start', () => {
        document.body.innerHTML = '<form id="f"><input id="a"></form>';

        const t = newTracker();
        const sent = captureSends(t);
        t.trackForms();

        const field = document.getElementById('a');
        field.dispatchEvent(new Event('focusin', { bubbles: true }));
        field.dispatchEvent(new Event('focus'));
        field.dispatchEvent(new Event('input', { bubbles: true }));

        expect(sent).toHaveLength(0);
    });

    /*
     * A submit with no start raises one first, so the funnel never shows more
     * submits than starts. No field changed, so no first-field properties.
     */
    test('a submit with no start raises form_start first, without first-field properties', () => {
        document.body.innerHTML =
            '<form id="f" name="N"><input id="a" name="email"></form>';

        const t = newTracker();
        const sent = captureSends(t);
        t.trackForms();

        document.getElementById('f')
            .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(sent.map(e => e.event_type)).toEqual(['form_start', 'form_submit']);

        const start = sent[0];
        expect(start.form_id).toBe('f');
        expect(start.form_name).toBe('N');
        expect(start.form_length).toBe(1);
        expect(start.first_field_id).toBeUndefined();
        expect(start.first_field_name).toBeUndefined();
        expect(start.first_field_type).toBeUndefined();
        expect(start.first_field_position).toBeUndefined();
    });

    test('a submit after a change raises no second start', () => {
        document.body.innerHTML = '<form id="f"><input id="a"></form>';

        const t = newTracker();
        const sent = captureSends(t);
        t.trackForms();

        document.getElementById('a').dispatchEvent(new Event('change', { bubbles: true }));

        const form = document.getElementById('f');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(sent.map(e => e.event_type))
            .toEqual(['form_start', 'form_submit', 'form_submit']);
        expect(sent[0].first_field_id).toBe('a');
    });
});

describe('scroll depth', () => {

    /*
     * UNTIL trackScroll() EXISTED, THIS RODE DOMSTREAM. bindScrollEvents() was
     * reachable only through the recorder's streamBindings, which trackDomStream()
     * iterates -- so a first-class event fired on installs with that module active,
     * for the sampled fraction of visitors, and nowhere else.
     */
    test('trackScroll binds its own listener, without domstream', () => {
        const t = newTracker();
        const bound = [];
        const real = window.addEventListener;
        window.addEventListener = (type, fn, capture) => {
            bound.push(type);
            return real.call(window, type, fn, capture);
        };

        try {
            expect(t.getOption('trackDomStream')).not.toBe(true);
            t.trackScroll();
        } finally {
            window.addEventListener = real;
        }

        expect(bound).toContain('scroll');
    });

    /*
     * The snippet pushes each command once, but a site can push one twice, and two
     * listeners would report every threshold twice.
     */
    test('pushing the command twice binds once', () => {
        const t = newTracker();
        let bound = 0;
        const real = window.addEventListener;
        window.addEventListener = (type, fn, capture) => {
            if (type === 'scroll') { bound++; }
            return real.call(window, type, fn, capture);
        };

        try {
            t.trackScroll();
            t.trackScroll();
        } finally {
            window.addEventListener = real;
        }

        expect(bound).toBe(1);
    });

    /*
     * THE RECORDER'S BINDING IS A DIFFERENT FEATURE. It samples scroll position for
     * playback; this checks whether a depth threshold was passed. They were one
     * handler because window.onscroll is a single slot, which is why depth fired
     * only where domstream was active -- and why neither uses that slot now.
     */
    test('the domstream sampler queues nothing when domstream is off', () => {
        const t = newTracker();
        const sent = captureSends(t);
        const queued = [];
        t.addToEventQueue = (event) => queued.push(event.get('event_type'));

        t.scrollEventHandler({});

        expect(queued).toEqual([]);
        expect(sent).toHaveLength(0);
    });

    /*
     * THE DEFAULT IS QUARTILES ENDING AT 90. A single 90% mark answered only "did
     * they reach the end"; nothing could say how far down people get. The tests
     * around this one pin [90] themselves because they are about the mechanism,
     * not the default.
     */
    test('the default thresholds are 25, 50, 75 and 90', () => {
        expect(newTracker().getOption('scrollThresholds')).toEqual([25, 50, 75, 90]);
    });

    test('with the defaults, a jump to the bottom reports every level', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.getScrollDepth = () => 100;
        t.checkScrollDepth();

        expect(sent.map((e) => e.scroll_depth)).toEqual([25, 50, 75, 90]);
    });

    test('passing the threshold raises one scroll event carrying the depth', () => {
        const t = newTracker({ scrollThresholds: [90] });
        const sent = captureSends(t);

        t.getScrollDepth = () => 95;
        t.checkScrollDepth();

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('scroll');
        expect(sent[0].scroll_depth).toBe(90);
    });

    /* ONE EVENT PER PAGE PER THRESHOLD, not one per scroll tick. */
    test('a second scroll past the same threshold raises nothing', () => {
        const t = newTracker({ scrollThresholds: [90] });
        const sent = captureSends(t);

        t.getScrollDepth = () => 95;
        t.checkScrollDepth();
        t.checkScrollDepth();

        expect(sent).toHaveLength(1);
    });

    /*
     * EVERY MARK CROSSED, in ascending order whatever order the site gave. With
     * [90, 25] a jump to the bottom used to report 90 and then skip 25 for good,
     * because last_scroll (90) is not below 25.
     */
    test('an unsorted list still reports every mark a jump crosses', () => {
        const t = newTracker({ scrollThresholds: [90, 25, 50] });
        const sent = captureSends(t);

        t.getScrollDepth = () => 100;
        t.checkScrollDepth();

        expect(sent.map((e) => e.scroll_depth)).toEqual([25, 50, 90]);
    });

    test('marks outside 1..100 are ignored rather than reported', () => {
        const t = newTracker({ scrollThresholds: [0, -5, 150, 50] });
        const sent = captureSends(t);

        t.getScrollDepth = () => 100;
        t.checkScrollDepth();

        expect(sent.map((e) => e.scroll_depth)).toEqual([50]);
    });

    /*
     * A PAGE THAT FITS IN THE VIEWPORT is read to the end without a scroll event,
     * so the depth is also checked once at load.
     */
    test('a page already loaded is checked without waiting for a scroll', () => {
        const t = newTracker({ scrollThresholds: [90] });
        const sent = captureSends(t);

        t.getScrollDepth = () => 100;
        t.trackScroll();

        expect(document.readyState).toBe('complete');
        expect(sent.map((e) => e.event_type)).toEqual(['scroll']);
    });

    /*
     * And NOT before load. A long page measured before layout has a small height,
     * which reads as scrolled to the bottom.
     */
    test('a page still loading is checked at load, not before', () => {
        const t = newTracker({ scrollThresholds: [90] });
        const sent = captureSends(t);
        const state = Object.getOwnPropertyDescriptor(Document.prototype, 'readyState');

        Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });

        try {
            t.getScrollDepth = () => 100;
            t.trackScroll();

            expect(sent).toHaveLength(0);

            window.dispatchEvent(new Event('load'));

            expect(sent.map((e) => e.event_type)).toEqual(['scroll']);
        } finally {
            delete document.readyState;
            if (state) { Object.defineProperty(Document.prototype, 'readyState', state); }
        }
    });

    /*
     * One check per frame, however many scroll events arrive in it.
     *
     * Drives the listener THIS tracker registered, captured as it binds. Earlier
     * trackers in the file leave their own listeners on the shared window, so
     * dispatching a real scroll event would measure all of them.
     */
    test('many scroll events in one frame make one check', () => {
        const t = newTracker();
        const frames = [];
        const raf = window.requestAnimationFrame;
        const add = window.addEventListener;
        let listener = null;

        window.requestAnimationFrame = (fn) => { frames.push(fn); return frames.length; };
        window.addEventListener = (type, fn, opts) => {
            if (type === 'scroll') { listener = fn; }
            return add.call(window, type, fn, opts);
        };

        let checks = 0;
        t.checkScrollDepth = () => { checks++; };

        try {
            t.trackScroll();
            checks = 0;   // the load-time check is not what this measures

            for (let i = 0; i < 20; i++) {
                listener();
            }

            expect(frames).toHaveLength(1);
            frames.shift()();
            expect(checks).toBe(1);

            // And the next frame's scrolling gets its own check.
            listener();
            expect(frames).toHaveLength(1);
        } finally {
            window.requestAnimationFrame = raf;
            window.addEventListener = add;
        }
    });

    test('short of the threshold raises nothing', () => {
        const t = newTracker({ scrollThresholds: [90] });
        const sent = captureSends(t);

        t.getScrollDepth = () => 40;
        t.checkScrollDepth();

        expect(sent).toHaveLength(0);
    });
});

describe('what a form event says about the form', () => {

    /** Fire a form_start by changing a field, and return the beacon. */
    function startOn(html, selector) {
        document.body.innerHTML = html;

        const t = newTracker();
        const sent = [];
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.trackForms();

        document.querySelector(selector)
            .dispatchEvent(new Event('change', { bubbles: true }));

        return sent.find((p) => p.event_type === 'form_start');
    }

    test('the destination is absolute, so two sites /subscribe are two forms', () => {
        const start = startOn(
            '<form id="f" action="/subscribe"><input id="a"></form>', '#a');

        expect(start.form_destination).toMatch(/^https?:\/\/[^/]+\/subscribe$/);
    });

    /*
     * A form with no action submits to the page itself, which is what the value
     * says rather than reporting an empty destination.
     */
    test('no action means the page itself', () => {
        const start = startOn('<form id="f"><input id="a"></form>', '#a');

        expect(start.form_destination).toBe(window.location.href);
    });

    /*
     * BUTTONS AND HIDDEN INPUTS ARE NOT FIELDS. Counting them makes the number
     * mean nothing in particular -- a two-field form with a submit button is not
     * three fields to fill in.
     */
    test('the length counts fillable fields only', () => {
        const start = startOn(
            '<form id="f">'
            + '<input id="a"><select id="b"><option>x</option></select>'
            + '<textarea id="c"></textarea>'
            + '<input type="hidden" name="nonce" value="z">'
            + '<button type="submit">Go</button>'
            + '<input type="submit" value="Also go">'
            + '</form>', '#a');

        expect(start.form_length).toBe(3);
    });

    /*
     * ONE-BASED, and the position is of the field that changed first -- which is
     * the signal: someone who begins at field three skipped two.
     */
    test('the first field is the one that changed, counted from one', () => {
        const html = '<form id="f"><input id="a"><input id="b" name="email">'
            + '<input id="c"></form>';

        expect(startOn(html, '#a').first_field_position).toBe(1);

        const second = startOn(html, '#b');

        expect(second.first_field_position).toBe(2);
        expect(second.first_field_id).toBe('b');
        expect(second.first_field_name).toBe('email');
    });

    test('the first field type is the element type', () => {
        const html = '<form id="f"><input id="plain"><input id="e" type="email">'
            + '<input id="c" type="CHECKBOX"><select id="s"><option>x</option></select>'
            + '<select id="m" multiple><option>x</option></select>'
            + '<textarea id="t"></textarea></form>';

        expect(startOn(html, '#plain').first_field_type).toBe('text');
        expect(startOn(html, '#e').first_field_type).toBe('email');
        expect(startOn(html, '#c').first_field_type).toBe('checkbox');
        expect(startOn(html, '#s').first_field_type).toBe('select-one');
        expect(startOn(html, '#m').first_field_type).toBe('select-multiple');
        expect(startOn(html, '#t').first_field_type).toBe('textarea');
    });

    /*
     * form_submit carries the form, not where the visitor started: by the time it
     * is submitted that is not a property of the submission.
     */
    test('a submit carries the form and not the first field', () => {
        document.body.innerHTML =
            '<form id="f" name="N" action="/x"><input id="a">'
            + '<button id="go" type="submit">Subscribe now</button></form>';

        const t = newTracker();
        const sent = [];
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.trackForms();

        document.getElementById('a')
            .dispatchEvent(new Event('change', { bubbles: true }));

        const submit = new Event('submit', { bubbles: true, cancelable: true });
        document.getElementById('f').dispatchEvent(submit);

        const beacon = sent.find((p) => p.event_type === 'form_submit');

        expect(beacon).toBeDefined();
        expect(beacon.form_id).toBe('f');
        expect(beacon.form_name).toBe('N');
        expect(beacon.form_length).toBe(1);
        expect(beacon.first_field_id).toBeUndefined();
        expect(beacon.first_field_position).toBeUndefined();
    });

    /*
     * WHICH BUTTON SENT IT. A dispatched Event has no `submitter`, and then the
     * property is absent rather than guessed -- which is exactly the case above,
     * so this asserts the presence path separately.
     */
    test('the submit text names the control, when there was one', () => {
        document.body.innerHTML =
            '<form id="f" action="/x"><input id="a">'
            + '<button id="go" type="submit">Save and publish</button></form>';

        const t = newTracker();
        const sent = [];
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.trackForms();

        const submit = new Event('submit', { bubbles: true, cancelable: true });
        Object.defineProperty(submit, 'submitter',
            { value: document.getElementById('go') });
        document.getElementById('f').dispatchEvent(submit);

        const beacon = sent.find((p) => p.event_type === 'form_submit');

        expect(beacon.form_submit_text).toBe('Save and publish');
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

    /*
     * THE DEFAULTS ARE THE FEATURE. This used to assert the opposite -- that
     * nothing is raised without configuration -- on the reasoning that no
     * convention exists so a default would guess wrong. The cost of that was total:
     * siteSearchParams was empty AND trackSiteSearch() had no caller, so
     * view_search_results could not fire on any install at all.
     */
    test('the shipped defaults raise it with no configuration', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.getUrlParam = (name) => (name === 'q' ? 'shoes' : false);
        t.trackSiteSearch();

        expect(sent).toHaveLength(1);
        expect(sent[0].event_type).toBe('view_search_results');
        expect(sent[0].search_term).toBe('shoes');
    });

    test('a page with no search parameter raises nothing', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.getUrlParam = () => false;
        t.trackSiteSearch();

        expect(sent).toHaveLength(0);
    });

    /*
     * A site that clears the option gets no site-search tracking, which is the
     * escape hatch for one whose ?q= means something else.
     */
    test('an empty option switches it off', () => {
        const t = newTracker({ siteSearchParams: [] });
        const sent = captureSends(t);

        t.getUrlParam = () => 'anything';
        t.trackSiteSearch();

        expect(sent).toHaveLength(0);
    });

    /*
     * IT REPLACES THE DEFAULTS, which is the whole point of the setter: a site
     * whose ?q= means something else must be able to stop it being read as a
     * search, and merging would make the shipped list impossible to get rid of.
     */
    test('an array replaces the defaults rather than adding to them', () => {
        const t = newTracker();

        expect(t.getOption('siteSearchParams')).toContain('q');

        t.setSearchQueryParams(['kw']);

        expect(t.getOption('siteSearchParams')).toEqual(['kw']);

        const sent = captureSends(t);
        // ?q= is still on the URL and must no longer be read as a search.
        t.getUrlParam = (name) => (name === 'q' ? 'shoes' : false);
        t.trackSiteSearch();

        expect(sent).toHaveLength(0);

        t.getUrlParam = (name) => (name === 'kw' ? 'boots' : false);
        t.trackSiteSearch();

        expect(sent).toHaveLength(1);
        expect(sent[0].search_term).toBe('boots');
    });

    test('names are kept in order, and blanks are dropped', () => {
        const t = newTracker();

        t.setSearchQueryParams([' kw ', '', 'q']);

        expect(t.getOption('siteSearchParams')).toEqual(['kw', 'q']);
    });

    /* An empty array is how a site turns it off. */
    test('an empty array switches it off', () => {
        const t = newTracker();

        t.setSearchQueryParams([]);

        expect(t.getOption('siteSearchParams')).toEqual([]);

        const sent = captureSends(t);
        t.getUrlParam = () => 'anything';
        t.trackSiteSearch();

        expect(sent).toHaveLength(0);
    });

    /*
     * A BARE STRING IS REFUSED, not coerced. trackSiteSearch() walks the value by
     * index, and a string has a length and indexes to characters, so 'query' would
     * search for q, u, e, r and y -- finding nothing and saying nothing. The
     * previous value stands, so the tracker keeps working.
     */
    test('a bare string is refused and the previous value stands', () => {
        const t = newTracker();
        const before = t.getOption('siteSearchParams');

        t.setSearchQueryParams('query');

        expect(t.getOption('siteSearchParams')).toEqual(before);

        t.setSearchQueryParams(42);

        expect(t.getOption('siteSearchParams')).toEqual(before);
    });

    /*
     * THE CACHE IS KEYED ON THE URL IT PARSED. Filled once and kept, an SPA read
     * the FIRST page's parameters for the rest of the session -- so every route
     * change reported the term from whatever screen the visitor landed on, which is
     * a wrong answer rather than a missing one.
     */
    test('the parameter cache follows the URL', () => {
        const t = newTracker();

        history.pushState({}, '', '/search?q=first');
        expect(t.getUrlParam('q')).toBe('first');

        history.pushState({}, '', '/search?q=second');
        expect(t.getUrlParam('q')).toBe('second');

        // And a URL with no parameters answers false rather than the last page's.
        history.pushState({}, '', '/plain');
        expect(t.getUrlParam('q')).toBe(false);
    });

    /*
     * NOT CHAINED TO trackPageView. A public method whose contract is "send a page
     * view" must not also send a different event -- and the chain broke on the
     * argument trackPageView takes, because parseUrlParams() ignored its own url
     * parameter and read location.href. The snippet pushes trackSiteSearch, and
     * trackRouteChanges() calls it per route.
     */
    test('a page view does not raise it by itself', () => {
        const t = newTracker();
        const sent = captureSends(t);

        t.getUrlParam = (name) => (name === 'q' ? 'partitioning' : false);
        t.trackPageView('https://example.org/search?q=partitioning');

        expect(sent.map((e) => e.event_type)).toEqual(['page_view']);
    });

    /*
     * BINDS NOTHING, so it is called per page like trackPageView rather than once
     * like trackClicks. An SPA route change is a new page and therefore a new
     * search.
     */
    test('a route change raises it again, with the new term', () => {
        const t = newTracker();
        const sent = captureSends(t);
        const terms = ['first', 'second'];

        t.getUrlParam = (name) => (name === 'q' ? terms.shift() : false);

        t.trackRouteChanges();
        t.trackSiteSearch();

        history.pushState({}, '', '/search?q=second');

        const searches = sent.filter((e) => e.event_type === 'view_search_results');

        expect(searches).toHaveLength(2);
        expect(searches.map((e) => e.search_term)).toEqual(['first', 'second']);
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


/**
 * ENGAGEMENT TIME RIDES ANY BEACON, which is what makes it a delta.
 *
 * addDefaultsToEvent() attaches the accrued-and-unreported time to EVERY event
 * that does not already carry it, and consumeEngagementDelta() banks what it
 * hands out -- so the same milliseconds cannot ride two beacons and a total is a
 * plain SUM over every event type. Losing one beacon costs one increment instead
 * of a page.
 *
 * WHY IT IS EASY TO GET WRONG IN THE REGISTRY, and was: the property is
 * CONDITIONAL. `if ( delta > 0 )` means a beacon sent with no time accrued does
 * not carry it at all, so a contract fixture recorded in a headless test where no
 * clock advances shows it on nothing -- and reading those fixtures suggests only
 * page_view and user_engagement ever send it. Advancing the clock is the only way
 * to see the real shape.
 */
describe('engagement time is a per-event delta', () => {

    // jsdom answers false; the engagement clock only runs for a focused page.
    beforeEach(() => { document.hasFocus = () => true; });

    test('a click carries the time accrued since the last report', () => {
        const sent = [];
        const t = newTracker();
        let now = 1000000;
        t.getTime = () => now;
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.resetEngagement();

        now += 4200;

        const click = t.makeEvent();
        click.setEventType('click');
        t.trackEvent(click);

        const beacon = sent.find((p) => p.event_type === 'click');

        expect(beacon).toBeDefined();
        expect(beacon.engagement_msec).toBe(4200);
    });

    test('the same milliseconds do not ride a second beacon', () => {
        const sent = [];
        const t = newTracker();
        let now = 1000000;
        t.getTime = () => now;
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.resetEngagement();

        now += 4200;
        const first = t.makeEvent();
        first.setEventType('click');
        t.trackEvent(first);

        // No further time passes, so there is no delta left to report.
        const second = t.makeEvent();
        second.setEventType('scroll');
        t.trackEvent(second);

        const scroll = sent.find((p) => p.event_type === 'scroll');

        expect(scroll).toBeDefined();
        expect(scroll.engagement_msec).toBeUndefined();
    });
});
