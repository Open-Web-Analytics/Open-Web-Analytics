/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://www.site.example/page"}
 */

import { Util as OwaUtil } from '../../modules/Base/src/common/Util.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * A browser whose privacy signal is honoured keeps none of the tracker's
 * cookies: the bootstrap erases the visitor store, every per-site session
 * store and the legacy stores, on whatever domain a tracker could have set
 * them. A browser refused only for being automated keeps them, and so do
 * cookies the tracker did not set.
 */

global.__webpack_public_path__ = '';

const FLAGS = ['doNotTrack', 'msDoNotTrack', 'globalPrivacyControl', 'webdriver'];

let saved = {};

function setFlag(prop, value) {
    Object.defineProperty(navigator, prop, { configurable: true, get() { return value; } });
}

/** Names of the cookies the page can see now. */
function cookieNames() {
    return document.cookie.split(';').map((c) => c.trim().split('=')[0]).filter(Boolean).sort();
}

/** The tracker's cookies as an earlier visit could have left them, beside two that are not its. */
function plantCookies() {
    document.cookie = 'owa_v=%7B%22vid%22%3A%221%22%7D; path=/';
    document.cookie = 'owa_s_privacy-site=%7B%22sid%22%3A%222%22%7D; path=/; domain=.site.example';
    document.cookie = 'owa_s_other-site=%7B%22sid%22%3A%223%22%7D; path=/; domain=.www.site.example';
    document.cookie = 'owa_b=x; path=/; domain=.site.example';
    document.cookie = 'owa_c=x; path=/';
    document.cookie = 'owa_last_property=7; path=/';
    document.cookie = 'site_pref=dark; path=/';
}

const PLANTED = ['owa_b', 'owa_c', 'owa_last_property', 'owa_s_other-site', 'owa_s_privacy-site', 'owa_v', 'site_pref'];
const NOT_THE_TRACKERS = ['owa_last_property', 'site_pref'];

function load(cmds) {
    global.owa_cmds = cmds;
    window.owa_cmds = cmds;
    require('../../modules/Base/src/tracker/tracker-dom.js');
}

const PAGE = [
    ['setSiteId', 'privacy-site'],
    ['setOption', 'logger_endpoint', 'https://owa.example.test/log.php'],
];

beforeEach(() => {
    saved = {};
    FLAGS.forEach((f) => { saved[f] = Object.getOwnPropertyDescriptor(navigator, f); });
    FLAGS.forEach((f) => setFlag(f, f === 'webdriver' ? false : null));
    window.owa_baseUrl = 'https://owa.example.test/';
    plantCookies();
});

afterEach(() => {
    FLAGS.forEach((f) => {
        if (saved[f]) { Object.defineProperty(navigator, f, saved[f]); } else { delete navigator[f]; }
    });
    cookieNames().forEach((name) => {
        ['', '; domain=.site.example', '; domain=.www.site.example'].forEach((d) => {
            document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT' + d;
        });
    });
    delete window.owa_baseUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    jest.resetModules();
});

test('the cookies are planted on the domains a tracker uses', () => {
    expect(cookieNames()).toEqual(PLANTED);
});

describe('a privacy signal is honoured', () => {

    test.each([
        ['Global Privacy Control', 'globalPrivacyControl', true],
        ['Do Not Track', 'doNotTrack', '1'],
        ['msDoNotTrack', 'msDoNotTrack', '1'],
    ])('%s: the tracker\'s cookies are erased, on every domain, and nothing else', (_label, flag, value) => {
        setFlag(flag, value);

        load(PAGE.slice());

        expect(window.OWATracker).toBeUndefined();
        expect(cookieNames()).toEqual(NOT_THE_TRACKERS);
    });
});

describe('nothing is erased when no privacy signal is the reason', () => {

    test('an automated browser refused as a crawler keeps its cookies', () => {
        setFlag('webdriver', true);
        const log = jest.spyOn(console, 'log').mockImplementation(() => {});

        try {
            load(PAGE.slice());
        } finally {
            log.mockRestore();
        }

        expect(window.OWATracker).toBeUndefined();
        expect(cookieNames()).toEqual(PLANTED);
    });

    test('a site that turns the signals off is tracked, and its visitor cookie stays', () => {
        setFlag('globalPrivacyControl', true);

        load([['setOption', 'honorPrivacySignals', false], ...PAGE]);

        expect(cookieNames()).toContain('owa_v');
        expect(cookieNames()).toContain('site_pref');
    });
});

describe('Util.eraseTrackerCookies()', () => {

    test('takes the ns prefix from the configuration', () => {
        document.cookie = 'xy_v=1; path=/';

        const before = OWA.getSetting('ns');
        OWA.setSetting('ns', 'xy_');

        try {
            OwaUtil.eraseTrackerCookies();
        } finally {
            OWA.setSetting('ns', before);
        }

        expect(cookieNames()).not.toContain('xy_v');
        expect(cookieNames()).toContain('owa_v');
    });
});
