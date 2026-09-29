import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * DOM-click tracking and DomStream capture/sampling.
 *
 * OWA can record individual DOM interactions and, separately, roll a page's
 * interaction events up into a periodic "DomStream" beacon:
 *
 *   - trackClicks() turns on click-as-it-happens logging: it flips
 *     logClicksAsTheyHappen and binds a single window click listener
 *     (bindClickEvents is idempotent via isClickTrackingEnabled). Each click
 *     then fires its own dom.click beacon carrying the clicked element's id,
 *     name, class, tag, text/target_url, and click coordinates.
 *
 *   - DomStream is sampled per visitor: trackDomStream() draws a 1..100 random
 *     number and only activates when it is <= logDomStreamPercentage
 *     (setDomstreamSampleRate / the logDomStreamPercentage option, default 100).
 *     When active it sets trackDomStream (so the click handler QUEUES events
 *     instead of/in addition to beaconing) and starts the flush timer.
 *
 *   - logDomStream() flushes the queue: it only emits a dom.stream beacon when
 *     the queue holds MORE than domstreamEventThreshold events (default 10),
 *     stamps a domstream_guid + duration + serialized stream, and clears the
 *     queue. Below threshold it is a no-op.
 *
 * These are driven by exercising the handler/flush methods directly with
 * synthetic events, since jsdom does not lay out or navigate the DOM. Image is
 * stubbed to capture beacons without hitting the network, and Math.random is
 * pinned so the sampling gate is deterministic.
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.cv.example' });
    t.setSiteId('domstream-site');
    return t;
}

// A synthetic click event: jsdom won't lay the DOM out, so we hand the handler
// the target + coordinates it reads.
function clickOn(target) {
    return { target, pageX: 12, pageY: 34 };
}

let beacons;
let OrigImage;

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    OWA.setSetting('loggerPause', false);

    beacons = [];
    OrigImage = global.Image;
    global.Image = class { set src(v) { beacons.push(v); } };
});

afterEach(() => {
    global.Image = OrigImage;
    document.body.innerHTML = '';
});

describe('trackClicks()', () => {

    test('enables click logging and marks click tracking bound', () => {
        const t = newTracker();
        expect(t.getOption('logClicksAsTheyHappen')).toBeFalsy();
        expect(t.isClickTrackingEnabled).toBe(false);

        t.trackClicks();

        expect(t.getOption('logClicksAsTheyHappen')).toBe(true);
        expect(t.isClickTrackingEnabled).toBe(true);
    });

    test('bindClickEvents is idempotent (a second call does not re-bind)', () => {
        const t = newTracker();
        t.bindClickEvents();
        expect(t.isClickTrackingEnabled).toBe(true);
        // Second call short-circuits on the isClickTrackingEnabled guard.
        expect(() => t.bindClickEvents()).not.toThrow();
        expect(t.isClickTrackingEnabled).toBe(true);
    });
});

describe('clickEventHandler builds the click event', () => {

    test('captures an anchor element id/name/class/tag/text/target_url/coords', () => {
        const t = newTracker();
        // Inspect the built event without beaconing.
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML =
            '<a id="lnk" class="btn" name="nav" href="https://x.example/y">Go Here</a>';

        t.clickEventHandler(clickOn(document.getElementById('lnk')));

        const c = t.click.getProperties();
        expect(c.event_type).toBe('click');
        expect(c.dom_element_id).toBe('lnk');
        expect(c.dom_element_name).toBe('nav');
        expect(c.dom_element_class).toBe('btn');
        expect(c.dom_element_tag).toBe('a');       // lower-cased tag name
        expect(c.target_url).toBe('https://x.example/y');
        expect(c.dom_element_text).toBe('Go Here');
        expect(c.click_x).toBe('12');
        expect(c.click_y).toBe('34');
    });

    test('sends nothing for an absent id, name or class, and no element value', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<span>hi</span>';
        t.clickEventHandler(clickOn(document.querySelector('span')));

        const c = t.click.getProperties();
        expect(c).not.toHaveProperty('dom_element_name');
        expect(c).not.toHaveProperty('dom_element_id');
        expect(c).not.toHaveProperty('dom_element_class');
        expect(c.dom_element_tag).toBe('span');

        /*
         * THE ELEMENT'S VALUE IS NOT COLLECTED. A click on an input would have
         * shipped whatever the visitor had typed into it, and nothing ever read
         * it: no column, and the server's registry declares no destination. Its
         * own offsets go too -- the heatmap reads the CLICK's coordinates.
         */
        expect(c.dom_element_value).toBeUndefined();
        expect(c.dom_element_x).toBeUndefined();
        expect(c.dom_element_y).toBeUndefined();
    });

    /*
     * A CLICK INSIDE A LINK IS A CLICK ON THE LINK. Only a click directly on the
     * <a>, or on an <img> in one, used to carry the URL, so most modern link
     * markup reached the server with no destination: not outbound, not a
     * download, and named by the inner element's missing id.
     */
    test('a click on markup inside a link reports the link', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML =
            '<a id="out" class="cta" href="https://elsewhere.example/p"><span><b id="deep">Go</b></span></a>';

        t.clickEventHandler(clickOn(document.getElementById('deep')));

        const c = t.click.getProperties();
        expect(c.dom_element_id).toBe('out');
        expect(c.dom_element_class).toBe('cta');
        expect(c.dom_element_tag).toBe('a');
        expect(c.target_url).toBe('https://elsewhere.example/p');
        expect(c.dom_element_text).toBe('Go');
        expect(c.is_outbound).toBe(1);
        expect(c.click_x).toBe('12', 'the coordinates are still the click\'s');
    });

    test('an svg inside a link reports the link, and an svg class is read as a string', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML =
            '<a id="icon-link" href="/inside"><svg class="icon"><circle id="c"></circle></svg></a>'
            + '<svg id="bare" class="chart"><rect id="r"></rect></svg>';

        t.clickEventHandler(clickOn(document.getElementById('c')));
        expect(t.click.getProperties().dom_element_id).toBe('icon-link');

        t.clickEventHandler(clickOn(document.getElementById('bare')));
        expect(t.click.getProperties().dom_element_class).toBe('chart');
    });

    test('an anchor with no href is not a link, so the clicked element stands', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<a id="named"><span id="inner">x</span></a>';

        t.clickEventHandler(clickOn(document.getElementById('inner')));

        expect(t.click.getProperties().dom_element_id).toBe('inner');
    });

    /*
     * A MIDDLE-CLICK fires auxclick, not click: opening a link in a new tab was
     * never recorded. It counts on a link only.
     */
    test('a middle-click on a link is a click; on anything else it is nothing', () => {
        const t = newTracker();
        const sent = [];
        t.logEvent = (properties) => { sent.push(properties); return true; };
        t.setOption('logClicksAsTheyHappen', true);
        t.bindClickEvents();
        document.body.innerHTML = '<a id="tab" href="https://elsewhere.example/t"><span id="s">t</span></a>'
            + '<div id="plain">p</div>';

        const middle = (el) => el.dispatchEvent(new MouseEvent('auxclick', { bubbles: true, button: 1 }));
        const right = (el) => el.dispatchEvent(new MouseEvent('auxclick', { bubbles: true, button: 2 }));

        middle(document.getElementById('s'));
        middle(document.getElementById('plain'));
        right(document.getElementById('s'));

        const clicks = sent.filter((p) => p.event_type === 'click');
        expect(clicks).toHaveLength(1);
        expect(clicks[0].dom_element_id).toBe('tab');
        expect(clicks[0].is_outbound).toBe(1);
    });

    test('a click on a text node is attributed to its element', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<a id="t" href="/x">text</a>';

        t.clickEventHandler(clickOn(document.getElementById('t').firstChild));

        expect(t.click.getProperties().dom_element_id).toBe('t');
    });

    test('fires a dom.click beacon when logClicksAsTheyHappen is on', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', true);
        document.body.innerHTML = '<button id="b">x</button>';

        t.clickEventHandler(clickOn(document.getElementById('b')));

        expect(beacons.length).toBe(1);
        expect(beacons[0]).toMatch(/click/);
    });

    test('queues (does not beacon) the click when DomStream capture is active', () => {
        const t = newTracker();
        // DomStream capture on, immediate beaconing off: the click goes into the
        // queue to be flushed later by logDomStream.
        t.setOption('trackDomStream', true);
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<button id="b">x</button>';

        t.clickEventHandler(clickOn(document.getElementById('b')));

        expect(t.event_queue.length).toBe(1);
        expect(beacons.length).toBe(0);
    });
});

describe('DomStream sampling (trackDomStream / setDomstreamSampleRate)', () => {

    test('logDomStreamPercentage defaults to 100 (everyone sampled)', () => {
        const t = newTracker();
        expect(t.getOption('logDomStreamPercentage')).toBe(100);
    });

    test('setDomstreamSampleRate updates the sample percentage', () => {
        const t = newTracker();
        t.setDomstreamSampleRate(25);
        expect(t.getOption('logDomStreamPercentage')).toBe(25);
    });

    test('activates capture when the sample roll is within the percentage', () => {
        const t = newTracker();
        t.setDomstreamSampleRate(100);
        // rand = floor(random*100+1) = 51 <= 100 -> sampled in.
        const origRandom = Math.random;
        Math.random = () => 0.5;
        try {
            t.trackDomStream();
        } finally {
            Math.random = origRandom;
        }
        expect(t.getOption('trackDomStream')).toBe(true);
    });

    test('does NOT activate capture when the sample roll exceeds the percentage', () => {
        const t = newTracker();
        t.setDomstreamSampleRate(0);
        // rand is always >= 1, which is never <= 0 -> sampled out.
        const origRandom = Math.random;
        Math.random = () => 0;
        try {
            t.trackDomStream();
        } finally {
            Math.random = origRandom;
        }
        expect(t.getOption('trackDomStream')).toBeFalsy();
    });

    test('does nothing while the tracker is paused', () => {
        const t = newTracker();
        t.setDomstreamSampleRate(100);
        t.pause();
        const origRandom = Math.random;
        Math.random = () => 0.5;
        try {
            t.trackDomStream();
        } finally {
            Math.random = origRandom;
        }
        expect(t.getOption('trackDomStream')).toBeFalsy();
    });
});

describe('logDomStream() queue flush', () => {

    test('emits nothing when the queue is at or below the event threshold', () => {
        const t = newTracker();
        expect(t.getOption('domstreamEventThreshold')).toBe(10);
        // 1 event, threshold 10: below threshold -> no-op.
        t.event_queue = [{ event_type: 'click' }];

        const result = t.logDomStream();

        expect(result).toBeUndefined();
        expect(beacons.length).toBe(0);
        // Queue is left intact for a later flush.
        expect(t.event_queue.length).toBe(1);
    });

    test('emits a dom.stream beacon and clears the queue when over threshold', () => {
        const t = newTracker();
        const queue = [];
        for (let i = 0; i < 11; i++) {
            queue.push({ event_type: 'click', n: i });
        }
        t.event_queue = queue;

        t.logDomStream();

        expect(beacons.length).toBe(1);
        expect(beacons[0]).toMatch(/dom\.stream/);
        // A domstream_guid is minted for upstream correlation, and the queue is
        // drained so events aren't double-counted on the next flush.
        expect(t.domstream_guid).toBeTruthy();
        expect(t.event_queue.length).toBe(0);
    });

    test('reuses the same domstream_guid across successive flushes', () => {
        const t = newTracker();
        const fill = () => {
            const q = [];
            for (let i = 0; i < 11; i++) q.push({ event_type: 'click', n: i });
            t.event_queue = q;
        };

        fill();
        t.logDomStream();
        const firstGuid = t.domstream_guid;

        fill();
        t.logDomStream();

        expect(t.domstream_guid).toBe(firstGuid);
    });
});
