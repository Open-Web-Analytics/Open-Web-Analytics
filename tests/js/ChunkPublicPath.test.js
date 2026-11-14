/**
 * Where the tracker's lazy chunks load from: webpack's publicPath, set by
 * tracker-dom.js from the page globals the tag or the bundle writes. With
 * OWA_ASSETS_URL (a CDN) the tag and the bundle set owa_assetsUrl, which
 * stands for <owa_baseUrl>public/.
 */
global.__webpack_public_path__ = '';

let savedWebdriver;

beforeEach(() => {
    global.__webpack_public_path__ = '';
    savedWebdriver = Object.getOwnPropertyDescriptor(navigator, 'webdriver');
    Object.defineProperty(navigator, 'webdriver', { configurable: true, get() { return false; } });
});

afterEach(() => {
    if (savedWebdriver) { Object.defineProperty(navigator, 'webdriver', savedWebdriver); } else { delete navigator.webdriver; }
    delete window.owa_baseUrl;
    delete window.owa_assetsUrl;
    delete window.owa_cmds;
    delete global.owa_cmds;
    delete window.OWATracker;
    jest.resetModules();
});

function load() {
    global.owa_cmds = [];
    window.owa_cmds = global.owa_cmds;
    jest.isolateModules(() => {
        require('../../modules/Base/src/tracker/tracker-dom.js');
    });
    return global.__webpack_public_path__;
}

test('by default the chunks load from the public URL\'s public/ tree', () => {
    window.owa_baseUrl = 'https://owa.example.test/';

    expect(load()).toBe('https://owa.example.test/public/base/dist/');
});

test('owa_assetsUrl replaces <owa_baseUrl>public/', () => {
    window.owa_baseUrl   = 'https://owa.example.test/';
    window.owa_assetsUrl = 'https://cdn.example.test/owa/';

    expect(load()).toBe('https://cdn.example.test/owa/base/dist/');
});

test('with neither set, webpack keeps its own default', () => {
    expect(load()).toBe('');
});
