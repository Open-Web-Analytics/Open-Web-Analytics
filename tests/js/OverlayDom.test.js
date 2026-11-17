import { OverlayDom } from '../../modules/Base/src/tracker/OverlayDom.js';

/**
 * The overlays' DOM helpers: they run on the tracked page, with no library.
 */

afterEach(() => {
    document.body.innerHTML = '';
    jest.useRealTimers();
});

describe('element()', () => {

    test('sets the attributes given and leaves out null, undefined and false', () => {
        const el = OverlayDom.element('div', { id: 'a', role: 'button', hidden: true, title: null, lang: undefined, dir: false });

        expect(el.id).toBe('a');
        expect(el.getAttribute('role')).toBe('button');
        expect(el.hidden).toBe(true);
        expect(el.hasAttribute('title')).toBe(false);
        expect(el.hasAttribute('lang')).toBe(false);
        expect(el.hasAttribute('dir')).toBe(false);
    });

    test('a string child is text, never markup', () => {
        const el = OverlayDom.element('div', {}, ['<img src=x onerror=alert(1)>', OverlayDom.element('span')]);

        expect(el.querySelector('img')).toBeNull();
        expect(el.firstChild.nodeType).toBe(Node.TEXT_NODE);
        expect(el.textContent).toBe('<img src=x onerror=alert(1)>');
        expect(el.lastChild.tagName).toBe('SPAN');
    });
});

describe('onActivate()', () => {

    test('runs on a click, Enter and Space, and on no other key', () => {
        const el = OverlayDom.control('c', 'Go');
        const fn = jest.fn();
        document.body.appendChild(el);
        OverlayDom.onActivate(el, fn);

        el.click();
        el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        el.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        el.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
        el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));

        expect(fn).toHaveBeenCalledTimes(3);
    });

    test('Space does not scroll the page', () => {
        const el = OverlayDom.control('c', 'Go');
        OverlayDom.onActivate(el, () => {});

        const e = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
        el.dispatchEvent(e);

        expect(e.defaultPrevented).toBe(true);
    });
});

describe('control()', () => {

    test('is a focusable button by role, named by its label', () => {
        const el = OverlayDom.control('owa_player_start', 'Play', { 'aria-expanded': 'false' });

        expect(el.tagName).toBe('DIV');
        expect(el.getAttribute('role')).toBe('button');
        expect(el.getAttribute('tabindex')).toBe('0');
        expect(el.textContent).toBe('Play');
        expect(el.getAttribute('aria-expanded')).toBe('false');
        expect(el.classList.contains('owa_overlay_control')).toBe(true);
    });
});

describe('getJson()', () => {

    afterEach(() => {
        delete global.fetch;
    });

    test('a page with no fetch() drops the request rather than throwing', async () => {
        delete global.fetch;
        const onData = jest.fn();

        await expect(OverlayDom.getJson('https://owa.example.test/api/', onData)).resolves.toBeUndefined();
        expect(onData).not.toHaveBeenCalled();
    });

    test('a network failure is dropped', async () => {
        global.fetch = jest.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        const onData = jest.fn();

        await OverlayDom.getJson('https://owa.example.test/api/', onData);

        expect(onData).not.toHaveBeenCalled();
    });
});

describe('the notice', () => {

    const region = () => document.querySelector('[data-ui="overlay:notices"]');
    const notices = () => document.querySelectorAll('[data-ui="overlay:notice"]');

    test('is announced: its region is a status region in the page before anything is shown', () => {
        new OverlayDom.OverlayNotice();

        expect(region()).not.toBeNull();
        expect(region().getAttribute('role')).toBe('status');
        expect(notices()).toHaveLength(0);
    });

    test('shows its header and message as text', () => {
        const n = new OverlayDom.OverlayNotice();

        n.show('Clicked:', '<b>#buy</b>');

        expect(notices()).toHaveLength(1);
        expect(region().querySelector('b')).toBeNull();
        expect(notices()[0].textContent).toContain('Clicked:');
        expect(notices()[0].textContent).toContain('<b>#buy</b>');
    });

    test('shows one at a time: a new one replaces the last', () => {
        const n = new OverlayDom.OverlayNotice();

        n.show('Clicked:', 'first');
        n.show('Clicked:', 'second');

        expect(notices()).toHaveLength(1);
        expect(notices()[0].textContent).toContain('second');
    });

    test('is dismissed from its dismiss control, by click or keyboard', () => {
        const n = new OverlayDom.OverlayNotice();

        n.show('Clicked:', 'a');
        notices()[0].querySelector('[role="button"][aria-label="Dismiss"]').click();
        expect(notices()).toHaveLength(0);

        n.show('Clicked:', 'b');
        notices()[0].querySelector('[aria-label="Dismiss"]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        expect(notices()).toHaveLength(0);
    });

    test('goes on its own after its life, and a replacement gets a full life', () => {
        jest.useFakeTimers();
        const n = new OverlayDom.OverlayNotice(1000);

        n.show('Clicked:', 'a');
        jest.advanceTimersByTime(900);
        n.show('Clicked:', 'b');
        jest.advanceTimersByTime(900);
        expect(notices()).toHaveLength(1);

        jest.advanceTimersByTime(100);
        expect(notices()).toHaveLength(0);
        expect(region()).not.toBeNull();
    });
});
