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
 * They also PIN jQuery 3.6.0 -- Phase 3.2 flipped the reporting bundle from
 * 1.6.4 to 3.x (jquery-migrate + a $.browser compat shim bridge the legacy
 * plugins; jqGrid was replaced by free-jqgrid). A future version bump fails
 * this assertion and must be a conscious, reviewed change (same discipline as
 * the bundle-integrity test).
 *
 * Prereq: run `php tests/e2e/seed_reporting_fixtures.php seed` first.
 */
test.describe('reporting dashboard renders (post-migration baseline)', () => {

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

    test('the reporting bundle initializes jQuery 3.6.0 and the OWA namespace', async ({ page }) => {
        const jqv = await page.evaluate(() => window.jQuery && window.jQuery.fn.jquery);
        const owaType = await page.evaluate(() => typeof window.OWA);
        // Post-migration baseline (Phase 3.2). $.browser is restored by the compat
        // shim so the legacy sparkline / jQuery-UI plugins still run.
        expect(jqv).toBe('3.6.0');
        expect(owaType).toBe('object');
    });

    test('chosen enhances the report select menus', async ({ page }) => {
        // The site filter (and period control) are <select>s upgraded by chosen
        // into .chosen-container widgets (chosen-js 1.8.7 renamed the prefix from
        // .chzn-* to .chosen-*). If chosen breaks under jQuery 3.x this count
        // drops and the menus fall back to bare <select>s.
        const chosen = page.locator('.chosen-container');
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

    test('sparkline paints kpi-box canvases', async ({ page }) => {
        // OWA renders each sparkline into <p class="sparkline">; jquery-sparkline
        // 2.4.0 (the jQuery-3.x-clean replacement for the vendored 1.2.1) draws a
        // <canvas> inside it. Assert at least one sparkline painted a canvas.
        const sparkCanvases = page.locator('p.sparkline canvas');
        expect(await sparkCanvases.count()).toBeGreaterThanOrEqual(1);

        const dims = await page.evaluate(() => {
            const c = document.querySelector('p.sparkline canvas');
            return c ? { w: c.width, h: c.height } : null;
        });
        expect(dims).not.toBeNull();
        expect(dims.w).toBeGreaterThan(0);
        expect(dims.h).toBeGreaterThan(0);
    });

    test('jQuery-UI initializes the datepicker and button widgets', async ({ page }) => {
        // Characterizes the jQuery-UI 1.8.12 widget surface BEFORE the planned
        // 1.13.x upgrade so a regression in datepicker/button (the widgets OWA
        // actually uses on the dashboard) fails loudly. The date range control
        // is two jQuery-UI datepickers; OWA also enhances several .ui-button
        // controls (paging, filter builder).
        const startInput = page.locator('#owa_report-datepicker-start');
        await expect(startInput).toHaveClass(/hasDatepicker/);
        expect(await page.locator('.ui-button').count()).toBeGreaterThanOrEqual(1);
    });

    test('jQuery-UI datepicker renders a calendar when the period control opens', async ({ page }) => {
        // Init alone can pass while the widget is broken at runtime (this is how
        // the $.curCSS regression slipped past the load probe). OWA's date range
        // is two INLINE jQuery-UI datepickers (rendered into <div> elements) that
        // live hidden inside #owa_reportPeriodFiltersContainer until the user
        // clicks the period label to reveal them. Open it and assert the calendar
        // grid actually painted -- exercising the widget's runtime render (and,
        // incidentally, the jQote2 template that builds the period control).
        await page.locator('#owa_reportPeriodLabelContainer').click();
        const startCal = page.locator('#owa_report-datepicker-start');
        await expect(startCal).toBeVisible();
        // A rendered inline datepicker has clickable day cells.
        expect(await startCal.locator('a.ui-state-default').count()).toBeGreaterThanOrEqual(1);
    });

    test('the dashboard loads without uncaught page errors', async ({ page }) => {
        // Captured across the whole beforeEach + assertions lifecycle.
        expect(page.__owaErrors).toEqual([]);
    });
});
