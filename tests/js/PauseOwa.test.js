/**
 * pause-owa holds every tracker the queue made and their default features,
 * under both tag types; unpause-owa restarts them.
 *
 * The bundle tag queues its options (which create the tracker) ahead of the
 * page's commands and its trackPageView after them (TrackerBundle::preamble():
 * options, then the page's queue, then features). The manual tag carries the
 * page's own commands only.
 */

global.__webpack_public_path__ = '';

const BASE_URL = 'https://owa.example.test/';

let sent = [];
let restore = [];

function spyOnTransports() {
    sent = [];

    const OrigImage = global.Image;
    global.Image = class {
        set src(v) { sent.push(String(v)); }
    };

    const origFetch = global.fetch;
    global.fetch = (url, init) => {
        sent.push(String(url) + '?' + String((init && init.body) || ''));
        return Promise.resolve({ ok: true, json: () => ({}) });
    };

    const origBeacon = Object.getOwnPropertyDescriptor(navigator, 'sendBeacon');
    Object.defineProperty(navigator, 'sendBeacon', {
        configurable: true,
        value: (url, body) => { sent.push(String(url) + '?' + String(body || '')); return true; },
    });

    restore = [
        () => { global.Image = OrigImage; },
        () => { global.fetch = origFetch; },
        () => {
            if (origBeacon) { Object.defineProperty(navigator, 'sendBeacon', origBeacon); } else { delete navigator.sendBeacon; }
        },
    ];
}

// A tracker from an earlier test keeps its window listeners, so each test
// uses its own site id and counts only that site's events.
let site = 'pause-site';
let siteNumber = 0;

function eventsSent(type) {
    return sent.filter((s) => new RegExp('[?&]e_t=' + type + '(&|$)').test(s)
        && new RegExp('[?&]site=' + site + '(&|$)').test(s));
}

const FEATURES = ['trackClicks'];

/** The bundle tag's queue: its options, the page's commands, then its hit. */
function bundleQueue(page) {
    const options = [
        ['setSiteId', site],
        ['setOption', 'features', FEATURES],
    ];

    return options.concat(page, [['trackPageView']]);
}

/** The manual tag's queue: the page's own commands. */
function manualQueue(page) {
    return page.concat([
        ['setSiteId', site],
        ['setOption', 'features', FEATURES],
        ['trackPageView'],
    ]);
}

function load(cmds) {
    global.owa_cmds = cmds;
    window.owa_cmds = cmds;
    require('../../modules/Base/src/tracker/tracker-dom.js');
}

function clickButton() {
    document.getElementById('pause-button').dispatchEvent(new MouseEvent('click', { bubbles: true }));
}

beforeEach(() => {
    siteNumber += 1;
    site = 'pause-site-' + siteNumber;
    window.owa_baseUrl = BASE_URL;
    Object.defineProperty(document, 'domain', { configurable: true, get() { return 'pause.example'; } });
    document.body.innerHTML = '<button id="pause-button">Go</button>';
    spyOnTransports();
});

afterEach(() => {
    restore.forEach((fn) => fn());
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'; }
    });
    document.body.innerHTML = '';
    delete window.owa_baseUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    jest.resetModules();
});

describe.each([
    ['bundle', bundleQueue],
    ['manual', manualQueue],
])('%s tag with pause-owa queued', (_type, queue) => {

    test('no default feature starts and nothing is sent', () => {
        load(queue([['pause-owa']]));

        clickButton();

        expect(window.owa_cmds.is_paused).toBe(true);
        expect(sent.filter((s) => s.indexOf('site=' + site + '&') !== -1)).toHaveLength(0);
        if (window.OWATracker) {
            expect(window.OWATracker.featuresStarted).toBeFalsy();
            expect(window.OWATracker.active).toBe(false);
        }
    });

    test('unpause-owa starts the features and the tracker sends again', () => {
        load(queue([['pause-owa']]));

        window.owa_cmds.push(['unpause-owa']);
        window.owa_cmds.push(['setSiteId', site]);
        window.owa_cmds.push(['trackPageView']);

        expect(window.OWATracker.active).toBe(true);
        expect(window.OWATracker.featuresStarted).toBe(true);
        expect(eventsSent('page_view')).toHaveLength(1);

        clickButton();

        expect(eventsSent('click')).toHaveLength(1);
    });
});

describe.each([
    ['bundle', bundleQueue],
    ['manual', manualQueue],
])('%s tag paused after load', (_type, queue) => {

    test('a click after pause-owa sends nothing; unpause-owa sends the next', () => {
        load(queue([]));

        expect(eventsSent('page_view')).toHaveLength(1);

        clickButton();
        expect(eventsSent('click')).toHaveLength(1);

        window.owa_cmds.push(['pause-owa']);
        clickButton();

        expect(window.OWATracker.active).toBe(false);
        expect(eventsSent('click')).toHaveLength(1);

        window.owa_cmds.push(['unpause-owa']);
        clickButton();

        expect(eventsSent('click')).toHaveLength(2);
    });
});

test('pause-owa pauses a named tracker the queue made', () => {
    load([
        ['setSiteId', site],
        ['OWATracker2.setSiteId', 'second-site'],
        ['pause-owa'],
    ]);

    expect(window.OWATracker.active).toBe(false);
    expect(window.OWATracker2.active).toBe(false);

    window.owa_cmds.push(['unpause-owa']);

    expect(window.OWATracker.active).toBe(true);
    expect(window.OWATracker2.active).toBe(true);

    delete window.OWATracker2;
});

test('unpause-owa without a pause does not restart a tracker paused by pause()', () => {
    load([['setSiteId', site], ['pause']]);

    window.owa_cmds.push(['unpause-owa']);

    expect(window.OWATracker.active).toBe(false);
});
