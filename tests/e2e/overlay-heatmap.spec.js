/**
 * Overlay (heatmap) real-render characterization.
 *
 * WHY THIS EXISTS
 * The heatmap/player overlay is the single largest untested jQuery-dependent
 * surface in OWA (Heatmap.js + Player.js, ~950 lines, dozens of jQuery calls).
 * It runs on the *tracker* code path, which is on jQuery 3.x. This test pins the
 * fact that jQuery 3.x can still build the overlay DOM at runtime.
 *
 * WHAT IT DOES
 * The overlay never renders on an OWA page -- it renders on the *tracked* page.
 * tests/e2e/overlay_harness.html stands in for that tracked page: it loads the
 * real dist/owa.tracker.js (same-origin with this install) and carries the
 * #owa_overlay.<base64params> anchor that base.overlayLauncher would redirect a
 * real browser to. The tracker's checkForOverlaySession() decodes the anchor
 * and, via OWA_instance.startOverlaySession(), dynamic-imports Heatmap.js and
 * calls generate(): showControlPanel() builds #owa_overlay + its controls and
 * createCanvas() appends #owa_heatmap -- all through jQuery 3.x.
 *
 * WHAT IT ASSERTS (and what it deliberately does not)
 * Only the synchronous, deterministic jQuery-built DOM: the control panel, its
 * three controls, the toggled "active" class, and the canvas element. The
 * subsequent click-data fetch is an async call against live data; it is not
 * part of the "does jQuery 3.x still build the overlay" contract and is not
 * asserted here. It is covered on its own, cross-origin and against a real API,
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

test.describe('heatmap overlay renders on the tracker path (jQuery 3.x)', () => {
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

    test('the tracker builds the overlay control panel from the URL anchor', async ({ page }) => {
        // showControlPanel() appends #owa_overlay and its controls via jQuery.
        // The dynamic import of Heatmap.js + its jQuery work is async, so wait
        // on the element rather than asserting synchronously.
        await expect(page.locator('#owa_overlay')).toBeVisible({ timeout: 20_000 });

        // The three controls showControlPanel() builds.
        await expect(page.locator('#owa_overlay_start')).toHaveCount(1);
        await expect(page.locator('#owa_overlay_stop')).toHaveCount(1);
        await expect(page.locator('#owa_overlay_end')).toHaveCount(1);
        await expect(page.locator('#owa_overlay_logo')).toHaveCount(1);
    });

    test('the start control is toggled active (jQuery toggleClass ran)', async ({ page }) => {
        // showControlPanel() calls jQuery('#owa_overlay_start').toggleClass('active').
        await expect(page.locator('#owa_overlay_start')).toHaveClass(/active/, { timeout: 20_000 });
    });

    test('the heatmap canvas element is created', async ({ page }) => {
        // createCanvas() appends <canvas id="owa_heatmap"> via jQuery, and the
        // Heatmap constructor getContext('2d')s it -- proves the canvas exists
        // and is a real 2d-context canvas.
        const canvas = page.locator('canvas#owa_heatmap');
        await expect(canvas).toHaveCount(1, { timeout: 20_000 });

        const has2dContext = await canvas.evaluate(
            (el) => !!(el instanceof HTMLCanvasElement && el.getContext('2d'))
        );
        expect(has2dContext).toBe(true);
    });

    test('the overlay control click handlers are wired (jQuery events)', async ({ page }) => {
        // .bind('click', ...) on .owa_overlay_control moves the "active" class to
        // the clicked control. Clicking Stop should activate it and de-activate
        // Start -- proving jQuery 3.x event binding on the overlay works.
        await expect(page.locator('#owa_overlay_stop')).toBeVisible({ timeout: 20_000 });
        await page.locator('#owa_overlay_stop').click();
        await expect(page.locator('#owa_overlay_stop')).toHaveClass(/active/);
        await expect(page.locator('#owa_overlay_start')).not.toHaveClass(/active/);
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
