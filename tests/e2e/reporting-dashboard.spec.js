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

    test('jqGrid header columns align with their data columns', async ({ page }) => {
        // Regression guard (Phase 3.2): the jqGrid 3.6.5 -> free-jqgrid swap left
        // the combined stylesheet's table-layout:auto rules in place. free-jqgrid
        // sizes columns from a hidden template row and needs table-layout:fixed;
        // under auto the header and body tables each sized to their own content,
        // so every column's header cell rendered at a different width than its
        // data cells. Assert they line up (within a sub-pixel rounding tolerance).
        const geom = await page.evaluate(() => {
            const grid = document.querySelector('.ui-jqgrid');
            if (!grid) return null;
            const round = (els) => [...els].map((c) => Math.round(c.getBoundingClientRect().width));
            const hcells = round(grid.querySelectorAll('.ui-jqgrid-htable th'));
            const brow = grid.querySelector('.ui-jqgrid-btable tr.jqgrow');
            const bcells = brow ? round(brow.children) : [];
            return { hcells, bcells };
        });
        expect(geom).not.toBeNull();
        expect(geom.hcells.length).toBeGreaterThan(0);
        expect(geom.bcells.length).toBe(geom.hcells.length);
        geom.hcells.forEach((hw, i) => {
            // 1px tolerance for sub-pixel layout rounding between the two tables.
            expect(Math.abs(hw - geom.bcells[i])).toBeLessThanOrEqual(1);
        });
    });

    test('jqGrid tables do not overflow their scroll container', async ({ page }) => {
        // Regression guard (Phase 3.2): free-jqgrid sizes the grid tables to fill
        // their scroll containers (.ui-jqgrid-bdiv / -hdiv) exactly, but the tables
        // inherited the browser-default border-collapse:separate; border-spacing:2px.
        // Fixed layout then adds (columns + 1) * 2px past the container, so every
        // grid overflowed by a few pixels and showed a small horizontal scrollbar.
        // Collapsing border-spacing to 0 removes it. Assert no grid's body or header
        // div scrolls horizontally.
        const overflow = await page.evaluate(() => {
            return [...document.querySelectorAll('.ui-jqgrid')].map((grid) => {
                const bdiv = grid.querySelector('.ui-jqgrid-bdiv');
                const hdiv = grid.querySelector('.ui-jqgrid-hdiv');
                return {
                    bdiv: bdiv ? bdiv.scrollWidth - bdiv.clientWidth : 0,
                    hdiv: hdiv ? hdiv.scrollWidth - hdiv.clientWidth : 0,
                };
            });
        });
        expect(overflow.length).toBeGreaterThan(0);
        overflow.forEach((o) => {
            expect(o.bdiv).toBeLessThanOrEqual(0);
            expect(o.hdiv).toBeLessThanOrEqual(0);
        });
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

    test('Flot pie is drawn centered, not collapsed into a corner wedge', async ({ page }) => {
        // Regression guard (Phase 3.2): jQuery 3.x returns undefined (not null)
        // from $().width() on an EMPTY set, so the Flot pie plugin's legend-width
        // read became undefined and centerLeft went NaN -- translating the pie
        // origin to (0,0) and drawing each pie as a quarter wedge in the top-left
        // corner. Sample the base canvas: a correctly centered pie has painted
        // (non-transparent) pixels at its center; a corner wedge leaves the center
        // empty. Also require the corner itself NOT be the only painted region.
        const probe = await page.evaluate(() => {
            const c = document.querySelector('.owa_pieChart canvas.base');
            if (!c) return null;
            const ctx = c.getContext('2d');
            const at = (x, y) => ctx.getImageData(x, y, 1, 1).data[3]; // alpha
            const cx = Math.floor(c.width / 2), cy = Math.floor(c.height / 2);
            return {
                center: at(cx, cy),
                // A ring of points around the center; a full pie paints most of them.
                ring: [
                    at(cx + 30, cy), at(cx - 30, cy),
                    at(cx, cy + 30), at(cx, cy - 30),
                ],
            };
        });
        expect(probe).not.toBeNull();
        // Pie fills its own center.
        expect(probe.center).toBeGreaterThan(0);
        // And extends outward around it (not a corner sliver).
        expect(probe.ring.filter((a) => a > 0).length).toBeGreaterThanOrEqual(3);
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
