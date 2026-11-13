import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * A tracked page opened from an overlay link: #owa_overlay.<base64 params>.
 *
 * Anyone can write such a link to any tracked page. The tracker used to pause
 * itself and start the overlay whatever the params said, and the overlay then
 * fetched the link's api_url -- as a script, when that URL asked for JSONP.
 * Now only a link whose api_url is on the OWA server the page reports to
 * starts an overlay; any other is ignored and the page is tracked as usual.
 */

const OWA_SERVER = 'https://reporting.example.com/owa/';

function openWithOverlay(params) {
    const encoded = encodeURIComponent(Util.base64_encode(JSON.stringify(params)));
    window.history.replaceState(null, '', `/page#owa_overlay.${encoded}`);
}

function openWithFragment(fragment) {
    window.history.replaceState(null, '', `/page#${fragment}`);
}

describe('the overlay fragment on a tracked page', () => {

    let started;

    beforeEach(() => {
        OWA.overlayParams = null;
        OWA.overlayActive = false;
        OWA.setSetting('baseUrl', OWA_SERVER);
        started = [];
        jest.spyOn(OWA, 'startOverlaySession').mockImplementation((p) => { started.push(p); return true; });
    });

    afterEach(() => {
        jest.restoreAllMocks();
        window.history.replaceState(null, '', '/');
    });

    test('a link to this OWA server starts the overlay and pauses tracking', () => {
        openWithOverlay({ action: 'loadHeatmap', api_url: `${OWA_SERVER}api/index.php?owa_do=reports` });

        const t = new OWATracker({ baseUrl: OWA_SERVER });

        expect(started).toHaveLength(1);
        expect(t.active).toBe(false);
    });

    test.each([
        ['another host', 'https://evil.example/x.js?cb=?'],
        ['a javascript: URL', 'javascript:alert(1)'],
        ['no api_url', undefined],
    ])('a link with %s is ignored, and the page is tracked', (_name, url) => {
        openWithOverlay({ action: 'loadHeatmap', api_url: url });

        const t = new OWATracker({ baseUrl: OWA_SERVER });

        expect(started).toHaveLength(0);
        expect(t.active).not.toBe(false);
    });

    test('a fragment that cannot be read is ignored, and the tracker still starts', () => {
        openWithFragment('owa_overlay.%zz');

        const t = new OWATracker({ baseUrl: OWA_SERVER });

        expect(started).toHaveLength(0);
        expect(t.active).not.toBe(false);
    });
});
