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
     * trackAction sends the action's OWN NAME as the event, with the rest as
     * parameters.
     *
     * It used to send event_type 'custom_event' and put the name in an
     * action_name field: v1's one-event-type-for-everything shape, told apart by
     * a property. v2 retires it -- an event name is a name, and the group, label
     * and value describe it, which is why eventName is a dimension.
     */
    test('trackAction sends the action name as the event, with ep_ parameters', () => {
        tracker.trackAction('signup', 'newsletter_opt_in', 'footer form', 10);

        expect(captured).toHaveLength(1);
        const e = captured[0];
        expect(e.get('event_type')).toBe('newsletter_opt_in');
        expect(e.get('ep_action_group')).toBe('signup');
        expect(e.get('ep_action_label')).toBe('footer form');
        expect(e.get('epn_numeric_value')).toBe(10);

        // The old spellings are gone, not merely unread.
        expect(e.get('action_name')).toBeUndefined();
        expect(e.get('action_group')).toBeUndefined();
        expect(e.get('numeric_value')).toBeUndefined();
    });

    /**
     * AND AN ACTION NAME THAT IS NOT A LEGAL EVENT NAME IS REFUSED.
     *
     * The migration cost, stated as a test. v1 action names were free text, so
     * 'test action' was ordinary; an event name may not contain a space in v2,
     * so a site passing one now sends nothing and gets a
     * debug line saying why.
     *
     * Refused rather than reshaped on purpose: silently turning 'test action' into
     * 'test_action' would invent a name the site never chose and split its history
     * across two of them.
     */
    test('trackAction refuses an action name that is not a legal event name', () => {
        tracker.trackAction('group', 'test action', 'label', 1);
        tracker.trackAction('group', '9_starts_numeric', 'label', 1);
        tracker.trackAction('group', 'page_view', 'label', 1);

        expect(captured).toHaveLength(0);
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
