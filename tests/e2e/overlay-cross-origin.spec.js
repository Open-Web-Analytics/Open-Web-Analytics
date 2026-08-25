// @ts-check
/**
 * The heatmap overlay and the domstream player fetch their data cross-origin.
 *
 * This is the property that JSONP existed to provide, and nothing tested it.
 * Both overlays run on the *tracked* site and fetch from the OWA origin, so
 * their request is genuinely cross-origin; JSONP got around the same-origin
 * policy by returning the body as an executable script. Replacing it with CORS
 * is only safe if CORS actually works from a browser, which curl cannot show
 * and the existing overlay spec explicitly does not assert -- it says so in its
 * own header: "the subsequent click-data fetch ... is not asserted (it simply
 * never calls back in this harness)".
 *
 * That silence is the point. JSONP fails by never calling back, so an overlay
 * whose fetch was broken looked exactly like one whose fetch was pending. The
 * DOM assertions passed either way.
 *
 * HOW THIS IS CROSS-ORIGIN WITHOUT A SECOND HOST
 * The self-host runner serves one php -S on 127.0.0.1:PORT, and
 * http://localhost:PORT is a *different origin* from http://127.0.0.1:PORT --
 * same server, different host string, so the browser sends a real Origin header
 * and enforces the response's CORS headers for real. The fixture provisions a
 * site at http://localhost so the request is allowed on the merits: the matcher
 * compares the Origin's host against configured sites.
 *
 * WHAT WOULD HAVE CAUGHT WHAT
 * Three separate defects had to be fixed before this could pass, and each was
 * invisible to every other suite:
 *   - addCorsHeaders() never emitted a header (row arrays vs a string)
 *   - isHttps() let the Origin header flip the server's own scheme, breaking
 *     the signature on every signed cross-origin request
 *   - the overlay read its params from a cookie that had been removed
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const HELPER = path.join(__dirname, 'overlay_e2e_helper.php');
const HARNESS_HTML = fs.readFileSync(path.join(__dirname, 'overlay_harness.html'), 'utf8');

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

/** The same install, addressed by the other host name -- a different origin. */
function crossOrigin(root) {
    return root.replace('127.0.0.1', 'localhost');
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

/**
 * Chrome's Local Network Access check has to be off for this spec, and that is
 * not a way of dodging the thing being tested.
 *
 * LNA is a *separate* gate from CORS: a page may not reach the loopback or
 * private address space without a user permission, and it is refused before the
 * response's CORS headers are ever consulted -- headless Chrome auto-denies, so
 * the fetch fails with status 0 and "Permission was denied for this request to
 * access the `loopback` address space", which looks exactly like a missing
 * Access-Control-Allow-Origin and is not one.
 *
 * It fires here only because the two origins are 127.0.0.1 and localhost, which
 * is an artifact of getting two origins out of one php -S. A real overlay runs
 * on a public tracked site fetching a public OWA host; neither is in the local
 * address space, so LNA does not apply to the case this spec exists to cover.
 * Leaving it on would test the harness rather than the product.
 */
test.use({
    launchOptions: { args: ['--disable-features=LocalNetworkAccessChecks'] },
});

test.describe('overlays fetch cross-origin @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Provisions a site, clicks and a domstream; runs only under the self-host e2e runner.');

    /** @type {{site_id:string, document_id:string, page_path:string, constraints:string, domstream_guid:string, heatmap_token:string, player_token:string, clicks:number}} */
    let fx;

    test.beforeAll(() => {
        fx = helper('provision');
        expect(fx.clicks, 'no click rows were seeded for the heatmap to fetch').toBeGreaterThan(0);
        expect(fx.heatmap_token, 'no heatmap token was minted').toBeTruthy();
        expect(fx.player_token, 'no player token was minted').toBeTruthy();
    });

    test.afterAll(() => {
        helper('cleanup');
    });

    /**
     * Loads the tracked-page harness on the localhost origin, pointing the
     * overlay at the API on the 127.0.0.1 origin.
     */
    async function runOverlay(page, testInfo, { action, apiQuery, extra = '' }) {
        const apiRoot = installRoot(testInfo.project.use.baseURL);      // 127.0.0.1
        const pageRoot = crossOrigin(apiRoot);                          // localhost

        const apiUrl = apiRoot + 'api/index.php?' + apiQuery;

        const harness = pageRoot + 'tests/e2e/overlay_harness.html'
            + '?base=' + encodeURIComponent(pageRoot)
            + '&action=' + encodeURIComponent(action)
            + '&api=' + encodeURIComponent(apiUrl)
            + extra;

        // tests/ is 403'd by the deny-all .htaccess, so the document is served
        // from disk at the right origin (see overlay-heatmap.spec.js).
        await page.route('**/tests/e2e/overlay_harness.html*', (route) =>
            route.fulfill({ status: 200, contentType: 'text/html', body: HARNESS_HTML })
        );

        const consoleErrors = [];
        page.on('console', (m) => {
            if (m.type() === 'error') consoleErrors.push(m.text());
        });

        await page.goto(harness, { waitUntil: 'load' });

        // Wait for the overlay's own XHR to complete.
        await page.waitForFunction(
            () => (window.owaOverlayFetches || []).some((f) => f.url.indexOf('api/index.php') !== -1),
            null,
            { timeout: 15000 }
        );

        const fetches = await page.evaluate(() =>
            (window.owaOverlayFetches || []).filter((f) => f.url.indexOf('api/index.php') !== -1)
        );

        return { fetches, consoleErrors, apiRoot, pageRoot };
    }

    test('the heatmap fetches its click data from another origin', async ({ page }, testInfo) => {
        const { fetches, consoleErrors, apiRoot, pageRoot } = await runOverlay(page, testInfo, {
            action: 'loadHeatmap',
            // An ordinary dimensional query: clicks grouped by coordinate,
            // constrained on the page. There is no clicks report any more.
            apiQuery: 'owa_do=reports&owa_module=base&owa_version=v1'
                + '&owa_metrics=domClicks'
                + '&owa_dimensions=' + encodeURIComponent('clickX,clickY')
                + '&owa_siteId=' + encodeURIComponent(fx.site_id)
                + '&owa_constraints=' + encodeURIComponent(fx.constraints)
                + '&owa_overlayToken=' + encodeURIComponent(fx.heatmap_token),
            extra: '&pagePath=' + encodeURIComponent(fx.page_path),
        });

        // The premise: the page and the API really are different origins.
        expect(pageRoot).not.toBe(apiRoot);

        const call = fetches[0];
        expect(call, 'the overlay issued no API request').toBeDefined();

        // 401 here means the credential or the signature failed; 0 means the
        // browser blocked the response for want of CORS headers -- the exact
        // failure JSONP was hiding.
        expect(call.status,
            `cross-origin overlay fetch failed (status ${call.status})\n`
            + `  console: ${JSON.stringify(consoleErrors)}`
        ).toBe(201);
        expect(call.length, 'the response body was empty').toBeGreaterThan(0);

        const corsBlocked = consoleErrors.filter((e) => /CORS|Access-Control/i.test(e));
        expect(corsBlocked, 'the browser reported a CORS failure').toEqual([]);
    });

    test('the player fetches its recording from another origin', async ({ page }, testInfo) => {
        const { fetches, consoleErrors } = await runOverlay(page, testInfo, {
            action: 'loadPlayer',
            apiQuery: 'owa_do=domstreams&owa_module=domstream&owa_version=v1'
                // siteId is what makeOverlayApiLink's add_state contributes; the
                // controller declares it required.
                + '&owa_siteId=' + encodeURIComponent(fx.site_id)
                + '&owa_domstream_guid=' + encodeURIComponent(fx.domstream_guid)
                + '&owa_overlayToken=' + encodeURIComponent(fx.player_token),
            extra: '&domstream_guid=' + encodeURIComponent(fx.domstream_guid),
        });

        const call = fetches[0];
        expect(call, 'the player issued no API request').toBeDefined();
        expect(call.status,
            `cross-origin player fetch failed (status ${call.status})\n  body: ${call.body}`
        ).toBe(201);

        const corsBlocked = consoleErrors.filter((e) => /CORS|Access-Control/i.test(e));
        expect(corsBlocked, 'the browser reported a CORS failure').toEqual([]);
    });

    test('an overlay token is refused for a resource it does not name', async ({ page }, testInfo) => {
        // The scope check, exercised through a real browser fetch rather than a
        // unit test: the heatmap token names one page.
        const { fetches } = await runOverlay(page, testInfo, {
            action: 'loadHeatmap',
            apiQuery: 'owa_do=reports&owa_module=base&owa_version=v1'
                + '&owa_metrics=domClicks'
                + '&owa_dimensions=' + encodeURIComponent('clickX,clickY')
                + '&owa_siteId=' + encodeURIComponent(fx.site_id)
                + '&owa_constraints=' + encodeURIComponent('pagePath==/some-other-page')
                + '&owa_overlayToken=' + encodeURIComponent(fx.heatmap_token),
        });

        const call = fetches[0];
        expect(call, 'no request was issued').toBeDefined();
        expect(call.status, 'a token must not work for a page it does not name').toBe(401);
    });
});
