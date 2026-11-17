import { OWA } from '../../modules/Base/src/reporting/owa.format.js';
import { tabs } from '../../modules/Base/src/reporting/owa.tabs.js';

/**
 * The tab control (owa.tabs.js): the ARIA tabs pattern over panels already on
 * the page, one shown at a time.
 */

const ITEMS = [
    { id: 'tab_site_usage', label: 'Site Usage' },
    { id: 'tab_ecommerce', label: 'e-commerce' },
    { id: 'tab_third', label: 'Third' },
];

function page() {
    document.body.innerHTML = '<div id="report-tabs">'
        + ITEMS.map((i) => `<div id="${i.id}" class="owa_reportGrid">${i.label} widgets</div>`).join('')
        + '</div>';
    return document.getElementById('report-tabs');
}

const tabList = () => document.querySelector('[role="tablist"]');
const tabEls = () => [...document.querySelectorAll('[role="tab"]')];
const panel = (id) => document.getElementById(id);
const key = (el, k) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true }));

afterEach(() => {
    document.body.innerHTML = '';
    window.location.hash = '';
});

test('draws a tab list first in the container, a tab per panel, each naming its panel', () => {
    const container = page();

    tabs(container, ITEMS, () => {}, { label: 'Metrics' });

    expect(container.firstElementChild).toBe(tabList());
    expect(tabList().getAttribute('aria-label')).toBe('Metrics');
    expect(tabEls().map((t) => t.textContent)).toEqual(['Site Usage', 'e-commerce', 'Third']);

    for (const [i, tab] of tabEls().entries()) {
        expect(tab.tagName).toBe('BUTTON');
        expect(tab.type).toBe('button');
        expect(tab.getAttribute('aria-controls')).toBe(ITEMS[i].id);

        const p = panel(ITEMS[i].id);
        expect(p.getAttribute('role')).toBe('tabpanel');
        expect(p.getAttribute('aria-labelledby')).toBe(tab.id);
    }
});

test('the first is selected, its panel alone shown, and the only tab in the tab order', () => {
    const selected = jest.fn();

    tabs(page(), ITEMS, selected);

    expect(tabEls().map((t) => t.getAttribute('aria-selected'))).toEqual(['true', 'false', 'false']);
    expect(tabEls().map((t) => t.tabIndex)).toEqual([0, -1, -1]);
    expect(ITEMS.map((i) => panel(i.id).hidden)).toEqual([false, true, true]);
    expect(selected.mock.calls).toEqual([['tab_site_usage']]);
});

test('a click selects a tab and shows its panel', () => {
    const selected = jest.fn();
    const control = tabs(page(), ITEMS, selected);

    tabEls()[1].click();

    expect(control.selected()).toBe('tab_ecommerce');
    expect(tabEls().map((t) => t.getAttribute('aria-selected'))).toEqual(['false', 'true', 'false']);
    expect(ITEMS.map((i) => panel(i.id).hidden)).toEqual([true, false, true]);
    expect(selected.mock.calls).toEqual([['tab_site_usage'], ['tab_ecommerce']]);
});

test('selecting the selected tab again does nothing', () => {
    const selected = jest.fn();

    tabs(page(), ITEMS, selected);
    tabEls()[0].click();

    expect(selected).toHaveBeenCalledTimes(1);
});

test('Right and Left select the tab beside, wrapping at the ends, and move focus to it', () => {
    tabs(page(), ITEMS, () => {});

    tabEls()[0].focus();

    key(tabEls()[0], 'ArrowRight');
    expect(document.activeElement).toBe(tabEls()[1]);
    expect(tabEls()[1].getAttribute('aria-selected')).toBe('true');

    key(tabEls()[1], 'ArrowRight');
    key(tabEls()[2], 'ArrowRight');
    expect(document.activeElement).toBe(tabEls()[0]);
    expect(panel('tab_site_usage').hidden).toBe(false);

    key(tabEls()[0], 'ArrowLeft');
    expect(document.activeElement).toBe(tabEls()[2]);
    expect(panel('tab_third').hidden).toBe(false);
});

test('Home and End go to the first and last', () => {
    tabs(page(), ITEMS, () => {});

    key(tabEls()[0], 'End');
    expect(document.activeElement).toBe(tabEls()[2]);

    key(tabEls()[2], 'Home');
    expect(document.activeElement).toBe(tabEls()[0]);
});

test('other keys are left alone', () => {
    tabs(page(), ITEMS, () => {});

    const e = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    tabEls()[0].dispatchEvent(e);

    expect(e.defaultPrevented).toBe(false);
    expect(tabEls()[0].getAttribute('aria-selected')).toBe('true');
});

test('a URL fragment naming a panel selects its tab first', () => {
    window.location.hash = '#tab_ecommerce';
    const selected = jest.fn();

    tabs(page(), ITEMS, selected);

    expect(selected.mock.calls).toEqual([['tab_ecommerce']]);
    expect(panel('tab_site_usage').hidden).toBe(true);
});

test('a label is text, not markup', () => {
    document.body.innerHTML = '<div id="c"><div id="p1"></div></div>';

    tabs(document.getElementById('c'), [{ id: 'p1', label: '<img src=x onerror=alert(1)>' }], () => {});

    expect(tabEls()[0].textContent).toBe('<img src=x onerror=alert(1)>');
    expect(document.querySelector('img')).toBeNull();
});

test('is on the OWA namespace', () => {
    expect(OWA.tabs).toBe(tabs);
});
