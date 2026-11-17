import { relativeTime, startNotifications, startMenus, BELL_SELECTOR }
    from '../../modules/Base/src/reporting/owa.header.js';
import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

/**
 * The header's controls (owa.header.js): the notification bell drawn from one
 * fetch, and the account and help menus, over header.php's markup.
 */

const NOW = Date.UTC(2026, 10, 17, 12, 0, 0);

function bellMarkup() {
    document.body.innerHTML = `
        <span class="owa_notificationBell" data-ui="header:notifications"
              data-owa-notifications-read="/api/index.php?do=notifications&amp;nonce=r"
              data-owa-notifications-mark-read="/api/index.php?do=notifications&amp;nonce=p"
              data-owa-notifications-dismiss="/api/index.php?do=notifications&amp;nonce=d">
            <button type="button" id="owa_notificationToggle" aria-expanded="false"></button>
            <span id="owa_notificationBadge">0</span>
            <div id="owa_notificationPanel" hidden>
                <div id="owa_notificationItems"><p>Loading...</p></div>
            </div>
        </span>
        <p id="outside">elsewhere</p>`;
    return document.querySelector(BELL_SELECTOR);
}

function answer(body, ok = true) {
    return Promise.resolve({ ok, json: () => Promise.resolve(body) });
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
    delete global.fetch;
    delete OWA.config.strings;
    delete OWA.config.locale;
    document.body.innerHTML = '';
});

describe('when a notification was published', () => {

    test('under a minute is just now; under a week, how long ago; after, the date', () => {
        OWA.config.locale = 'en';

        expect(relativeTime(NOW / 1000 - 30, NOW)).toBe('just now');
        expect(relativeTime(NOW / 1000 - 3 * 86400, NOW)).toBe('3d ago');
        expect(relativeTime(NOW / 1000 - 30 * 86400, NOW)).toBe('Oct 18');
    });

    test('in the install\'s words', () => {
        OWA.config.strings = { 'just now': 'gerade eben' };

        expect(relativeTime(NOW / 1000, NOW)).toBe('gerade eben');
    });
});

describe('the bell', () => {

    test('draws what the one fetch returns, the badge counting the unread rows', async () => {
        global.fetch = jest.fn(() => answer({ data: { notifications: [
            { id: 1, title: 'OWA 2.0', url: 'https://example.com/r', read: false, type: 'release' },
            { id: 2, title: 'Read <b>one</b>', read: true },
        ] } }));

        startNotifications(bellMarkup());
        await flush();

        expect(global.fetch.mock.calls[0][0]).toBe('/api/index.php?do=notifications&nonce=r');

        const rows = document.querySelectorAll('.owa_notification');
        expect(rows).toHaveLength(2);
        expect(rows[0].querySelector('a').getAttribute('href')).toBe('https://example.com/r');
        expect(rows[1].textContent).toContain('Read <b>one</b>');
        expect(document.getElementById('owa_notificationBadge').textContent).toBe('1');
        expect(document.getElementById('owa_notificationBadge').hidden).toBe(false);
    });

    test('a row clicked is marked read, here and on the server, and the badge goes', async () => {
        global.fetch = jest.fn(() => answer({ data: { notifications: [{ id: 7, title: 'New', read: false }] } }));

        startNotifications(bellMarkup());
        await flush();

        document.querySelector('.owa_notification').click();

        expect(global.fetch).toHaveBeenLastCalledWith('/api/index.php?do=notifications&nonce=p&notificationId=7',
            { method: 'POST', credentials: 'same-origin' });
        expect(document.querySelector('.owa_notification').classList.contains('is-unread')).toBe(false);
        expect(document.getElementById('owa_notificationBadge').hidden).toBe(true);
    });

    test('a dismissed row goes once the server agrees, and an empty list says so', async () => {
        global.fetch = jest.fn(() => answer({ data: { notifications: [{ id: 7, title: 'New', read: false }] } }));

        startNotifications(bellMarkup());
        await flush();

        global.fetch.mockImplementation(() => answer({}));
        document.querySelector('.owa_notificationDismiss').click();
        await flush();

        expect(global.fetch).toHaveBeenLastCalledWith('/api/index.php?do=notifications&nonce=d&notificationId=7',
            { method: 'DELETE', credentials: 'same-origin' });
        expect(document.querySelector('.owa_notification')).toBeNull();
        expect(document.getElementById('owa_notificationItems').textContent).toBe('Nothing new.');
    });

    test('a failed fetch says nothing new', async () => {
        global.fetch = jest.fn(() => Promise.reject(new Error('offline')));

        startNotifications(bellMarkup());
        await flush();

        expect(document.getElementById('owa_notificationItems').textContent).toBe('Nothing new.');
    });

    test('opens on its button and closes on a click outside', async () => {
        global.fetch = jest.fn(() => answer({ data: { notifications: [] } }));

        startNotifications(bellMarkup());

        const toggle = document.getElementById('owa_notificationToggle');
        const panel = document.getElementById('owa_notificationPanel');

        toggle.click();
        expect(panel.hidden).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');

        document.getElementById('outside').click();
        expect(panel.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });
});

describe('the menus', () => {

    beforeEach(() => {
        document.body.innerHTML = `
            <span class="owa_userMenu">
                <button id="owa_userMenuToggle" aria-expanded="false"></button>
                <div id="owa_userMenuPanel" hidden><a href="#">Profile</a></div>
            </span>
            <span class="owa_helpMenu">
                <button id="owa_helpMenuToggle" aria-expanded="false"></button>
                <div id="owa_helpMenuPanel" hidden><a href="#">Documentation</a></div>
            </span>
            <p id="outside">elsewhere</p>`;
        startMenus();
    });

    test('one at a time: opening one closes the other', () => {
        document.getElementById('owa_userMenuToggle').click();
        expect(document.getElementById('owa_userMenuPanel').hidden).toBe(false);

        document.getElementById('owa_helpMenuToggle').click();
        expect(document.getElementById('owa_helpMenuPanel').hidden).toBe(false);
        expect(document.getElementById('owa_userMenuPanel').hidden).toBe(true);
        expect(document.getElementById('owa_userMenuToggle').getAttribute('aria-expanded')).toBe('false');
    });

    test('Escape closes it and returns to its button', () => {
        const toggle = document.getElementById('owa_helpMenuToggle');

        toggle.click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(document.getElementById('owa_helpMenuPanel').hidden).toBe(true);
        expect(document.activeElement).toBe(toggle);
    });

    test('a click outside closes it', () => {
        document.getElementById('owa_userMenuToggle').click();
        document.getElementById('outside').click();

        expect(document.getElementById('owa_userMenuPanel').hidden).toBe(true);
    });
});
