const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openDashboard, openReport } = require('./fixtures');

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
 * 1.6.4 to 3.x. jquery-migrate bridges the 1.x API removals; the legacy plugins
 * were replaced with jQuery-3.x-clean versions (jqGrid -> free-jqgrid, Flot ->
 * 0.8.3, jQuery-UI -> 1.13.3), so the interim $.browser compat shim was deleted.
 * A future version bump fails this assertion and must be a conscious, reviewed
 * change (same discipline as the bundle-integrity test).
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
        // Post-migration baseline (Phase 3.2). Every reporting plugin is now
        // jQuery-3.x-clean (jQuery-UI 1.13.3, Flot 0.8.3, ...), so the
        // $.browser/$.curCSS compat shim was deleted -- jquery-migrate alone bridges.
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

    test('chosen widgets are actually STYLED (stylesheet matches the markup)', async ({ page }) => {
        // Regression guard for a build-artifact clobber that shipped a WORKING but
        // completely UNSTYLED control. chosen-js 1.8.7's JS emits .chosen-* markup,
        // but the SOURCE chosen.css was left as the 0.9.6 .chzn-* stylesheet when the
        // JS was swapped (commit 36fb9d0b hand-edited only the built CSS artifact).
        // The next `cli.php cmd=build` regenerated the artifact from the stale source,
        // so the served CSS had ZERO .chosen-* rules -- the widget rendered as a bare
        // unstyled text list with no dropdown arrow. Existence/visibility (the test
        // above) still PASSED because the markup was correct; only the applied CSS was
        // wrong. So assert the stylesheet actually takes effect: the sprite-driven
        // dropdown arrow paints a background image (the single clearest "the .chosen-*
        // CSS is present and its sprite resolves" signal).
        const arrowBg = await page.evaluate(() => {
            const b = document.querySelector('.chosen-container .chosen-single div b');
            return b ? getComputedStyle(b).backgroundImage : null;
        });
        expect(arrowBg).not.toBeNull();
        expect(arrowBg).toContain('chosen-sprite');
    });

    test('the secondary-dimension picker enhances, opens, and drives the grid', async ({ page }) => {
        // The data-table's "Secondary Dimension" control (OWA.dimensionPicker ->
        // <select.dim-list> enhanced by chosen) is a tricky compound widget the
        // chosen 0.9.6 -> 1.8.7 migration put at risk on two axes:
        //   (1) STYLING -- same build-artifact clobber as above; the picker rendered
        //       as an unstyled text list. Assert the opened .chosen-drop is styled
        //       (grouped, sprite-backed search box) and the trigger carries the arrow.
        //   (2) BEHAVIOR -- generateDimList() sets a pre-selected value with
        //       .trigger('liszt:updated'), chosen 0.9.x's "re-sync widget to <select>"
        //       event. chosen-js 1.x renamed it to 'chosen:updated' and ignores the
        //       old name, so a pre-selected dimension silently failed to render.
        //       Exercise the live path: open the widget and pick the first dimension,
        //       which must reload the grid with an extra column.
        const picker = page.locator('[id$="_grid_secondDimensionChooser"] .chosen-container').first();
        await expect(picker).toBeVisible();

        // The trigger's dropdown arrow is sprite-backed (styling present).
        const arrowBg = await picker.locator('.chosen-single div b').evaluate(
            (b) => getComputedStyle(b).backgroundImage
        );
        expect(arrowBg).toContain('chosen-sprite');

        // Column count before selecting a secondary dimension.
        const colsBefore = await page.locator('.ui-jqgrid-htable th[id]').count();

        // Open the widget and pick the first real dimension by clicking (the real
        // user path through chosen's own change handler).
        await picker.click();
        const drop = picker.locator('.chosen-drop');
        await expect(drop).toBeVisible();
        // The results are grouped (bold group headers) -- proves the .chosen-* CSS
        // structure rendered, not a flat unstyled <select> fallback.
        expect(await drop.locator('.chosen-results li.group-result').count()).toBeGreaterThanOrEqual(1);

        const firstResult = drop.locator('.chosen-results li.active-result').first();
        await expect(firstResult).toBeVisible();
        await firstResult.click();

        // Selecting a secondary dimension adds it as a grid column; wait for the
        // reloaded grid to widen. (Guards the liszt:updated -> chosen:updated fix
        // AND the change -> changeDimension -> getNewResultSet wiring.)
        await expect
            .poll(() => page.locator('.ui-jqgrid-htable th[id]').count(), { timeout: 15_000 })
            .toBeGreaterThan(colsBefore);
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
        // Flot draws each chart as a <canvas class="flot-base"> plus a
        // "flot-overlay" sibling inside an OWA chart container. (Flot 0.8 renamed
        // these from 0.7's bare "base"/"overlay" -- Phase 3.2 Flot 0.7 -> 0.8.3.)
        // Assert the area chart and at least one pie chart painted (base+overlay
        // pair each).
        const areaCanvases = page.locator('.owa_areaChart canvas');
        const pieCanvases = page.locator('.owa_pieChart canvas');
        expect(await areaCanvases.count()).toBeGreaterThanOrEqual(2);
        expect(await pieCanvases.count()).toBeGreaterThanOrEqual(2);

        // A painted Flot canvas has non-zero pixel dimensions.
        const dims = await page.evaluate(() => {
            const c = document.querySelector('.owa_areaChart canvas.flot-base');
            return c ? { w: c.width, h: c.height } : null;
        });
        expect(dims).not.toBeNull();
        expect(dims.w).toBeGreaterThan(0);
        expect(dims.h).toBeGreaterThan(0);
    });

    test('Flot pie is drawn centered, not collapsed into a corner wedge', async ({ page }) => {
        // Regression guard: jQuery 3.x returns undefined (not null) from $().width()
        // on an EMPTY set, so a Flot pie plugin that reads the legend width without
        // a guard gets undefined -> centerLeft NaN -> the pie origin translates to
        // (0,0) and each pie draws as a quarter wedge in the top-left corner. OWA
        // hand-patched Flot 0.7's pie with `|| 0`; jquery.flot 0.8.3 (Phase 3.2 Flot
        // upgrade) ships that guard upstream, so this now pins the 0.8.3 behavior.
        // Sample the base canvas: a correctly centered pie has painted
        // (non-transparent) pixels at its center; a corner wedge leaves the center
        // empty. Also require the corner itself NOT be the only painted region.
        const probe = await page.evaluate(() => {
            const c = document.querySelector('.owa_pieChart canvas.flot-base');
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
        // Pins the jQuery-UI 1.13.3 widget surface so a regression in
        // datepicker/button (the widgets OWA actually uses on the dashboard) fails
        // loudly. The date range control is two jQuery-UI datepickers; OWA also
        // enhances several .ui-button controls (paging, filter builder).
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

    test('jQuery-UI controlgroup enhances the auto-refresh control', async ({ page }) => {
        // owa.report.createAutoRefreshControl() wraps the On/Off radios in
        // `.autoRefreshControl > .buttons` and enhances them. The 1.8.12 -> 1.13.3
        // upgrade replaced the deprecated buttonset() with controlgroup(): the
        // container becomes .ui-controlgroup and each radio is enhanced (via
        // checkboxradio) into a .ui-button. Pin the post-upgrade DOM.
        const group = page.locator('.autoRefreshControl .buttons.ui-controlgroup');
        await expect(group.first()).toBeVisible();
        expect(await group.locator('.ui-button').count()).toBeGreaterThanOrEqual(2);
    });

    test('the filter/constraint builder opens with button + selectmenu widgets', async ({ page }) => {
        // Guard for the RISKIEST widgets: the result-set explorer's constraint
        // builder uses jQuery-UI button() plus selectmenu. The 1.8.12 -> 1.13.3
        // upgrade replaced the vendored ui.selectmenu (Nagel fork) with CORE
        // jQuery-UI selectmenu (bundled since 1.11). The builder is built hidden and
        // revealed by a .toggle-button; open it and assert both widget types
        // rendered so a selectmenu regression can't slip by.
        // (The .constraintPickerContainer itself collapses to height 0 -- its
        // .builder child is display:none until toggled -- so anchor on the visible
        // toggle-button, not the container.)
        const builder = page.locator('.constraintPickerContainer').first();
        const toggle = builder.locator('> .toggle-button');
        await expect(toggle).toBeVisible();

        // The toggle button is a jQuery-UI button; clicking it reveals .builder.
        await expect(toggle).toHaveClass(/ui-button/);
        await toggle.click();
        await expect(builder.locator('> .builder')).toBeVisible();

        // Add / Apply are jQuery-UI buttons inside the revealed builder.
        await expect(builder.locator('.add-button.ui-button')).toBeVisible();
        await expect(builder.locator('.apply-button.ui-button')).toBeVisible();

        // Each constraint row's <select.operator-list> is enhanced by CORE
        // selectmenu, which inserts a span.ui-selectmenu-button as its trigger and
        // hides the native <select>. At least one row exists by default.
        expect(await page.locator('span.ui-selectmenu-button').count()).toBeGreaterThanOrEqual(1);
        // The underlying native select is hidden once selectmenu takes over.
        const selectDisplay = await page.evaluate(() => {
            const s = document.querySelector('select.operator-list');
            return s ? getComputedStyle(s).display : null;
        });
        expect(selectDisplay).toBe('none');
    });

    test('selectmenu keeps the native select in sync with the selected operator', async ({ page }) => {
        // Runtime guard (not just render): the constraint-apply path reads the
        // chosen operator (owa.resultSetExplorer.js ~1638). The old Nagel fork used
        // .selectmenu('value'); core jQuery-UI selectmenu has no such method and
        // instead keeps the native <select> in sync, so the code now reads .val().
        // Pin that contract: the select carries a non-empty value.
        await page.locator('.constraintPickerContainer .toggle-button').first().click();
        const value = await page.evaluate(() => {
            const sel = jQuery('select.operator-list').first();
            return sel.length ? sel.val() : null;
        });
        // Default selection is the first operator ("==" Exactly Matching).
        expect(value).not.toBeNull();
        expect(typeof value).toBe('string');
        expect(value.length).toBeGreaterThan(0);
    });

    test('the dashboard loads without uncaught page errors', async ({ page }) => {
        // Captured across the whole beforeEach + assertions lifecycle.
        expect(page.__owaErrors).toEqual([]);
    });
});

/**
 * The jQuery-UI `tabs` widget does NOT render on the dashboard -- it only appears
 * on dimension report pages (owa.report.createTabs -> #report-tabs.ui-tabs). The
 * 1.8.12 -> 1.13.3 upgrade migrated tabs({show:fn}) to the `activate` event (the
 * old `show` option was removed in 1.9) plus an explicit initial selectTab() call
 * (activate does not fire on init the way show did), so pin the rendered tab UI.
 * Browser Types (base.reportBrowsers) is the simplest such page (metrics/dimension
 * are hard-coded in the controller; needs only siteId + period).
 */
test.describe('dimension report tabs render (post-1.13 upgrade)', () => {

    test.beforeEach(async ({ page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        page.__owaErrors = errors;
        await login(page);
        await openReport(page);
    });

    test('jQuery-UI tabs build the tabbed report layout', async ({ page }) => {
        // openReport already waited for #report-tabs.ui-tabs, so the widget built.
        // Assert the full tab structure: the widget container, the generated nav
        // list with at least one labeled tab, and the active tab panel.
        const tabs = page.locator('#report-tabs.ui-tabs');
        await expect(tabs).toBeVisible();

        const navTabs = tabs.locator('.report-tabs-nav-list li a');
        expect(await navTabs.count()).toBeGreaterThanOrEqual(1);
        // Tab labels come from OWA.report.tab config; the first is non-empty.
        expect((await navTabs.first().innerText()).trim().length).toBeGreaterThan(0);

        // The active tab's panel is shown and carries the grid (createTabs' initial
        // selectTab() calls tab.load(), which builds a result-set explorer grid).
        await expect(page.locator('tr.jqgrow').first()).toBeVisible();
    });

    test('the dimension report loads without uncaught page errors', async ({ page }) => {
        expect(page.__owaErrors).toEqual([]);
    });
});
