// startOverlaySession() dispatches into Heatmap.js/Player.js, which call
// jQuery(...). Same interop fix as DomStreamPlayback.test.js: babel-jest's
// wildcard namespace is not callable, so mark the real jQuery as an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Overlay params are held in memory, not in a cookie -- and are actually
 * readable by the code that needs them.
 *
 * They used to be written to an `owa_overlay` cookie on the *tracked* site's
 * own domain, path `/`, so Heatmap.fetchData() and Player.fetchData() could
 * read `api_url` back out. That put a credential somewhere every other script
 * on the page could read and the browser re-sent to that site on every
 * request.
 *
 * The cookie was never necessary: startOverlaySession() already receives the
 * decoded params from the URL fragment. It now keeps them for the page's
 * lifetime instead.
 *
 * These tests exist because the failure mode of getting this wrong is silent.
 * If getOverlayParams() returned nothing, `var url = params.api_url` would be
 * undefined, jQuery.ajax would be called with an undefined URL, and the overlay
 * would simply never draw -- no error, no exception, nothing for a suite that
 * only asserts DOM construction to catch. The existing overlay e2e explicitly
 * does not assert the fetch ("it simply never calls back in this harness"), so
 * nothing else covers this.
 */
describe('overlay params are kept in memory', () => {

    const params = {
        action: 'loadHeatmap',
        api_url: 'https://reporting.example.com/owa/api/index.php?owa_do=reports&owa_overlayToken=abc',
    };

    beforeEach(() => {
        OWA.overlayParams = null;
        OWA.overlayActive = false;
        document.cookie.split(';').forEach((c) => {
            const name = c.split('=')[0].trim();
            if (name) {
                document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`;
            }
        });
    });

    test('startOverlaySession keeps the params where the overlay can read them', () => {
        OWA.startOverlaySession(params);

        expect(OWA.getOverlayParams()).toEqual(params);
        expect(OWA.getOverlayParams().api_url).toBe(params.api_url);
    });

    test('the api_url is never written to a cookie', () => {
        OWA.startOverlaySession(params);

        // The whole point: the credential in api_url must not be readable via
        // document.cookie by anything else running on the tracked page.
        expect(document.cookie).not.toContain('owa_overlay');
        expect(document.cookie).not.toContain('overlayToken');
        expect(document.cookie).not.toContain('api_url');
    });

    test('the session flag is set, so the tracker knows to stay paused', () => {
        expect(OWA.overlayActive).toBe(false);

        OWA.startOverlaySession(params);

        expect(OWA.overlayActive).toBe(true);
    });

    test('ending the session drops the params', () => {
        OWA.startOverlaySession(params);
        expect(OWA.getOverlayParams()).not.toBeNull();

        OWA.endOverlaySession();

        expect(OWA.getOverlayParams()).toBeNull();
        expect(OWA.overlayActive).toBe(false);
    });

    test('getOverlayParams returns null rather than undefined when no session is running', () => {
        // fetchData() does `OWA_instance.getOverlayParams() || {}`, so a falsy
        // return is required -- undefined would work by accident, null says so.
        expect(OWA.getOverlayParams()).toBeNull();
    });

    test('a second overlay session replaces the first', () => {
        OWA.startOverlaySession(params);

        const second = { action: 'loadPlayer', api_url: 'https://reporting.example.com/other' };
        OWA.startOverlaySession(second);

        expect(OWA.getOverlayParams()).toEqual(second);
    });
});
