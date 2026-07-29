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

    test('trackAction assembles a track.action event with all fields', () => {
        tracker.trackAction('test group', 'test action', 'this is just a test', 10);

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('track.action');
        expect(e.get('action_group')).toBe('test group');
        expect(e.get('action_name')).toBe('test action');
        expect(e.get('action_label')).toBe('this is just a test');
        expect(e.get('numeric_value')).toBe(10);
    });

    test('trackPageView assembles a base.page_request event', () => {
        tracker.trackPageView('https://example.com/page');

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('base.page_request');
        expect(e.get('page_url')).toBe('https://example.com/page');
    });

    test('trackPageView without a url still sets the event_type', () => {
        tracker.trackPageView();

        expect(captured).toHaveLength(1);
        expect(captured[0].get('event_type')).toBe('base.page_request');
    });

    test('trackTransaction assembles an ecommerce.transaction event with line items', () => {
        tracker.addTransaction('order-1', 'web', 42.5, 2.5, 5, 'stripe', 'NYC', 'NY', 'US');
        tracker.addTransactionLineItem('order-1', 'SKU-1', 'Widget', 'widgets', 20, 2);
        tracker.trackTransaction();

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('ecommerce.transaction');
        expect(e.get('ct_order_id')).toBe('order-1');
        expect(e.get('ct_order_source')).toBe('web');
        expect(e.get('ct_total')).toBe(42.5);
        expect(e.get('ct_gateway')).toBe('stripe');

        const items = e.get('ct_line_items');
        expect(items).toHaveLength(1);
        expect(items[0].li_sku).toBe('SKU-1');
        expect(items[0].li_product_name).toBe('Widget');
        expect(items[0].li_quantity).toBe(2);
    });

    test('trackTransaction without a set-up transaction sends nothing', () => {
        tracker.trackTransaction();
        expect(captured).toHaveLength(0);
    });

    test('clickEventHandler assembles a dom.click event from a DOM target', () => {
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
        expect(e.get('event_type')).toBe('dom.click');
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
