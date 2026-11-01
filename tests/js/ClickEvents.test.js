import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { ElementAnchor } from '../../modules/Base/src/tracker/ElementAnchor.js';

/**
 * DOM-click tracking.
 *
 * trackClicks() turns on click-as-it-happens logging: it flips
 * logClicksAsTheyHappen and binds a single window click listener
 * (bindClickEvents is idempotent via isClickTrackingEnabled). Each click then
 * fires its own click beacon carrying the clicked element's id, name, class,
 * tag, text/target_url, and click coordinates.
 *
 * Driven by exercising the handler directly with synthetic events, since jsdom
 * does not lay out or navigate the DOM. Image is stubbed to capture beacons
 * without hitting the network.
 */
function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.cv.example' });
    t.setSiteId('click-site');
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

    /*
     * WHERE ON THE ELEMENT, which the heatmap draws the click by. The element
     * is the one hit, not the link around it, whose box need not hold the
     * point; and a click with no pointer behind it has none.
     */
    test('records where on the clicked element the click landed', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<div id="nav"><a id="out" href="/p"><span>Go</span></a></div>';
        const span = document.querySelector('span');
        span.getBoundingClientRect = () => ({ left: 100, top: 20, width: 50, height: 10 });

        t.clickEventHandler({ target: span, pageX: 125, pageY: 1025, clientX: 125, clientY: 25, detail: 1 });

        const c = t.click.getProperties();
        expect(c.dom_element_id).toBe('out');
        expect(c.element_anchor).toBe(ElementAnchor.hash('#out>span:1'));
        expect(c.anchor_x).toBe(500);
        expect(c.anchor_y).toBe(500);
    });

    test('a click from the keyboard or a script carries no anchor', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<a id="k" href="/k">k</a>';
        const a = document.getElementById('k');
        a.getBoundingClientRect = () => ({ left: 0, top: 0, width: 50, height: 10 });

        t.clickEventHandler({ target: a, pageX: 0, pageY: 0, clientX: 0, clientY: 0, detail: 0 });

        expect(t.click.getProperties()).not.toHaveProperty('element_anchor');
        expect(t.click.getProperties()).not.toHaveProperty('anchor_x');
    });

    test('the anchor travels under the element\'s wire names', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', true);
        document.body.innerHTML = '<button id="w">x</button>';
        const b = document.getElementById('w');
        b.getBoundingClientRect = () => ({ left: 0, top: 0, width: 100, height: 100 });

        t.clickEventHandler({ target: b, pageX: 25, pageY: 75, clientX: 25, clientY: 75, detail: 1 });

        const q = new URLSearchParams(beacons[0].split('?')[1]);
        expect(q.get('el_a')).toBe(ElementAnchor.hash('#w'));
        expect(q.get('el_ax')).toBe('250');
        expect(q.get('el_ay')).toBe('750');
    });

    test('fires a click beacon when logClicksAsTheyHappen is on', () => {
        const t = newTracker();
        t.setOption('logClicksAsTheyHappen', true);
        document.body.innerHTML = '<button id="b">x</button>';

        t.clickEventHandler(clickOn(document.getElementById('b')));

        expect(beacons.length).toBe(1);
        expect(beacons[0]).toMatch(/click/);
    });

    test('a click is announced to anything compiled in (tracker.click)', () => {
        const t = newTracker();
        const heard = [];
        OWA.addAction('tracker.click', (o) => { if (o.tracker === t) heard.push(o.click.get('dom_element_id')); });
        t.setOption('logClicksAsTheyHappen', false);
        document.body.innerHTML = '<button id="announced">x</button>';

        t.clickEventHandler(clickOn(document.getElementById('announced')));

        expect(heard).toEqual(['announced']);
    });
});
