import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * An overlay session runs whatever registered its action.
 *
 * owa.js named its two overlays in an if/else, so an overlay a module ships
 * could not be started without editing Base. A module registers its mode from
 * code compiled into the bundle; Base registers the heatmap the same way.
 */

beforeEach(() => {
    OWA.loadHeatmap = jest.fn();
});

test('a registered mode runs with the session params', () => {
    const run = jest.fn();
    OWA.registerOverlayMode('loadAcmeOverlay', run);

    OWA.startOverlaySession({ action: 'loadAcmeOverlay', api_url: 'https://owa.example/api/' });

    expect(run).toHaveBeenCalledWith({ action: 'loadAcmeOverlay', api_url: 'https://owa.example/api/' });
});

test('the heatmap is a registered mode like any other', () => {
    OWA.startOverlaySession({ action: 'loadHeatmap' });

    expect(OWA.loadHeatmap).toHaveBeenCalledTimes(1);
});

test('an action nothing registered starts nothing, and does not throw', () => {
    expect(() => OWA.startOverlaySession({ action: 'loadNothing' })).not.toThrow();
    expect(OWA.loadHeatmap).not.toHaveBeenCalled();
});

test('a mode name inherited from Object is not a mode', () => {
    expect(() => OWA.startOverlaySession({ action: 'toString' })).not.toThrow();
});

test('registration ignores what is not a function', () => {
    OWA.registerOverlayMode('loadBroken', 'not a function');

    expect(() => OWA.startOverlaySession({ action: 'loadBroken' })).not.toThrow();
});
