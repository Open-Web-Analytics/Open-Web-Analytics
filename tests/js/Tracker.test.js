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
});
