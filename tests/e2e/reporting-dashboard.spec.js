const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openDashboard, openReport } = require('./fixtures');

/**
 * Real-browser characterization of the reporting UI.
 *
 * jsdom (tests/js/reporting/*) proves the bundle loads and OWA objects
 * construct; it CANNOT paint. These tests drive headless Chromium against a
 * live, logged-in dashboard backed by the deterministic fixtures seeded by
 * tests/e2e/seed_reporting_fixtures.php, and pin the *rendered* output of the
 * three vendored jQuery plugins:
 *
 *   - Flot        -> <canvas> chart tiles
 *   - jqGrid      -> .ui-jqgrid tables with data rows
 *   - chosen      -> .chzn-container enhanced select menus
 *
 * They also PIN jQuery 3.6.0 -- the reporting bundle is on 3.x (was 1.6.4).
 * jquery-migrate bridges the 1.x API removals; the legacy plugins
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

    /**
     * Two pies the same width draw the same size.
     *
     * flot's pie radius defaults to 'auto', which means "as large as fits once
     * the labels are placed" -- so the pie shrank as its labels got longer.
     * Visitor Types has two short slices and Traffic Sources five plus an
     * "others", and the two dashboard widgets are both a quarter of the row,
     * so identical containers drew visibly different pies.
     *
     * Measured from the CANVAS, not the container: the containers were always
     * the same size, which is exactly why this looked like a styling problem
     * and was not one.
     */
    test('the two dashboard pies draw at the same size', async ({ page }) => {
        const pies = page.locator('.owa_pieChart canvas');

        await expect.poll(async () => pies.count(), { timeout: 20_000 })
            .toBeGreaterThanOrEqual(2);

        const drawn = await pies.evaluateAll((els) => els.map((c) => {
            const ctx = c.getContext('2d');
            const mid = Math.floor(c.height / 2);
            const row = ctx.getImageData(0, mid, c.width, 1).data;

            let first = -1, last = -1;

            for (let x = 0; x < c.width; x++) {
                if (row[x * 4 + 3] > 10) { if (first < 0) first = x; last = x; }
            }

            return { canvas: c.width, drawn: first < 0 ? 0 : last - first + 1 };
        }));

        // flot builds a base canvas and an empty overlay per plot; the overlay
        // has nothing drawn on it, so only the painted ones are pies.
        const widths = drawn.filter((d) => d.drawn > 10);

        expect(widths.length).toBe(2);

        // The same, and actually drawn -- a pair of zeroes would be "equal" too.
        expect(widths[0].drawn).toBe(widths[1].drawn);
        expect(widths[0].drawn).toBeGreaterThan(50);

        // ...and both are comfortably inside their canvas, which is what a
        // fixed radius under 1 buys: room for the labels flot draws at the edge.
        for (const w of widths) {
            expect(w.drawn).toBeLessThan(w.canvas);
        }
    });

    /**
     * A grid does not offer a picker for a column nobody can see.
     *
     * Top Content is grouped by pageTitle AND pagePath, with pagePath in
     * excludeColumns -- it is there only so the rows can link to the page
     * detail report. The bar drew a picker for it all the same, so a grid
     * showing one column offered two pickers and the second named a column
     * that is not in the table.
     */
    test('a grid with a hidden dimension shows one picker and a plus', async ({ page }) => {
        const bars = await page.locator('.owa_reportGridItem').evaluateAll((els) => els.map((e) => ({
            title: e.querySelector('.owa_reportSectionHeader')?.textContent?.trim(),
            slots: e.querySelectorAll('.owa_dimSlot').length,
            add: e.querySelectorAll('.owa_dimAdd').length,
        })).filter((r) => r.slots || r.add));

        const topContent = bars.find((b) => b.title === 'Top Content');

        expect(topContent).toBeTruthy();
        expect(topContent.slots).toBe(1);
        expect(topContent.add).toBe(1);
    });

    /**
     * The empty pill beside every report heading.
     *
     * View::get() answers `false` for a key nobody set, so the title-count
     * guard -- `!== null && !== ''` -- was true on every report in the install,
     * and out() prints false as nothing. Every report grew an empty grey pill.
     * The roster, which sets a real count, still has one.
     */
    test('a report with no count has no count pill', async ({ page }) => {
        await expect(page.locator('.owa_titleCount')).toHaveCount(0);
    });

    /*
     * ------------------------------------------------------------------
     * A trend can chart a metric SET
     * ------------------------------------------------------------------
     *
     * It could not before, and not only because nothing asked it to: the data
     * array was declared once OUTSIDE the per-series loop, so every series
     * pushed into the same array and every entry in the series list referenced
     * that one object. Two metrics drew two identical lines, each holding both
     * metrics' points end to end. Every shipped trend charts one metric, so
     * nothing ever exercised it.
     */
    test('a trend charts one line per metric, with a total in front', async ({ page }) => {
        // Re-plot the dashboard's trend with three metrics, through the same
        // call the template makes.
        await page.evaluate(() => window.siteTrend.makeAreaChart([
            { x: 'date', y: 'visits' },
            { x: 'date', y: 'pageViews' },
            { x: 'date', y: 'uniqueVisitors' },
        ], 'trend-chart'));

        const labels = page.locator('#trend-chart > .owa_chartLegend .legendLabel');

        await expect(labels).toHaveCount(4);

        // The synthetic total is FIRST, so it reads before the parts it sums.
        await expect(labels.nth(0)).toHaveText('Total');
        await expect(labels.nth(1)).toHaveText('Visits');

        const state = await page.evaluate(() => {
            const ac = window.siteTrend.areaChart;
            return {
                colors: ac.plotted.map((s) => s.color),
                lengths: ac.plotted.map((s) => s.data.length),
                firstPoints: ac.plotted.map((s) => s.data[0][1]),
            };
        });

        // A colour each, all different -- the point of one line per metric.
        expect(new Set(state.colors).size).toBe(4);

        // Every series is the same length, and none is the concatenation of
        // the others: that is exactly what the shared-array bug produced.
        expect(new Set(state.lengths).size).toBe(1);

        // The total really is the sum of the three at each point -- and every
        // point is a NUMBER. A result set carries metric values as strings, so
        // this used to add up to "121" rather than to 4.
        const [total, a, b, c] = state.firstPoints;

        for (const v of state.firstPoints) { expect(typeof v).toBe('number'); }

        expect(total).toBe(a + b + c);
    });

    /**
     * Clicking a legend entry brings that line forward.
     *
     * Asserted on what was handed to flot rather than on pixels: the dimming IS
     * the colour, since flot has no per-series opacity to set after the fact.
     */
    test('selecting a series in the legend dims the others', async ({ page }) => {
        await page.evaluate(() => window.siteTrend.makeAreaChart([
            { x: 'date', y: 'visits' },
            { x: 'date', y: 'pageViews' },
        ], 'trend-chart'));

        const labels = page.locator('#trend-chart > .owa_chartLegend .legendLabel');

        await expect(labels).toHaveCount(3);

        // Nothing selected to begin with: every line at full strength.
        expect(await page.evaluate(() => window.siteTrend.areaChart.plotted
            .every((s) => s.color.indexOf('rgba') === -1))).toBe(true);

        await labels.nth(1).click();

        const after = await page.evaluate(() => ({
            selected: window.siteTrend.areaChart.selected,
            colors: window.siteTrend.areaChart.plotted.map((s) => s.color),
        }));

        // The one clicked, by its own index -- not by its row. The legend is
        // one column per series so it reads left to right, which puts every
        // entry in a single <tr>; reading the row gave 0 for all of them.
        expect(after.selected).toBe(1);

        expect(after.colors[1].indexOf('rgba')).toBe(-1);
        expect(after.colors[0]).toContain('rgba');
        expect(after.colors[2]).toContain('rgba');
        expect(after.colors[0]).toContain('0.5');

        // The labels say the same thing the lines do.
        await expect(labels.nth(1)).toHaveClass(/owa_seriesSelected/);
        await expect(labels.nth(0)).toHaveClass(/owa_seriesDimmed/);

        // Clicking it again puts everything back, so there is always a way out.
        await labels.nth(1).click();

        expect(await page.evaluate(() => window.siteTrend.areaChart.selected)).toBeNull();
    });

    /**
     * The legend sits UNDER the chart, not floating on top of it.
     *
     * flot draws its own legend inside the plot area, over the data it is
     * labelling -- survivable for one entry, useless for five.
     */
    test('the trend legend is below the plot', async ({ page }) => {
        await page.evaluate(() => window.siteTrend.makeAreaChart([
            { x: 'date', y: 'visits' },
            { x: 'date', y: 'pageViews' },
        ], 'trend-chart'));

        const box = await page.evaluate(() => {
            const legend = document.querySelector('#trend-chart > .owa_chartLegend');
            const plot = document.querySelector('#trend-chart > .owa_areaChart');
            return {
                legendTop: legend.getBoundingClientRect().top,
                plotBottom: plot.getBoundingClientRect().bottom,
                insidePlot: !!plot.querySelector('.legend'),
            };
        });

        expect(box.legendTop).toBeGreaterThanOrEqual(box.plotBottom);
        expect(box.insidePlot).toBe(false);
    });

    /**
     * A single-metric trend is untouched.
     *
     * Sixty-one shipped trends chart one metric and are drawn as a filled area;
     * that fill is what makes it read as an area chart. Several translucent
     * fills stacked on each other muddy every colour underneath, so the fill is
     * dropped when there is more than one line -- and must not be dropped when
     * there is one.
     */
    test('a single-metric trend is still a filled area chart', async ({ page }) => {
        await page.evaluate(() => window.siteTrend.makeAreaChart(
            [{ x: 'date', y: 'visits' }], 'trend-chart'));

        const state = await page.evaluate(() => ({
            series: window.siteTrend.areaChart.plotted.length,
            fill: window.siteTrend.areaChart.flotOptions.series.lines.fill,
            interactive: !!document.querySelector('#trend-chart > .owa_chartLegendInteractive'),
        }));

        expect(state.series).toBe(1);
        expect(state.fill).toBe(true);

        // ...and no legend interaction: one line is always the selected one, so
        // a control that can only turn itself off is worse than none.
        expect(state.interactive).toBe(false);
    });

    test('the reporting bundle initializes jQuery 3.6.0 and the OWA namespace', async ({ page }) => {
        const jqv = await page.evaluate(() => window.jQuery && window.jQuery.fn.jquery);
        const owaType = await page.evaluate(() => typeof window.OWA);
        // Every reporting plugin is jQuery-3.x-clean
        // (jQuery-UI 1.13.3, Flot 0.8.3, ...), so the
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

        /*
         * A VISIBLE one, not the first one.
         *
         * Some of these are the constraint builder's dimension picker, which
         * lives inside a collapsed .builder panel and is hidden until the
         * Filter control opens it -- so whether .first() happens to be visible
         * depends on which widget the dashboard draws first. It stopped being
         * visible when Latest Visits moved to the top: it groups by seven
         * dimensions, which is past the dimension control's cap, so its only
         * chosen is the hidden one in its filter.
         *
         * What this test is actually about is that chosen ran at all, and one
         * visible enhanced control says that without depending on the layout.
         */
        await expect(chosen.locator('visible=true').first()).toBeVisible();
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

    test('the secondary-dimension picker is styled (chosen render regressions)', async ({ page }) => {
        // The data-table's "Secondary Dimension" control (OWA.dimensionPicker ->
        // <select.dim-list> enhanced by chosen) rendered as an unstyled text list
        // after the chosen 0.9.6 -> 1.8.7 migration (build-artifact CSS clobber).
        // Guard the RENDER: sprite-backed trigger arrow + a grouped, styled
        // .chosen-drop (not a flat unstyled <select> fallback). The FUNCTIONAL
        // behavior (pick a dimension -> grid requeries correctly) is asserted in
        // the next test.
        const picker = page.locator('[id$="_grid_secondDimensionChooser"] .chosen-container').first();
        await expect(picker).toBeVisible();

        const arrowBg = await picker.locator('.chosen-single div b').evaluate(
            (b) => getComputedStyle(b).backgroundImage
        );
        expect(arrowBg).toContain('chosen-sprite');

        await picker.click();
        const drop = picker.locator('.chosen-drop');
        await expect(drop).toBeVisible();
        expect(await drop.locator('.chosen-results li.group-result').count()).toBeGreaterThanOrEqual(1);
    });

    test('jqGrid renders the seeded page-title rows', async ({ page }) => {
        // At least one grid with exactly the seeded rows. The "top pages" grid
        // has one row per seeded page title.
        const grids = page.locator('.ui-jqgrid');
        expect(await grids.count()).toBeGreaterThanOrEqual(1);

        /*
         * Scoped to #top-pages, not the whole page.
         *
         * Counting every tr.jqgrow on the dashboard only ever matched the
         * seeded page count because the OTHER grids had nothing to draw --
         * the fixture attributed no traffic, so #top-referers took the
         * explorer's empty branch. Now that it has a referral to show, a
         * page-wide count measures both grids and this read 5 for 4 pages.
         */
        const rows = page.locator('#top-pages tr.jqgrow');
        expect(await rows.count()).toBe(FIXTURE.expectedGridRows);

        // The seeded titles must appear in the rendered grid text.
        const gridText = await page.evaluate(() =>
            [...document.querySelectorAll('#top-pages tr.jqgrow')]
                .map((r) => r.innerText.replace(/\s+/g, ' ').trim())
                .join('\n')
        );
        for (const title of FIXTURE.pageTitles) {
            expect(gridText).toContain(title);
        }
        // Each seeded page got exactly 2 pageviews; the count must render.
        expect(gridText).toMatch(/\b2\b/);
    });

    /**
     * The referrers grid, which had nothing to draw until the fixture
     * attributed its traffic.
     *
     * One row: the grid constrains to medium==referral, and of the four seeded
     * visits exactly one is a referral -- the other three are two organic
     * searches and a direct. So the count is the constraint working.
     *
     * It shows the referring URL. It used to show referralPageTitle and hide the
     * url, but that title was only ever filled by fetching the referring page,
     * which OWA no longer does -- so every row read '(not set)', including the
     * link text. The column and its dimension are kept and simply stop being
     * populated; nothing about the schema changed.
     */
    test('jqGrid renders the seeded referring site', async ({ page }) => {
        await expect(page.locator('#top-referers tr.jqgrow'))
            .toHaveCount(1, { timeout: 20_000 });

        await expect(page.locator('#top-referers'))
            .toContainText(FIXTURE.traffic.refererHost);

        await expect(page.locator('#top-referers'), 'the unfillable title is still shown')
            .not.toContainText('(not set)');
    });

    test('jqGrid header columns align with their data columns', async ({ page }) => {
        // Regression guard: the jqGrid 3.6.5 -> free-jqgrid swap left
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
        // Regression guard: free-jqgrid sizes the grid tables to fill
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
        // these from 0.7's bare "base"/"overlay".)
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
        // hand-patched Flot 0.7's pie with `|| 0`; jquery.flot 0.8.3 ships that
        // guard upstream, so this now pins the 0.8.3 behavior.
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
        // It must render as a clean On/Off SWITCH, not radios: 1.13's checkboxradio
        // defaults to icon:true (a radio-dot span); the source pre-enhances with
        // icon:false so no .ui-checkboxradio-icon is present. See the report-page
        // 'Live View toggle renders as a switch' test for the full rationale.
        expect(await group.first().locator('.ui-checkboxradio-icon').count()).toBe(0);
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

    test("the filter builder's dimension picker opens at full width with a dimension list", async ({ page }) => {
        // Regression guard (chosen 0.9.6 -> 1.8.7): the filter/constraint builder's
        // dimension picker (OWA.dimensionPicker -> <select.dim-list> enhanced by
        // chosen, INSIDE each constraint row) rendered as a ~2px-wide sliver -- the
        // dimension list was there but unusable. Root cause: the builder creates its
        // rows while its .builder is display:none, and chosen-js 1.x sizes its
        // container from the <select>'s offsetWidth AT ENHANCEMENT TIME (0 when
        // hidden). chosen 0.9.x read the CSS width, so this only broke on the upgrade.
        // Fix: pass an explicit width:'150px' to .chosen(). Unlike the secondary-
        // dimension picker (created visible), THIS one is created hidden -- so it is
        // the specific case that regressed. Assert it opens at a real, usable width.
        const builder = page.locator('.constraintPickerContainer').first();
        await builder.locator('> .toggle-button').click();
        await expect(builder.locator('> .builder')).toBeVisible();

        const dimChosen = builder.locator('.constraintDimensionPicker .chosen-container').first();
        await expect(dimChosen).toBeVisible();
        // The enhanced container must be a real width, not the collapsed sliver.
        const contWidth = await dimChosen.evaluate((el) => Math.round(el.getBoundingClientRect().width));
        expect(contWidth).toBeGreaterThanOrEqual(100);

        // Open the dropdown and assert the dimension list is present, grouped, and
        // painted at the same usable width (chosen sizes .chosen-drop off the container).
        await dimChosen.locator('.chosen-single').click();
        const drop = dimChosen.locator('.chosen-drop');
        await expect(drop).toBeVisible();
        const dropWidth = await drop.evaluate((el) => Math.round(el.getBoundingClientRect().width));
        expect(dropWidth).toBeGreaterThanOrEqual(100);
        expect(await drop.locator('.chosen-results li.active-result').count()).toBeGreaterThanOrEqual(2);
        expect(await drop.locator('.chosen-results li.group-result').count()).toBeGreaterThanOrEqual(1);
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

    test('server-rendered images resolve to public/, not the denied module tree', async ({ page }) => {
        // Regression: makeImageLink() (owa_template.php) emits the <img> src for
        // server-rendered images (the header logo, browser/referral/document icons,
        // etc.). It must resolve against images_url (the public/ asset tree the build
        // copies base/i/ into), NOT modules_url -- the deny-all .htaccess blocks the
        // whole modules/ tree, so a modules/base/i/... src is a broken (403) image.
        // The header logo is rendered via makeImageLink on every admin page, so it is
        // a reliable anchor. NOTE: probing the static URLs directly (access-hardening
        // spec) does NOT catch this -- only asserting the emitted src does.
        const logo = page.locator('.owa_logo img').first();
        await expect(logo).toBeVisible();

        const src = await logo.getAttribute('src');
        expect(src).toContain('/public/');
        expect(src).not.toMatch(/\/modules\//);

        // And it must actually load, not 403 under the deny-all.
        const resp = await page.request.get(src);
        expect(resp.status()).toBeLessThan(400);
    });

    test('the dashboard loads without uncaught page errors', async ({ page }) => {
        // Captured across the whole beforeEach + assertions lifecycle.
        expect(page.__owaErrors).toEqual([]);
    });
});

/**
 * Dimension report page (Browser Types, base.reportBrowsers) characterization.
 *
 * Two things only exist here, not on the dashboard:
 *
 *  1. The jQuery-UI `tabs` widget (owa.report.createTabs -> #report-tabs.ui-tabs).
 *     The 1.8.12 -> 1.13.3 upgrade migrated tabs({show:fn}) to the `activate`
 *     event (the old `show` option was removed in 1.9) plus an explicit initial
 *     selectTab() call (activate does not fire on init the way show did), so we
 *     pin the rendered tab UI.
 *  2. A deterministic single-row grid: all 8 seeded pageviews use a Chrome UA, so
 *     the Browser Types grid is exactly ONE "Chrome" row. That determinism is what
 *     lets the FUNCTIONAL secondary-dimension / filter tests below assert real
 *     requery outcomes (split by date -> 4 rows; filter Firefox -> 0 rows) rather
 *     than just DOM shape. The dashboard's top-pages grid (4 rows, pageTitle) has
 *     no such single-value column to discriminate on.
 *
 * Browser Types is the simplest such page (metrics/dimension are hard-coded in the
 * controller; needs only siteId + period).
 */
test.describe('dimension report: tabs, secondary dimension + filter (post-1.13 upgrade)', () => {

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

    test('selecting a secondary dimension requeries and splits the grid by that dimension', async ({ page }) => {
        // FUNCTIONAL test (not just "a column appeared"): the Browser Types report
        // has ONE row for the fixture data -- every seeded pageview is Chrome
        // (seeded UA), so the grid is a single "Chrome" row. The seeder spreads
        // those views across FIVE distinct days (day_ago 23/16/9/4/2, see
        // seed_reporting_fixtures.php -- the day_ago 4 visit is the one that walks
        // the goal funnel in order), so adding "Date" as the secondary dimension
        // must requery (owa.resultSetExplorer.changeDimension -> getNewResultSet
        // with owa_dimensions=browserType,date) and split the one Chrome row into
        // exactly FIVE rows (Chrome x each day), each carrying a rendered Date value.
        // This pins the real outcome: the right dimension is added AND the server
        // returns the correctly grouped result set -- catching a break anywhere in
        // pick -> event -> URL rewrite -> requery -> re-render, not just DOM width.

        // Baseline: single Chrome row, no Date column.
        await expect(page.locator('tr.jqgrow')).toHaveCount(1);
        const headsBefore = await page.locator('.ui-jqgrid-htable th').allInnerTexts();
        expect(headsBefore.map((h) => h.trim())).not.toContain('Date');

        // Grouped by exactly one dimension to begin with, so the split below is
        // the plus doing something rather than a second picker already present.
        await expect(page.locator('.owa_dimSlot')).toHaveCount(1);

        /*
         * ADD a dimension, through the real chosen click path.
         *
         * The bar has one picker per dimension now, so the FIRST one holds
         * browserType and choosing in it would REPLACE rather than add. The
         * plus is what adds, which is the action this test is about -- it
         * appends an empty picker, and choosing in that one splits the grid.
         */
        await page.locator('.owa_dimAdd').first().click();

        const picker = page.locator('[id$="_grid_secondDimensionChooser"] .chosen-container').last();
        await picker.click();
        await picker.locator('.chosen-results li.active-result', { hasText: /^Date$/ }).first().click();

        // The requery must add a "Date" column and split into one row per seeded day.
        await expect
            .poll(async () => (await page.locator('.ui-jqgrid-htable th').allInnerTexts()).map((h) => h.trim()),
                { timeout: 15_000 })
            .toContain('Date');
        await expect(page.locator('tr.jqgrow')).toHaveCount(5);

        // Added, not swapped: Browser Type is still a column.
        expect((await page.locator('.ui-jqgrid-htable th').allInnerTexts()).map((h) => h.trim()))
            .toContain('Browser Type');

        // Every row is still a Chrome row and now carries a YYYYMMDD date value,
        // and the dates are DISTINCT -- i.e. the grid really grouped by date.
        const rowText = await page.locator('tr.jqgrow').allInnerTexts();
        expect(rowText.every((t) => t.includes('Chrome'))).toBe(true);
        const dates = rowText.map((t) => (t.match(/\b(20\d{6})\b/) || [])[1]).filter(Boolean);
        expect(dates).toHaveLength(5);
        expect(new Set(dates).size).toBe(5);
    });

    test('applying a filter constraint requeries and filters the grid result set', async ({ page }) => {
        // FUNCTIONAL test of the whole filter feature end-to-end: pick a dimension
        // in the constraint builder, choose an operator, type a value, click Apply,
        // and prove the grid actually requeried with that constraint
        // (OWA.constraintBuilder -> constraint_change -> resultSetExplorer
        // .changeConstraints -> getNewResultSet with owa_constraints=...).
        // Deterministic against the fixture: the Browser Types grid is a single
        // "Chrome" row (all seeded pageviews use a Chrome UA). So:
        //   - browserType contains "Firefox" matches NOTHING  -> grid empties (0 rows)
        //   - browserType contains "Chrome"  matches the row  -> grid keeps 1 row
        // Asserting BOTH directions proves the filter genuinely discriminates on the
        // constraint, not merely that Apply clears/reloads the grid.
        const builder = page.locator('.constraintPickerContainer').first();
        // Reveal the builder (toggle only when it's currently hidden -- a requery
        // may leave it open, and a blind toggle would hide it again).
        const openBuilder = async () => {
            const panel = builder.locator('> .builder');
            if (!(await panel.isVisible())) {
                await builder.locator('> .toggle-button').click();
            }
            await expect(panel).toBeVisible();
        };
        await openBuilder();
        await expect(page.locator('tr.jqgrow')).toHaveCount(1);

        // Fill the single default constraint row: browserType =@ <value>.
        const setConstraint = async (value) => {
            await page.evaluate((val) => {
                const row = document.querySelector('.constraintPickerContainer .builder li.constraintRow');
                // dimension picker is a chosen widget -> set the <select> + resync
                jQuery(row).find('.constraintDimensionPicker select.dim-list')
                    .val('browserType').trigger('chosen:updated');
                // operator is a jQuery-UI selectmenu kept in sync via the native <select>
                jQuery(row).find('.constraintOperatorPicker select.operator-list').val('=@');
                jQuery(row).find('.constraintValueField').val(val);
            }, value);
            await builder.locator('.apply-button').click();
        };

        // Non-matching value -> the grid must empty out.
        await setConstraint('Firefox');
        await expect(page.locator('tr.jqgrow')).toHaveCount(0, { timeout: 15_000 });

        // Matching value -> the Chrome row must come back (proves it filtered on the
        // constraint, not just wiped the grid). Re-open the builder (rebuilt on reload).
        await openBuilder();
        await setConstraint('Chrome');
        await expect(page.locator('tr.jqgrow')).toHaveCount(1, { timeout: 15_000 });
        await expect(page.locator('tr.jqgrow').first()).toContainText('Chrome');
    });

    test('the Live View toggle renders as a switch, not radio buttons', async ({ page }) => {
        // Regression guard (jQuery-UI 1.8.12 -> 1.13.3): the "Live View" On/Off
        // control (owa.report.showAutoRefreshControl) is a controlgroup of two
        // radios enhanced into a two-segment button switch. 1.8.12's buttonset()
        // produced clean segments; 1.13's controlgroup enhances the radios via
        // checkboxradio, which DEFAULTS to icon:true and prepends a blank radio-dot
        // span (.ui-checkboxradio-icon) to each label -- so the switch rendered WITH
        // radio dots (looked like plain radio buttons). Fix pre-enhances the radios
        // with checkboxradio({icon:false}) before controlgroup(). Assert the switch
        // shape (2 enhanced .ui-button segments, native radios visually hidden) AND
        // that the radio-dot icon is gone.
        const control = page.locator('.autoRefreshControl').first();
        await expect(control).toBeVisible();

        // Two segments, both real jQuery-UI buttons inside a controlgroup.
        await expect(control.locator('.buttons.ui-controlgroup')).toBeVisible();
        expect(await control.locator('label.ui-button').count()).toBe(2);

        // The native radios are enhanced + visually hidden (accessibility-hidden,
        // 1px clipped) -- not shown as bare radios.
        const radio = control.locator('input[type=radio]').first();
        await expect(radio).toHaveClass(/ui-helper-hidden-accessible/);
        const radioW = await radio.evaluate((el) => Math.round(el.getBoundingClientRect().width));
        expect(radioW).toBeLessThanOrEqual(2);

        // The regression signature: NO checkboxradio radio-dot icon on the labels.
        expect(await control.locator('.ui-checkboxradio-icon').count()).toBe(0);
    });

    test('turning Live View on polls for fresh data and off stops it', async ({ page }) => {
        // FUNCTIONAL test of what the switch is FOR: flipping it On must start the
        // report auto-refresh (owa.report.startAutoRefresh -> each active-tab
        // resultSetExplorer.enableAutoRefresh -> setInterval(getNewResultSet)), which
        // re-queries the REST reports API on a timer; flipping it Off must clear the
        // timers so polling stops. We shorten the per-explorer interval, then COUNT
        // real network hits to the reports API (do=reports json) in each state:
        //   Off (baseline) -> no polling; On -> repeated polls; Off again -> stops.
        //
        // Both spellings are matched. The reporting bundle builds this URL from
        // the app namespace, which is empty, so it reads 'do=reports' -- but an
        // older cached bundle still sends 'owa_do='. Matching only the prefixed
        // form made this count ZERO polls and read as 'Live View is broken'.
        const isPoll = (u) => u.includes('/api/index.php') && /[?&](owa_)?do=reports/.test(u);
        const polls = [];
        page.on('request', (r) => { if (isPoll(r.url())) polls.push(r.url()); });

        // Shorten the auto-refresh interval on the active tab's explorers so the test
        // observes several polls quickly instead of waiting the 10s default.
        await page.evaluate(() => {
            let rep = null;
            for (const k in OWA.items) {
                const it = OWA.items[k];
                if (it && it.tabs && it.activeTab) { rep = it; break; }
            }
            const tab = rep.tabs[rep.activeTab];
            for (const n in tab.resultSetExplorers) {
                tab.resultSetExplorers[n].autoRefreshInterval = 600;
            }
        });

        // Baseline: switch defaults to Off -> no polling happens on its own.
        const b0 = polls.length;
        await page.waitForTimeout(1500);
        expect(polls.length - b0).toBe(0);

        // On -> polling starts (both active-tab explorers re-query on the timer).
        await page.locator('label[for=autorefresh-on-button]').first().click();
        const bOn = polls.length;
        await page.waitForTimeout(2000);
        expect(polls.length - bOn).toBeGreaterThanOrEqual(2);

        // Off -> timers cleared, polling stops. Let any in-flight interval settle
        // first, then assert no further polls arrive.
        await page.locator('label[for=autorefresh-off-button]').first().click();
        await page.waitForTimeout(400);
        const bOff = polls.length;
        await page.waitForTimeout(1800);
        expect(polls.length - bOff).toBe(0);
    });

    test('the dimension report loads without uncaught page errors', async ({ page }) => {
        expect(page.__owaErrors).toEqual([]);
    });
});
