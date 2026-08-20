// Heatmap and Player call jQuery(...) and jQuery.ajax(...). Same interop fix as
// DomStreamPlayback.test.js: babel-jest's wildcard namespace is not callable, so
// mark the real jQuery as an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import * as jQuery from 'jquery';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * The overlay fetches its data over CORS, not JSONP.
 *
 * JSONP is not a transport, it is a way around the same-origin policy: the
 * response comes back as a `<script>` the browser executes, so the endpoint is
 * reachable by any page on the internet and its body runs with that page's
 * privileges. OWA used it for the heatmap overlay and the domstream player
 * because those run on the *tracked* site and call back to the OWA origin --
 * genuinely cross-origin, and CORS did not work.
 *
 * CORS does work now: addCorsHeaders() had never emitted a header (it compared
 * site row arrays against the Origin string), and isHttps() let a client's
 * Origin header flip the server's own scheme, which broke the signature on
 * every cross-origin signed request. With both fixed and covered end to end,
 * the reason for JSONP is gone.
 *
 * These tests pin the transport rather than the plumbing, so they keep meaning
 * something if the fetch is ever rewritten off jQuery: whatever issues the
 * request must not ask for a JSONP response, and must not smuggle a callback
 * name into the URL.
 */
describe('the overlay fetches over CORS, not JSONP', () => {

    let calls;

    beforeEach(() => {
        calls = [];
        jest.spyOn(jQuery, 'ajax').mockImplementation((opts) => {
            calls.push(opts);
            return { done: () => {}, fail: () => {} };
        });

        OWA.overlayParams = {
            action: 'loadHeatmap',
            api_url: 'https://reporting.example.com/owa/api/index.php'
                + '?owa_do=reports&owa_document_id=doc-1&owa_overlayToken=tok',
        };
    });

    afterEach(() => {
        jest.restoreAllMocks();
        OWA.overlayParams = null;
    });

    async function heatmapFetch() {
        const { Heatmap } = await import('../../modules/Base/src/tracker/Heatmap.js');
        const h = new Heatmap();
        h.fetchData(1);
        return calls[0];
    }

    async function playerFetch() {
        const { Player } = await import('../../modules/Base/src/tracker/Player.js');
        const p = new Player();
        p.fetchData();
        return calls[0];
    }

    test('the heatmap does not request a JSONP response', async () => {
        const opts = await heatmapFetch();

        expect(opts).toBeDefined();
        // JSONP would make the response an executable script.
        expect(opts.dataType).not.toBe('jsonp');
        // and must not request a callback parameter.
        expect(opts.jsonp).toBeUndefined();
    });

    test('the player does not request a JSONP response', async () => {
        const opts = await playerFetch();

        expect(opts).toBeDefined();
        expect(opts.dataType).not.toBe('jsonp');
        expect(opts.jsonp).toBeUndefined();
    });

    test('the request goes to the URL the admin interface supplied', async () => {
        const opts = await heatmapFetch();

        // Not derived client-side: the tracker's base URL is where it *logs*,
        // which need not be where reporting lives.
        expect(opts.url).toBe(OWA.overlayParams.api_url);
    });

    test('no callback name is smuggled into the URL', async () => {
        const opts = await heatmapFetch();

        expect(String(opts.url)).not.toMatch(/jsonpCallback/i);
        expect(String(opts.url)).not.toMatch(/callback=/i);
    });
});
