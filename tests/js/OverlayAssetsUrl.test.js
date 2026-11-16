jest.mock('../../modules/Domstream/src/tracker/Player.js', () => ({
    Player: class { init() {} },
}));
jest.mock('../../modules/Base/src/tracker/Heatmap.js', () => ({
    Heatmap: class { constructor() { this.options = {}; } generate() {} },
}));

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';
import '../../modules/Domstream/src/tracker/Overlay.js';

/**
 * The overlay stylesheet loads from window.owa_assetsUrl when the page sets it
 * (a CDN standing for <baseUrl>public/), otherwise from <baseUrl>public/. The
 * heatmap and the session player look it up the same way.
 */

let loadCss;

beforeEach(() => {
    OWA.setSetting('baseUrl', 'https://owa.example.test/');
    loadCss = jest.spyOn(Util, 'loadCss').mockImplementation(() => {});
});

afterEach(() => {
    jest.restoreAllMocks();
    delete window.owa_assetsUrl;
});

test('getAssetsUrl is <baseUrl>public/ without owa_assetsUrl', () => {
    expect(OWA.getAssetsUrl()).toBe('https://owa.example.test/public/');
});

test('getAssetsUrl is owa_assetsUrl when the page sets it', () => {
    window.owa_assetsUrl = 'https://cdn.example.test/owa/';

    expect(OWA.getAssetsUrl()).toBe('https://cdn.example.test/owa/');
});

test.each([
    ['the session player', () => OWA.overlayModes.loadPlayer({})],
    ['the heatmap', () => OWA.loadHeatmap({})],
])('%s loads its stylesheet from owa_assetsUrl', (_name, start) => {
    window.owa_assetsUrl = 'https://cdn.example.test/owa/';

    start();

    expect(loadCss).toHaveBeenCalledWith('https://cdn.example.test/owa/base/css/owa.overlay.css', expect.any(Function));
});

test.each([
    ['the session player', () => OWA.overlayModes.loadPlayer({})],
    ['the heatmap', () => OWA.loadHeatmap({})],
])('%s loads its stylesheet from <baseUrl>public/ without owa_assetsUrl', (_name, start) => {
    start();

    expect(loadCss).toHaveBeenCalledWith('https://owa.example.test/public/base/css/owa.overlay.css', expect.any(Function));
});
