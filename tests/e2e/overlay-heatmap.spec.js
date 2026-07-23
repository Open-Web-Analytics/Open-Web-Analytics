/**
 * Overlay (heatmap) real-render characterization -- Phase 3.2 safety net.
 *
 * WHY THIS EXISTS
 * The heatmap/player overlay is the single largest untested jQuery-dependent
 * surface in OWA (Heatmap.js + Player.js, ~950 lines, dozens of jQuery calls).
 * It runs on the *tracker* code path, which is already on jQuery 3.x. Before
 * Phase 3.2 starts swapping the vendored reporting plugins and flipping the
 * reporting bundle's jQuery core, this test pins the fact that jQuery 3.x can
 * still build the overlay DOM at runtime.
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
 * subsequent click-data fetch is an async JSONP call against live data; it is
 * not part of the "does jQuery 3.x still build the overlay" contract and is not
 * asserted (it simply never calls back in this harness).
 */

const { test, expect } = require('@playwright/test');

// The harness page, served from under the OWA webroot (tests/ is web-servable).
// baseURL in playwright.config.js points at <install>/index.php; strip the
// index.php entry script to reach the install root that serves the tree.
function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

test.describe('heatmap overlay renders on the tracker path (jQuery 3.x)', () => {
    let pageErrors;

    test.beforeEach(async ({ page }, testInfo) => {
        pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(err.message));

        const root = installRoot(testInfo.project.use.baseURL);
        const harness = root + 'tests/e2e/overlay_harness.html'
            + '?base=' + encodeURIComponent(root);

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
});
