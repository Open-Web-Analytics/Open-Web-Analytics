import { Util as OwaUtil } from '../../modules/Base/src/common/Util.js';

/**
 * Privacy signals: Do Not Track and Global Privacy Control, and the
 * honorPrivacySignals option that turns both off.
 *
 * The option is a tracker option like any other, queued with setOption. The
 * bootstrap reads it from the loaded queue with CommandQueue.optionFor() before
 * process() runs a command, so a site can turn the check off from the snippet
 * or the published bundle without a page global.
 *
 * DoNotTrack.test.js pins the default behaviour for DNT; this file adds GPC and
 * the option.
 */

global.__webpack_public_path__ = '';

const BASE_URL = 'https://owa.example.test/';
const FLAGS = ['doNotTrack', 'msDoNotTrack', 'globalPrivacyControl', 'webdriver'];

let saved = {};

function setFlag(prop, value) {
    Object.defineProperty(navigator, prop, { configurable: true, get() { return value; } });
}

function installImageSpy() {
    const sent = [];
    const Orig = global.Image;
    global.Image = class {
        constructor() {}
        set src(v) { sent.push(v); }
        get src() { return this._src; }
    };
    return { sent, restore: () => { global.Image = Orig; } };
}

/** Load the tracker on a page that queued `cmds`, and return the beacons it sent. */
function loadWith(cmds) {
    const spy = installImageSpy();
    try {
        global.owa_cmds = cmds;
        window.owa_cmds = cmds;
        require('../../modules/Base/src/tracker/tracker-dom.js');
        return spy.sent;
    } finally {
        spy.restore();
    }
}

const PAGE = [
    ['setSiteId', 'privacy-site'],
    ['trackPageView', 'https://site.example/page'],
];

beforeEach(() => {
    saved = {};
    FLAGS.forEach((f) => { saved[f] = Object.getOwnPropertyDescriptor(navigator, f); });
    FLAGS.forEach((f) => setFlag(f, f === 'webdriver' ? false : null));
    window.owa_baseUrl = BASE_URL;
    Object.defineProperty(document, 'domain', { configurable: true, get() { return 'site.example'; } });
});

afterEach(() => {
    FLAGS.forEach((f) => {
        if (saved[f]) { Object.defineProperty(navigator, f, saved[f]); } else { delete navigator[f]; }
    });
    delete window.owa_baseUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    jest.resetModules();
});

describe('Util.sendsPrivacySignal()', () => {

    test('Global Privacy Control is a signal', () => {
        setFlag('globalPrivacyControl', true);
        expect(OwaUtil.sendsPrivacySignal()).toBe(true);
    });

    test('Do Not Track is a signal', () => {
        setFlag('doNotTrack', '1');
        expect(OwaUtil.sendsPrivacySignal()).toBe(true);
    });

    test('GPC false and DNT unset are not', () => {
        setFlag('globalPrivacyControl', false);
        expect(OwaUtil.sendsPrivacySignal()).toBe(false);
    });
});

describe('Util.isBrowserTrackable(honorPrivacySignals)', () => {

    test('honoured by default', () => {
        setFlag('globalPrivacyControl', true);
        expect(OwaUtil.isBrowserTrackable()).toBe(false);
    });

    test('false ignores both signals', () => {
        setFlag('doNotTrack', '1');
        setFlag('globalPrivacyControl', true);
        expect(OwaUtil.isBrowserTrackable(false)).toBe(true);
    });

    test('false does not re-admit automation', () => {
        setFlag('webdriver', true);
        expect(OwaUtil.isBrowserTrackable(false)).toBe(false);
    });
});

describe('the bootstrap reads honorPrivacySignals from the queue', () => {

    test('GPC on: nothing runs and nothing is sent', () => {
        setFlag('globalPrivacyControl', true);

        const cmds = PAGE.slice();
        const sent = loadWith(cmds);

        expect(sent).toHaveLength(0);
        expect(window.owa_cmds).toBe(cmds);
        expect(window.OWATracker).toBeUndefined();
    });

    test.each([
        ['Do Not Track', 'doNotTrack', '1'],
        ['Global Privacy Control', 'globalPrivacyControl', true],
    ])('%s with the option false: the page view is sent', (_label, flag, value) => {
        setFlag(flag, value);

        const sent = loadWith([['setOption', 'honorPrivacySignals', false], ...PAGE]);

        expect(sent).toHaveLength(1);
        expect(sent[0]).toMatch(/[?&]site=privacy-site/);
    });

    test('the option is found wherever it sits in the queue', () => {
        setFlag('doNotTrack', '1');

        const sent = loadWith([...PAGE, ['setOption', 'honorPrivacySignals', false]]);

        expect(sent).toHaveLength(1);
    });

    test('the option true keeps the signal honoured', () => {
        setFlag('doNotTrack', '1');

        const sent = loadWith([['setOption', 'honorPrivacySignals', true], ...PAGE]);

        expect(sent).toHaveLength(0);
    });

    test('a named tracker\'s option does not decide for the page', () => {
        setFlag('doNotTrack', '1');

        const sent = loadWith([['siteB.setOption', 'honorPrivacySignals', false], ...PAGE]);

        expect(sent).toHaveLength(0);
    });
});
