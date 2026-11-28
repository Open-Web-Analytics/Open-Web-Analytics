import { OwaEvent } from '../../modules/Base/src/tracker/OwaEvent.js';

/**
 * Unit tests for the tracker Event value object. Event is the payload every
 * track* method assembles before handing it to the beacon transport, so its
 * get/set/merge/type contract underpins every event type.
 */
describe('OwaEvent', () => {

    test('new event carries a timestamp by default', () => {
        const e = new OwaEvent();
        expect(e.isSet('timestamp')).toBe(true);
        expect(typeof e.get('timestamp')).toBe('number');
    });

    test('setEventType stores under event_type', () => {
        const e = new OwaEvent();
        e.setEventType('custom_event');
        expect(e.get('event_type')).toBe('custom_event');
    });

    test('set/get round-trips a property', () => {
        const e = new OwaEvent();
        e.set('action_group', 'test group');
        expect(e.get('action_group')).toBe('test group');
    });

    test('get returns undefined for an unset property', () => {
        const e = new OwaEvent();
        expect(e.get('nope')).toBeUndefined();
    });

    test('isSet reflects presence', () => {
        const e = new OwaEvent();
        expect(e.isSet('action_name')).toBeFalsy();
        e.set('action_name', 'test action');
        expect(e.isSet('action_name')).toBe(true);
    });

    test('merge copies own properties onto the event', () => {
        const e = new OwaEvent();
        e.merge({ a: 1, b: 'two' });
        expect(e.get('a')).toBe(1);
        expect(e.get('b')).toBe('two');
    });

    test('getProperties exposes the backing map', () => {
        const e = new OwaEvent();
        e.set('numeric_value', 10);
        expect(e.getProperties().numeric_value).toBe(10);
    });

    test('set ignores undefined and keeps a value already set', () => {
        const e = new OwaEvent();
        e.set('ct_order_id', undefined);
        expect(e.isSet('ct_order_id')).toBeFalsy();

        e.set('ct_order_id', 'o1');
        e.set('ct_order_id', undefined);
        expect(e.get('ct_order_id')).toBe('o1');
    });

    test('set keeps null, 0, false and the empty string', () => {
        const e = new OwaEvent();
        e.set('a', null);
        e.set('b', 0);
        e.set('c', false);
        e.set('d', '');
        expect(e.getProperties()).toMatchObject({ a: null, b: 0, c: false, d: '' });
    });

    test('unset removes a property', () => {
        const e = new OwaEvent();
        e.set('engagement_msec', 1200);
        e.unset('engagement_msec');
        expect(e.isSet('engagement_msec')).toBeFalsy();
    });
});
