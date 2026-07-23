import { OWATracker } from '../../modules/base/src/tracker/Tracker.js';

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
        // dom_element_tag: an early lowercased set() is overwritten by the
        // getDomElementProperties() merge, so the raw uppercase tagName wins.
        expect(e.get('dom_element_tag')).toBe('A');
        expect(e.get('dom_element_id')).toBe('buy-now');
        expect(e.get('dom_element_name')).toBe('buy');
        expect(e.get('dom_element_class')).toBe('btn');
        // Coordinates are captured as strings.
        expect(e.get('click_x')).toBe('12');
        expect(e.get('click_y')).toBe('34');

        document.body.removeChild(link);
    });
});
