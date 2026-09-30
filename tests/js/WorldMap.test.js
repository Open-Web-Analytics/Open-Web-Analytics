import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';
import '../../modules/Base/src/reporting/v1/owa.worldmap.js';

/**
 * The country map: shaded by count per ISO code, cleared when a country's
 * count goes, and titled from the map's own names.
 */
const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
    + '<g data-cc="US"><title>United States of America</title><path d="M0 0L1 1Z"/></g>'
    + '<g data-cc="FR"><title>France</title><path d="M2 2L3 3Z"/></g>'
    + '<g data-cc="JP"><title>Japan</title><path d="M4 4L5 5Z"/></g>'
    + '</svg>';

async function loadedMap() {
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve(SVG) }));

    const slot = document.createElement('div');
    document.body.appendChild(slot);

    const map = new OWA.worldMap(slot, { url: 'world.svg' });
    await map.load();

    return { map, slot };
}

const g = (slot, code) => slot.querySelector(`g[data-cc="${code}"]`);

test('countries with users are shaded in proportion, the rest are not', async () => {
    const { map, slot } = await loadedMap();

    map.shade([{ code: 'US', users: 4 }, { code: 'FR', users: 1 }]);

    expect(g(slot, 'US').classList.contains('is-active')).toBe(true);
    expect(g(slot, 'US').style.fillOpacity).toBe('1');
    expect(g(slot, 'FR').style.fillOpacity).toBe('0.4375');
    expect(g(slot, 'JP').classList.contains('is-active')).toBe(false);
    expect(g(slot, 'US').querySelector('title').textContent).toBe('United States of America: 4 users');
    expect(g(slot, 'FR').querySelector('title').textContent).toBe('France: 1 user');
});

test('a country nobody is in any more goes back to the base fill', async () => {
    const { map, slot } = await loadedMap();

    map.shade([{ code: 'FR', users: 2 }]);
    map.shade([]);

    expect(g(slot, 'FR').classList.contains('is-active')).toBe(false);
    expect(g(slot, 'FR').style.fillOpacity).toBe('');
    expect(g(slot, 'FR').querySelector('title').textContent).toBe('France');
});

test('shading asked for before the map arrives is applied when it does', async () => {
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve(SVG) }));

    const slot = document.createElement('div');
    const map  = new OWA.worldMap(slot, { url: 'world.svg' });

    map.shade([{ code: 'JP', users: 1 }]);
    await map.load();

    expect(g(slot, 'JP').classList.contains('is-active')).toBe(true);
});

test('names come from the map, and a code that is not a country names nothing', async () => {
    const { map } = await loadedMap();

    expect(map.nameOf('US')).toBe('United States of America');
    expect(map.nameOf('ZZ')).toBe('');
    expect(map.nameOf('"]<x')).toBe('');
});
