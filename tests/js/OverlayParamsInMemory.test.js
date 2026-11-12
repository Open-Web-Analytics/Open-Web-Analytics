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
        // The OWA server this page reports to; api_url must be on it.
        OWA.setSetting('baseUrl', 'https://reporting.example.com/owa/');
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

/**
 * The overlay starts from a link on the tracked page, which anyone can write.
 * Its data may come only from the OWA server the page reports to: a link that
 * pointed it elsewhere could have the page run a script from there.
 */
describe('an overlay link must point at this OWA server', () => {

    beforeEach(() => {
        OWA.overlayParams = null;
        OWA.overlayActive = false;
        OWA.setSetting('baseUrl', 'https://reporting.example.com/owa/');
    });

    test.each([
        ['another host', 'https://evil.example/x.js?cb=?'],
        ['a lookalike host', 'https://reporting.example.com.evil.example/owa/api/'],
        ['another port', 'https://reporting.example.com:8443/owa/api/'],
        ['another scheme', 'http://reporting.example.com/owa/api/'],
        ['a javascript: URL', 'javascript:alert(1)'],
        ['no URL', undefined],
    ])('%s is refused, and no session starts', (_name, url) => {
        expect(OWA.startOverlaySession({ action: 'loadHeatmap', api_url: url })).toBe(false);
        expect(OWA.overlayActive).toBe(false);
        expect(OWA.getOverlayParams()).toBeNull();
    });

    test('nothing is allowed when the OWA server is not known', () => {
        OWA.setSetting('baseUrl', '');

        expect(OWA.isOwaServerUrl('https://reporting.example.com/owa/api/')).toBe(false);
    });

    test('a URL on the OWA server is allowed', () => {
        expect(OWA.isOwaServerUrl('https://reporting.example.com/owa/api/index.php?owa_do=reports')).toBe(true);
    });
});
