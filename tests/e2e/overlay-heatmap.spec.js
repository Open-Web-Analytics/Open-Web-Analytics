/**
 * Overlay (heatmap) real-render characterization.
 *
 * WHY THIS EXISTS
 * The heatmap overlay runs on the *tracked* page, built with DOM APIs alone
 * (no jQuery on the tracker side). This test pins that a real browser builds
 * the overlay from the URL anchor, on the shipped tracker bundle and chunk.
 *
 * WHAT IT DOES
 * The overlay never renders on an OWA page -- it renders on the *tracked* page.
 * tests/e2e/overlay_harness.html stands in for that tracked page: it loads the
 * real dist/owa.tracker.js (same-origin with this install) and carries the
 * #owa_overlay.<base64params> anchor that base.overlayLauncher would redirect a
 * real browser to. The tracker's checkForOverlaySession() decodes the anchor
 * and, via OWA_instance.startOverlaySession(), dynamic-imports Heatmap.js and
 * calls generate(): showControlPanel() builds #owa_overlay + its controls and
 * createCanvas() appends #owa_heatmap.
 *
 * WHAT IT ASSERTS (and what it deliberately does not)
 * Only the deterministic DOM: the panel, which carries the OWA logo and the
 * Stats toggle, and the canvas element. The click-data fetch, and that points
 * land where the clicks were, are covered cross-origin and against a real API
 * by overlay-cross-origin.spec.js.
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

// baseURL in playwright.config.js points at <install>/index.php; strip the
// index.php entry script to reach the install root that serves the tree.
function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

// The harness HTML, read from disk and fulfilled via route interception below.
// It USED to be fetched over HTTP from tests/e2e/, but the Phase 5 deny-all
// .htaccess (correctly) 403s the whole tests/ tree -- test infra ships in no
// release tarball and is not a public asset, so it must not be web-served. We
// still need the harness to load at an OWA-origin URL, though: the tracker is
// injected by absolute URL and webpack's auto publicPath derives the heatmap
// import() chunk base from document.currentScript.src, so that chunk fetch is
// same-origin and CORS-clean ONLY if the harness document itself is same-origin.
// So we navigate to the (denied) OWA URL and fulfill the document from disk --
// origin stays the OWA host, the real tracker + chunk still load from the live
// server (both allowlisted, 200), and no tests/ exception is punched in the deny-all.
const HARNESS_HTML = fs.readFileSync(
    path.join(__dirname, 'overlay_harness.html'), 'utf8'
);

test.describe('heatmap overlay renders on the tracker path', () => {
    let pageErrors;
    let overlayCssResponses;

    test.beforeEach(async ({ page }, testInfo) => {
        pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(err.message));

        // Record every owa.overlay.css fetch (url + status). loadHeatmap() requests it
        // right after the dynamic import resolves -- possibly after 'load' -- so the
        // listener must be attached BEFORE goto, and the test polls this array.
        overlayCssResponses = [];
        page.on('response', (r) => {
            if (r.url().includes('owa.overlay.css')) {
                overlayCssResponses.push({ url: r.url(), status: r.status() });
            }
        });

        const root = installRoot(testInfo.project.use.baseURL);
        const harness = root + 'tests/e2e/overlay_harness.html'
            + '?base=' + encodeURIComponent(root);

        // Serve the harness document from disk (the deny-all .htaccess 403s tests/).
        // Only the top-level document is intercepted; the tracker + heatmap chunk it
        // pulls in fall through to the live OWA server, same-origin.
        await page.route(harness, (route) =>
            route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
        );

        await page.goto(harness, { waitUntil: 'load' });
    });

    /**
     * The panel carries the OWA logo and one control, Stats, which opens the
     * counts of what was and was not drawn. Start and Stop are gone -- they
     * paused fetching pages of clicks that now come in one request -- and so
     * is X, which threw rather than closing anything; the reader closes the
     * window.
     */
    test('the tracker builds the overlay panel from the URL anchor: the logo and Stats', async ({ page }) => {
        // The dynamic import of Heatmap.js is async, so wait on the element
        // rather than asserting synchronously.
        await expect(page.locator('#owa_overlay')).toBeVisible({ timeout: 20_000 });

        await expect(page.locator('#owa_overlay_logo')).toHaveCount(1);
        await expect(page.locator('#owa_overlay').getByRole('button')).toHaveCount(1);

        const stats = page.getByRole('button', { name: 'Stats' });
        await expect(stats).toHaveAttribute('aria-expanded', 'false');
        await expect(page.locator('#owa_overlay_stats')).toBeHidden();

        await stats.click();
        await expect(stats).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('#owa_overlay_stats')).toBeVisible();
        await expect(page.getByRole('rowheader').first()).toHaveText('Clicks');

        // From the keyboard too.
        await stats.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('#owa_overlay_stats')).toBeHidden();
    });

    test('the heatmap canvas element is created', async ({ page }) => {
        // createCanvas() appends <canvas id="owa_heatmap">, and the
        // Heatmap constructor getContext('2d')s it -- proves the canvas exists
        // and is a real 2d-context canvas.
        const canvas = page.locator('canvas#owa_heatmap');
        await expect(canvas).toHaveCount(1, { timeout: 20_000 });

        const has2dContext = await canvas.evaluate(
            (el) => !!(el instanceof HTMLCanvasElement && el.getContext('2d'))
        );
        expect(has2dContext).toBe(true);
    });

    test('the overlay renders without uncaught page errors', async ({ page }) => {
        await expect(page.locator('#owa_overlay')).toBeVisible({ timeout: 20_000 });
        // Give any microtasks from the dynamic import a beat to surface errors.
        await page.waitForTimeout(500);
        expect(pageErrors, 'overlay bootstrap threw:\n' + pageErrors.join('\n')).toEqual([]);
    });

    test('the overlay stylesheet loads from public/ (not the denied module tree)', async ({ page }) => {
        // loadHeatmap() does Util.loadCss(baseUrl + '/public/base/css/owa.overlay.css').
        // The Phase 5 deny-all 403s modules/base/css/, so this asset MUST resolve under
        // public/ or the overlay renders unstyled. Regression guard: the tracker/overlay
        // code hardcodes asset paths (webpack can't rewrite a runtime-built URL string),
        // so a stale modules/base/ path silently 403s -- caught here, not by the DOM tests.
        // The fetch is recorded in beforeEach (listener attached before navigation); wait
        // for the overlay to build, then assert on what was fetched.
        await expect(page.locator('#owa_overlay')).toBeVisible({ timeout: 20_000 });
        await expect.poll(() => overlayCssResponses.length, { timeout: 20_000 })
            .toBeGreaterThan(0);
        const cssResp = overlayCssResponses[0];
        expect(cssResp.url).toContain('/public/base/css/owa.overlay.css');
        expect(cssResp.status, 'overlay.css must be served, not denied').toBe(200);
    });
});
