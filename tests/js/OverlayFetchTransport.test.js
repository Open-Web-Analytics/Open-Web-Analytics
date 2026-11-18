import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * The overlay fetches its data over CORS, not JSONP.
 *
 * JSONP is not a transport, it is a way around the same-origin policy: the
 * response comes back as a `<script>` the browser executes, so the endpoint is
 * reachable by any page on the internet and its body runs with that page's
 * privileges. The heatmap overlay and the recording player run on the *tracked*
 * site and call back to the OWA origin -- genuinely cross-origin -- so they use
 * CORS.
 *
 * The overlays fetch with fetch(): whatever comes back is read as JSON, never
 * run, and no <script> is added to the page.
 */
describe('the overlay fetches over CORS, not JSONP', () => {

    let calls;
    let answer;

    beforeEach(() => {
        calls = [];
        answer = { ok: true, json: () => Promise.resolve({ data: { resultsRows: [], more: false } }) };
        global.fetch = jest.fn((url, init) => {
            calls.push({ url, init });
            return Promise.resolve(answer);
        });

        // The heatmap fetches only from this OWA server.
        OWA.setSetting('baseUrl', 'https://reporting.example.com/owa/');

        OWA.overlayParams = {
            action: 'loadHeatmap',
            api_url: 'https://reporting.example.com/owa/api/index.php'
                + '?owa_do=reports&owa_document_id=doc-1&owa_overlayToken=tok',
        };
    });

    afterEach(() => {
        delete global.fetch;
        OWA.overlayParams = null;
        document.body.innerHTML = '';
    });

    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    async function heatmap() {
        const { Heatmap } = await import('../../modules/Base/src/tracker/Heatmap.js');
        return Object.assign(Object.create(Heatmap.prototype), { clicks: '', nextPage: 1, more: true, rows: [] });
    }

    async function player() {
        const { Player } = await import('../../modules/Domstream/src/tracker/Player.js');
        return new Player();
    }

    test('the heatmap GETs the URL the admin interface supplied, without cookies', async () => {
        const h = await heatmap();
        h.fetchData(1);
        await settle();

        expect(calls).toHaveLength(1);
        // Not derived client-side: the tracker's base URL is where it *logs*,
        // which need not be where reporting lives.
        expect(calls[0].url).toBe(OWA.overlayParams.api_url);
        expect(calls[0].init.method).toBe('GET');
        // The credential is in the query string; no cookie rides along.
        expect(calls[0].init.credentials).toBe('omit');
    });

    test('the heatmap draws what the JSON answer holds', async () => {
        const h = await heatmap();
        h.plotClickData = jest.fn();

        h.fetchData(1);
        await settle();

        expect(h.plotClickData).toHaveBeenCalledWith({ data: { resultsRows: [], more: false } });
    });

    test('the player loads the recording the JSON answer holds', async () => {
        OWA.overlayParams = { action: 'loadPlayer', api_url: OWA.overlayParams.api_url };
        answer = { ok: true, json: () => Promise.resolve({ data: { samples: [[0, 'm', 1, 2]] } }) };
        const p = await player();

        p.fetchData();
        await settle();

        expect(calls[0].url).toBe(OWA.overlayParams.api_url);
        expect(calls[0].init.credentials).toBe('omit');
        expect(p.samples).toEqual([[0, 'm', 1, 2]]);
    });

    test('an answer that is not 2xx, or not JSON, is dropped', async () => {
        const h = await heatmap();
        h.plotClickData = jest.fn();

        answer = { ok: false, json: () => Promise.resolve({ data: {} }) };
        h.fetchData(1);
        await settle();

        answer = { ok: true, json: () => Promise.reject(new SyntaxError('Unexpected token')) };
        h.fetchData(1);
        await settle();

        expect(calls).toHaveLength(2);
        expect(h.plotClickData).not.toHaveBeenCalled();
    });

    test('nothing is added to the page as a script', async () => {
        const h = await heatmap();
        const scripts = document.getElementsByTagName('script').length;

        h.fetchData(1);
        await settle();

        expect(document.getElementsByTagName('script')).toHaveLength(scripts);
        expect(String(calls[0].url)).not.toMatch(/callback=/i);
    });

    test('a next page on another host is not fetched', async () => {
        const h = await heatmap();
        h.clicks = { next: 'https://evil.example/x.js?cb=?' };

        h.fetchData(2);
        await settle();

        expect(calls).toHaveLength(0);
    });
});
