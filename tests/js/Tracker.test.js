import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Event-assembly tests for the tracker's public track* methods.
 *
 * These assert that each method builds an Event with the correct event_type
 * and properties and hands it to trackEvent(). We spy on trackEvent so no
 * beacon/network/state machinery runs — the contract under test is purely
 * "does track.X produce the right event payload". This is the layer that
 * catches bundle-side regressions in event construction (e.g. a renamed or
 * dropped property, or a wrong event_type) without a browser.
 */
describe('OWATracker event assembly', () => {

    let tracker;
    let captured;

    beforeEach(() => {
        tracker = new OWATracker({});
        captured = [];
        // Intercept at trackEvent: capture the assembled Event, do not dispatch.
        tracker.trackEvent = (event) => { captured.push(event); };
    });

    /**
     * trackAction sends 1.x's track.action with 1.x's property names, for the
     * server's beacon compat layer to reshape into the custom event a migrated
     * 1.x action is. The reshaping rule lives on the server only.
     */
    test('trackAction sends a track.action event with the 1.x property names', () => {
        tracker.trackAction('signup', 'Newsletter Opt In', 'footer form', 10);

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('track.action');
        expect(e.get('action_group')).toBe('signup');
        expect(e.get('action_name')).toBe('Newsletter Opt In');
        expect(e.get('action_label')).toBe('footer form');
        expect(e.get('numeric_value')).toBe(10);
    });

    /** Absent arguments are not sent. */
    test('trackAction leaves out the arguments it was not given', () => {
        tracker.trackAction('group', 'Recipe Print');

        const e = captured[0];
        expect(e.get('action_label')).toBeUndefined();
        expect(e.get('numeric_value')).toBeUndefined();
    });

    /**
     * On the wire, as BeaconCompatActionTest.php feeds it to the server: the
     * same fixture holds both sides.
     */
    test('trackAction puts on the wire what the server reshapes', () => {
        const fixture = require('../fixtures/track_action_beacon.json');
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('track-action-site');

        let wire = null;
        t.logEvent = (properties) => { wire = t.prepareRequestData(properties); };

        t.trackAction('cooking', 'Recipe Print', 'Italian Chicken Soup', 1);

        expect(wire).not.toBeNull();

        for (const [key, value] of Object.entries(fixture.wire)) {
            expect([key, wire[key]]).toEqual([key, value]);
        }
    });

    test('trackPageView assembles a page_view event', () => {
        tracker.trackPageView('https://example.com/page');

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('page_view');
        expect(e.get('page_location')).toBe('https://example.com/page');
    });

    test('trackPageView without a url still sets the event_type', () => {
        tracker.trackPageView();

        expect(captured).toHaveLength(1);
        expect(captured[0].get('event_type')).toBe('page_view');
    });

    test('trackTransaction assembles an ecommerce.transaction event with line items', () => {
        tracker.addTransaction('order-1', 'web', 42.5, 2.5, 5, 'stripe', 'NYC', 'NY', 'US');
        tracker.addTransactionLineItem('order-1', 'SKU-1', 'Widget', 'widgets', 20, 2);
        tracker.trackTransaction();

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('purchase');
        expect(e.get('ct_order_id')).toBe('order-1');
        expect(e.get('ct_order_source')).toBe('web');
        expect(e.get('ct_total')).toBe(42.5);
        expect(e.get('ct_gateway')).toBe('stripe');

        const items = e.get('ct_line_items');
        expect(items).toHaveLength(1);
        // The shape trackPurchase() takes, so every purchase's items read alike.
        expect(items[0].item_id).toBe('SKU-1');
        expect(items[0].item_name).toBe('Widget');
        expect(items[0].quantity).toBe(2);
    });

    test('trackTransaction without a set-up transaction sends nothing', () => {
        tracker.trackTransaction();
        expect(captured).toHaveLength(0);
    });

    test('clickEventHandler assembles a click event from a DOM target', () => {
        // logClicksAsTheyHappen makes the handler hand the click to trackEvent.
        tracker.setOption('logClicksAsTheyHappen', true);

        // Build a real DOM target (jsdom) and a synthetic click event.
        const link = document.createElement('a');
        link.id = 'buy-now';
        link.setAttribute('name', 'buy');
        link.className = 'btn';
        link.textContent = 'Buy Now';
        document.body.appendChild(link);
        const event = { target: link, pageX: 12, pageY: 34 };

        tracker.clickEventHandler(event);

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('click');
        // dom_element_tag is lower-cased by getDomElementProperties() for
        // consistent storage regardless of how the browser reports tagName.
        expect(e.get('dom_element_tag')).toBe('a');
        expect(e.get('dom_element_id')).toBe('buy-now');
        expect(e.get('dom_element_name')).toBe('buy');
        expect(e.get('dom_element_class')).toBe('btn');
        // Coordinates are captured as strings.
        expect(e.get('click_x')).toBe('12');
        expect(e.get('click_y')).toBe('34');

        document.body.removeChild(link);
    });

    test('a click on an image outside a link has no target_url', () => {
        tracker.setOption('logClicksAsTheyHappen', true);

        const figure = document.createElement('figure');
        const img = document.createElement('img');
        img.alt = 'A rocket';
        figure.appendChild(img);
        document.body.appendChild(figure);

        tracker.clickEventHandler({ target: img, pageX: 1, pageY: 2 });

        const e = captured[0];
        expect(e.get('dom_element_tag')).toBe('img');
        expect(e.get('dom_element_text')).toBe('A rocket');
        // Not on the wire at all: a key holding undefined went out as the
        // string "undefined".
        expect(e.getProperties()).not.toHaveProperty('target_url');

        document.body.removeChild(figure);
    });

    test('a click on an image inside a link is the link, with its href', () => {
        tracker.setOption('logClicksAsTheyHappen', true);

        const link = document.createElement('a');
        link.href = 'https://example.com/full-size';
        const img = document.createElement('img');
        link.appendChild(img);
        document.body.appendChild(link);

        tracker.clickEventHandler({ target: img, pageX: 1, pageY: 2 });

        const e = captured[0];
        expect(e.get('dom_element_tag')).toBe('a');
        expect(e.get('target_url')).toBe('https://example.com/full-size');

        document.body.removeChild(link);
    });
});

/**
 * trackCustomEvent(eventType, properties).
 *
 * The documented public embed API is the async owa_cmds command queue, which is
 * fire-and-forget and cannot pass in an Event instance built by makeEvent(). So
 * custom events -- the one flow that required an Event object -- could not be
 * logged from the queue. trackCustomEvent builds the Event internally (like
 * trackAction does) so it can be driven from the queue; trackEvent(eventObject)
 * is left untouched for advanced/return-value use.
 *
 * These drive the REAL send path and intercept only the innermost logEvent, so
 * they exercise the Event construction end to end.
 */
describe('trackCustomEvent(eventType, properties)', () => {

    function newTracker() {
        const t = new OWATracker({ cookie_domain_set: true });
        t.setSiteId('custom-event-site');
        return t;
    }

    test('builds and logs a custom event from a type string and properties object', () => {
        const t = newTracker();
        let beacon = null;
        t.logEvent = (properties) => { beacon = properties; };

        t.trackCustomEvent('someeventname', { somename: 'somevalue', other: 2 });

        expect(beacon).not.toBeNull();
        expect(beacon.event_type).toBe('someeventname');
        expect(beacon.somename).toBe('somevalue');
        expect(beacon.other).toBe(2);
    });

    test('works with just an event type and no properties', () => {
        const t = newTracker();
        let beacon = null;
        t.logEvent = (properties) => { beacon = properties; };

        t.trackCustomEvent('someeventname');

        expect(beacon).not.toBeNull();
        expect(beacon.event_type).toBe('someeventname');
    });

    test('makeEvent() + trackEvent(event) still works (unchanged advanced path)', () => {
        const t = newTracker();
        let beacon = null;
        t.logEvent = (properties) => { beacon = properties; };

        const e = t.makeEvent();
        e.setEventType('someeventname');
        e.set('somename', 'somevalue');
        t.trackEvent(e);

        expect(beacon).not.toBeNull();
        expect(beacon.event_type).toBe('someeventname');
        expect(beacon.somename).toBe('somevalue');
    });
});
