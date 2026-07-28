import { Event } from '../../modules/base/src/tracker/Event.js';

/**
 * Unit tests for the tracker Event value object. Event is the payload every
 * track* method assembles before handing it to the beacon transport, so its
 * get/set/merge/type contract underpins every event type.
 */
describe('Event', () => {

    test('new event carries a timestamp by default', () => {
        const e = new Event();
        expect(e.isSet('timestamp')).toBe(true);
        expect(typeof e.get('timestamp')).toBe('number');
    });

    test('setEventType stores under event_type', () => {
        const e = new Event();
        e.setEventType('track.action');
        expect(e.get('event_type')).toBe('track.action');
    });

    test('set/get round-trips a property', () => {
        const e = new Event();
        e.set('action_group', 'test group');
        expect(e.get('action_group')).toBe('test group');
    });

    test('get returns undefined for an unset property', () => {
        const e = new Event();
        expect(e.get('nope')).toBeUndefined();
    });

    test('isSet reflects presence', () => {
        const e = new Event();
        expect(e.isSet('action_name')).toBeFalsy();
        e.set('action_name', 'test action');
        expect(e.isSet('action_name')).toBe(true);
    });

    test('merge copies own properties onto the event', () => {
        const e = new Event();
        e.merge({ a: 1, b: 'two' });
        expect(e.get('a')).toBe(1);
        expect(e.get('b')).toBe('two');
    });

    test('getProperties exposes the backing map', () => {
        const e = new Event();
        e.set('numeric_value', 10);
        expect(e.getProperties().numeric_value).toBe(10);
    });
});
