const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openDashboard } = require('./fixtures');

/**
 * Phase 3.0 safety net -- real-browser characterization of the reporting UI.
 *
 * jsdom (tests/js/reporting/*) proves the bundle loads and OWA objects
 * construct; it CANNOT paint. These tests drive headless Chromium against a
 * live, logged-in dashboard backed by the deterministic fixtures seeded by
 * tests/e2e/seed_reporting_fixtures.php, and pin the *rendered* output of the
 * three vendored jQuery plugins the migration will touch:
 *
 *   - Flot        -> <canvas> chart tiles
 *   - jqGrid      -> .ui-jqgrid tables with data rows
 *   - chosen      -> .chzn-container enhanced select menus
 *
 * They also PIN jQuery 1.6.4 as the pre-migration baseline. When Phase 3.1
 * flips the reporting bundle to 3.x, the version assertion fails and must be
 * consciously updated -- turning an invisible, load-order-sensitive change
 * into an explicit, reviewed one (same discipline as the bundle-integrity test).
 *
 * Prereq: run `php tests/e2e/seed_reporting_fixtures.php seed` first.
 */
test.describe('reporting dashboard renders (pre-migration baseline)', () => {

    // One authenticated dashboard load shared by the assertions below.
    test.beforeEach(async ({ page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        // Expose the live array (same reference) so the final test can assert
        // no uncaught errors accumulated during this test's dashboard load.
        page.__owaErrors = errors;

        await login(page);
        await expect(page.locator('text=Logout').first()).toBeVisible();
        await openDashboard(page);
    });

    test('the fixture user can authenticate and reach the dashboard', async ({ page }) => {
        // openDashboard already waited for a data grid row; being here means
        // login + site-access (analyst view_reports on the fixture site) worked.
        await expect(page.locator('.ui-jqgrid').first()).toBeVisible();
    });

    test('the reporting bundle initializes jQuery 1.6.4 and the OWA namespace', async ({ page }) => {
        const jqv = await page.evaluate(() => window.jQuery && window.jQuery.fn.jquery);
        const owaType = await page.evaluate(() => typeof window.OWA);
        // Pre-migration baseline. Flip to 3.x when Phase 3.1 lands -> update then.
        expect(jqv).toBe('1.6.4');
        expect(owaType).toBe('object');
    });

    test('chosen enhances the report select menus', async ({ page }) => {
        // The site filter (and period control) are <select>s upgraded by chosen
        // into .chzn-container widgets. If chosen breaks under jQuery 3.x this
        // count drops and the menus fall back to bare <select>s.
        const chosen = page.locator('.chzn-container');
        expect(await chosen.count()).toBeGreaterThanOrEqual(1);
        await expect(chosen.first()).toBeVisible();
    });

    test('jqGrid renders the seeded page-title rows', async ({ page }) => {
        // At least one grid with exactly the seeded rows. The "top pages" grid
        // has one row per seeded page title.
        const grids = page.locator('.ui-jqgrid');
        expect(await grids.count()).toBeGreaterThanOrEqual(1);

        const rows = page.locator('tr.jqgrow');
        expect(await rows.count()).toBe(FIXTURE.expectedGridRows);

        // The seeded titles must appear in the rendered grid text.
        const gridText = await page.evaluate(() =>
            [...document.querySelectorAll('tr.jqgrow')]
                .map((r) => r.innerText.replace(/\s+/g, ' ').trim())
                .join('\n')
        );
        for (const title of FIXTURE.pageTitles) {
            expect(gridText).toContain(title);
        }
        // Each seeded page got exactly 2 pageviews; the count must render.
        expect(gridText).toMatch(/\b2\b/);
    });

    test('Flot paints the chart canvases', async ({ page }) => {
        // Flot draws each chart as a <canvas class="base"> plus a "overlay"
        // sibling inside an OWA chart container. Assert the area chart and at
        // least one pie chart painted (base+overlay pair each).
        const areaCanvases = page.locator('.owa_areaChart canvas');
        const pieCanvases = page.locator('.owa_pieChart canvas');
        expect(await areaCanvases.count()).toBeGreaterThanOrEqual(2);
        expect(await pieCanvases.count()).toBeGreaterThanOrEqual(2);

        // A painted Flot canvas has non-zero pixel dimensions.
        const dims = await page.evaluate(() => {
            const c = document.querySelector('.owa_areaChart canvas.base');
            return c ? { w: c.width, h: c.height } : null;
        });
        expect(dims).not.toBeNull();
        expect(dims.w).toBeGreaterThan(0);
        expect(dims.h).toBeGreaterThan(0);
    });

    test('the dashboard loads without uncaught page errors', async ({ page }) => {
        // Captured across the whole beforeEach + assertions lifecycle.
        expect(page.__owaErrors).toEqual([]);
    });
});
