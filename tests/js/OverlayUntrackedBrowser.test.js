/**
 * An overlay link opened in a browser that is not tracked: one that sends Do
 * Not Track or Global Privacy Control, or is under automation.
 *
 * The overlay starts, and the page sends no tracking event: the bootstrap runs
 * the queue with OWA's sendsNothing setting on (tracker-dom.js), and every
 * tracker it makes sends nothing, restart() or not.
 */

global.__webpack_public_path__ = '';

const BASE_URL = 'https://owa.example.test/';
const FLAGS = ['doNotTrack', 'msDoNotTrack', 'globalPrivacyControl', 'webdriver'];

let saved = {};
let sent = [];
let restore = [];

function setFlag(prop, value) {
    Object.defineProperty(navigator, prop, { configurable: true, get() { return value; } });
}

/** Every transport the tracker can send by: fetch, sendBeacon and the pixel. */
function spyOnTransports() {
    sent = [];

    const OrigImage = global.Image;
    global.Image = class {
        set src(v) { sent.push(['image', v]); }
    };

    const origFetch = global.fetch;
    global.fetch = (url) => { sent.push(['fetch', String(url)]); return Promise.resolve({ ok: true, json: () => ({}) }); };

    const origBeacon = Object.getOwnPropertyDescriptor(navigator, 'sendBeacon');
    Object.defineProperty(navigator, 'sendBeacon', {
        configurable: true,
        value: (url) => { sent.push(['beacon', String(url)]); return true; },
    });

    restore = [
        () => { global.Image = OrigImage; },
        () => { global.fetch = origFetch; },
        () => {
            if (origBeacon) { Object.defineProperty(navigator, 'sendBeacon', origBeacon); } else { delete navigator.sendBeacon; }
        },
    ];
}

/** Open the page on an overlay link whose data comes from this OWA server. */
function openOverlayLink(apiUrl = BASE_URL + 'api/index.php?owa_do=reports') {
    const { Util } = require('../../modules/Base/src/common/Util.js');
    const params = encodeURIComponent(Util.base64_encode(JSON.stringify({ action: 'loadTestOverlay', api_url: apiUrl })));

    window.history.replaceState(null, '', `/page#owa_overlay.${params}`);
}

/** Load the tracker on a page that queued `cmds`; returns OWA and what the overlay mode was given. */
function loadWith(cmds) {
    const { OWA_instance: OWA } = require('../../modules/Base/src/common/owa.js');
    const started = [];

    OWA.registerOverlayMode('loadTestOverlay', (p) => started.push(p));

    global.owa_cmds = cmds;
    window.owa_cmds = cmds;
    require('../../modules/Base/src/tracker/tracker-dom.js');

    return { OWA, started };
}

const PAGE = [
    ['setSiteId', 'overlay-site'],
    ['setCustomVar', 1, 'plan', 'pro', 'visitor'],
    ['trackPageView'],
    ['trackAction', 'group', 'action'],
];

beforeEach(() => {
    saved = {};
    FLAGS.forEach((f) => { saved[f] = Object.getOwnPropertyDescriptor(navigator, f); });
    FLAGS.forEach((f) => setFlag(f, f === 'webdriver' ? false : null));
    window.owa_baseUrl = BASE_URL;
    spyOnTransports();
});

afterEach(() => {
    restore.forEach((fn) => fn());
    FLAGS.forEach((f) => {
        if (saved[f]) { Object.defineProperty(navigator, f, saved[f]); } else { delete navigator[f]; }
    });
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'; }
    });
    window.history.replaceState(null, '', '/');
    delete window.owa_baseUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    jest.resetModules();
});

describe.each([
    ['Do Not Track', 'doNotTrack', '1'],
    ['Global Privacy Control', 'globalPrivacyControl', true],
    ['automation', 'webdriver', true],
])('a browser sending %s, opened on an overlay link', (_label, flag, value) => {

    test('starts the overlay', () => {
        setFlag(flag, value);
        openOverlayLink();

        const { OWA, started } = loadWith(PAGE.slice());

        expect(started).toHaveLength(1);
        expect(started[0].action).toBe('loadTestOverlay');
        expect(OWA.overlayActive).toBe(true);
    });

    test('sends no tracking event', () => {
        setFlag(flag, value);
        openOverlayLink();

        loadWith(PAGE.slice());
        window.owa_cmds.push(['trackPageView']);
        window.OWATracker.trackEvent(window.OWATracker.makeEvent());

        expect(sent).toHaveLength(0);
    });
});

test('restart() does not make an untracked browser send', () => {
    setFlag('globalPrivacyControl', true);
    openOverlayLink();

    loadWith([['setSiteId', 'overlay-site'], ['restart'], ['trackPageView']]);
    window.OWATracker.restart();
    window.OWATracker.trackPageView();

    expect(window.OWATracker.active).toBe(true);
    expect(sent).toHaveLength(0);
});

test('a privacy signal still leaves no tracker cookie', () => {
    setFlag('doNotTrack', '1');
    document.cookie = 'owa_v=' + encodeURIComponent('{"vid":"123"}') + '; path=/';
    openOverlayLink();

    loadWith(PAGE.slice());

    expect(document.cookie).not.toMatch(/(^|;\s*)owa_(v|s(_[^=]*)?|c|b)=/);
});

test('an overlay link to another server starts nothing, and nothing is sent', () => {
    setFlag('globalPrivacyControl', true);
    openOverlayLink('https://elsewhere.example/api/');

    const { OWA, started } = loadWith(PAGE.slice());

    expect(started).toHaveLength(0);
    expect(OWA.overlayActive).toBe(false);
    expect(sent).toHaveLength(0);
});

test('with no overlay link, a privacy signal still runs nothing', () => {
    setFlag('globalPrivacyControl', true);

    const cmds = PAGE.slice();
    loadWith(cmds);

    expect(window.owa_cmds).toBe(cmds);
    expect(window.OWATracker).toBeUndefined();
    expect(sent).toHaveLength(0);
});

test('a tracked browser is tracked as before', () => {
    loadWith(PAGE.slice());

    expect(sent.length).toBeGreaterThan(0);
});
