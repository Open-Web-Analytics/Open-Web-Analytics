import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * removeAction() and removeFilter() take a hook off the live hook system that
 * doAction() and applyFilters() run.
 */

afterEach(() => {
    delete OWA.hooks.actions['test.hook'];
    delete OWA.hooks.filters['test.hook'];
});

test('a removed action no longer runs', () => {
    const heard = [];
    const kept = (o) => heard.push('kept:' + o.n);
    const removed = (o) => heard.push('removed:' + o.n);

    OWA.addAction('test.hook', kept);
    OWA.addAction('test.hook', removed);
    OWA.doAction('test.hook', { n: 1 });

    expect(() => OWA.removeAction('test.hook', removed)).not.toThrow();
    OWA.doAction('test.hook', { n: 2 });

    expect(heard).toEqual(['kept:1', 'removed:1', 'kept:2']);
});

test('a removed filter no longer changes the value', () => {
    const double = (v) => v * 2;
    const addOne = (v) => v + 1;

    OWA.addFilter('test.hook', double);
    OWA.addFilter('test.hook', addOne);
    expect(OWA.applyFilters('test.hook', 5)).toBe(11);

    expect(() => OWA.removeFilter('test.hook', double)).not.toThrow();
    expect(OWA.applyFilters('test.hook', 5)).toBe(6);
});

test('a callback added twice, at different priorities, is removed both times', () => {
    const heard = [];
    const cb = () => heard.push('cb');

    OWA.addAction('test.hook', cb, 5);
    OWA.addAction('test.hook', cb, 20);
    OWA.removeAction('test.hook', cb);
    OWA.doAction('test.hook', {});

    OWA.addFilter('test.hook', (v) => v + 'a', 5);
    const f = (v) => v + 'b';
    OWA.addFilter('test.hook', f, 10);
    OWA.addFilter('test.hook', f, 20);
    OWA.removeFilter('test.hook', f);

    expect(heard).toEqual([]);
    expect(OWA.applyFilters('test.hook', '')).toBe('a');
});

test('removing from a tag with nothing registered is a no-op', () => {
    expect(() => OWA.removeAction('test.hook', () => {})).not.toThrow();
    expect(() => OWA.removeFilter('test.hook', () => {})).not.toThrow();
    expect(OWA.applyFilters('test.hook', 'v')).toBe('v');
});
