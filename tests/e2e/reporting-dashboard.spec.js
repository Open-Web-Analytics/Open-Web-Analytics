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
     * flot fits its round-the-edge labels by SHRINKING: drawPie() returns false
     * when a label div lands outside the canvas and the caller multiplies
     * maxRadius by 0.95 and tries again. So the width of the word
     * "organic-search" set the diameter, and Traffic Sources drew smaller than
     * Visitor Types on an identical canvas.
     *
     * Fixed twice, and the first attempt is worth remembering: pinning
     * `radius: 0.72` pinned the FRACTION, and flot computes
     * `maxRadius * radius` -- the fraction was never what moved. It is a pixel
     * length now, which the loop cannot reach, and the labels are a legend
     * beside the pie rather than text around it, so there is nothing left to
     * overflow.
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

        // ...and both are comfortably inside their canvas, leaving room for the
        // legend flot places beside them.
        for (const w of widths) {
            expect(w.drawn).toBeLessThan(w.canvas);
        }

        /*
         * The labels are a LEGEND, not text around the circle. That is what
         * removes the shrink loop's cause rather than working around it.
         */
        const legends = page.locator('.owa_pieChart .legend');

        await expect(legends).toHaveCount(2);

        // ...carrying the slice name and its share, which is what the
        // round-the-edge labels said.
        await expect(legends.first()).toContainText('%');
    });

    /**
     * A grid does not offer a picker for a column nobody can see.
     *
     * Top Referrers is grouped by referralPageTitle AND referralPageUrl, with
     * the title in excludeColumns -- it is there only so the rows can carry it.
     * The bar drew a picker for it all the same, so a grid showing one column
     * offered two pickers and the second named a column that is not in the
     * table.
     *
     * This used to be asserted against Top Content, which was the same shape
     * until it became a grid-card grouped by pagePath alone. A card draws no
     * explorer bar at all, so it can no longer answer this question.
     */
    test('a grid with a hidden dimension shows one picker and a plus', async ({ page }) => {
        const bars = await page.locator('.owa_reportGridItem').evaluateAll((els) => els.map((e) => ({
            title: e.querySelector('.owa_reportSectionHeader')?.textContent?.trim(),
            slots: e.querySelectorAll('.owa_dimSlot').length,
            add: e.querySelectorAll('.owa_dimAdd').length,
        })).filter((r) => r.slots || r.add));

        const hidden = bars.find((b) => b.title === 'Top Referrers');

        expect(hidden, 'Top Referrers drew no explorer bar to count pickers on').toBeTruthy();
        expect(hidden.slots).toBe(1);
        expect(hidden.add).toBe(1);
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

    /**
     * A trend with nothing to break out is the filled area it has always been.
     *
     * Sixty-one shipped trends are grouped by date alone. The fill is what
     * makes one read as an area chart, and it is decided PER SERIES now -- so
     * the case to protect is that a lone series still gets it.
     */
    test('a trend over date alone is a single filled area', async ({ page }) => {
        const state = await page.evaluate(() => {
            const ac = window.siteTrend.areaChart;
            return {
                count: ac.dataseries.length,
                fill: !!(ac.dataseries[0].lines && ac.dataseries[0].lines.fill),
                legendShown: ac.flotOptions.legend.show,
                interactive: !!document.querySelector('#trend-chart > .owa_chartLegendInteractive'),
                everyPointNumeric: ac.dataseries[0].data.every((p) => typeof p[1] === 'number'),
            };
        });

        expect(state.count).toBe(1);
        expect(state.fill).toBe(true);

        // No legend and no legend interaction for one line: naming the only
        // series says nothing, and a control that can only turn itself off is
        // worse than none.
        expect(state.legendShown).toBe(false);
        expect(state.interactive).toBe(false);

        /*
         * Values are NUMBERS. A result set carries metric values as strings and
         * flot coerces them, so one line always drew correctly -- but the total
         * of a broken-out trend is arithmetic over these, and string addition
         * concatenates.
         */
        expect(state.everyPointNumeric).toBe(true);
    });

    /**
     * The metric boxes under a trend are how you choose what it charts.
     *
     * They already ARE the metrics the widget measures, and the chart draws one
     * of them -- so they are the list of what it could draw instead, and a
     * separate picker beside them would be a second copy of the same list.
     *
     * No refetch: the widget queried every one of these, so which is plotted is
     * a choice about data already on the page.
     */
    test('clicking a metric box charts that metric', async ({ page }) => {
        const boxes = page.locator('#siteTrend-metrics .owa_metricInfobox');

        await expect(boxes.first()).toBeVisible({ timeout: 20_000 });
        expect(await boxes.count()).toBeGreaterThan(1);

        const charted = await page.evaluate(() => window.siteTrend.areaChart.chartedMetric());

        // The one being drawn is marked, and only it.
        await expect(page.locator('#siteTrend-metrics .owa_metricInfoboxCharted')).toHaveCount(1);
        await expect(page.locator(
            `#siteTrend-metrics .owa_metricInfobox[data-metric="${charted}"]`))
            .toHaveClass(/owa_metricInfoboxCharted/);

        // Clickable, because there is more than one to choose between.
        await expect(page.locator('#siteTrend-metrics.owa_metricBoxesSelectable')).toHaveCount(1);

        const others = (await boxes.evaluateAll((els) => els.map((e) => e.getAttribute('data-metric'))))
            .filter((m) => m !== charted);

        const target = others[0];
        const urlBefore = await page.evaluate(() => window.siteTrend.resultSet.self);

        await page.locator(`#siteTrend-metrics .owa_metricInfobox[data-metric="${target}"]`).click();

        await expect.poll(async () => page.evaluate(
            () => window.siteTrend.areaChart.chartedMetric()), { timeout: 10_000 }).toBe(target);

        // The chart is drawing it, by its own label.
        const label = await page.evaluate(() => window.siteTrend.areaChart.dataseries[0].label);
        expect(label.length).toBeGreaterThan(0);
        expect(label).not.toBe('Total');

        // The marking moved with it, and did not multiply.
        await expect(page.locator('#siteTrend-metrics .owa_metricInfoboxCharted')).toHaveCount(1);
        await expect(page.locator(
            `#siteTrend-metrics .owa_metricInfobox[data-metric="${target}"]`))
            .toHaveClass(/owa_metricInfoboxCharted/);

        // ...and nothing was fetched to do it.
        expect(await page.evaluate(() => window.siteTrend.resultSet.self)).toBe(urlBefore);
    });

    /**
     * The chosen metric survives a refetch, and the boxes do not multiply.
     *
     * THE BUG THIS EXISTS FOR
     *
     * kpiBox built its container selector as `dom_id + ' > .metricInfobox...'`
     * with no leading '#', which is a valid CSS TYPE selector -- an element
     * called <siteTrend-metrics> -- so it matched nothing. The remove() before
     * each rebuild was a silent no-op and every new result set appended ANOTHER
     * full set of boxes beneath the old ones. Every granularity change, page
     * change and site change doubled them.
     *
     * It went unnoticed because nothing read the boxes; marking one of them is
     * what made two of them visible.
     */
    test('the metric boxes rebuild rather than accumulate', async ({ page }) => {
        const boxes = page.locator('#siteTrend-metrics .owa_metricInfobox');

        await expect(boxes.first()).toBeVisible({ timeout: 20_000 });

        const before = await boxes.count();

        await page.locator('.owa_chartGranularity').selectOption('month');

        await expect.poll(async () => page.evaluate(
            () => window.siteTrend.areaChart.xDimension), { timeout: 20_000 }).toBe('month');

        // The same boxes, rebuilt -- not a second set under the first.
        await expect(boxes).toHaveCount(before);
        await expect(page.locator('#siteTrend-metrics .metricInfoboxesContainer')).toHaveCount(1);

        // ...and the chart is still drawing the metric it was drawing.
        await expect(page.locator('#siteTrend-metrics .owa_metricInfoboxCharted')).toHaveCount(1);
    });

    /**
     * No sparkline in a box that sits under a chart.
     *
     * The chart above already draws the shape over time, at a size you can read
     * -- a thumbnail of it inside every box is the same information again, and
     * these boxes are the control for choosing which metric that chart draws,
     * which scans better as numbers than as a row of small graphs.
     *
     * Both sides, because "suppressed" only means something against somewhere
     * they are still drawn: a metric-boxes widget with no chart above it keeps
     * its sparklines, and that is what the inference has to get right.
     */
    test('metric boxes under a trend have no sparklines, standalone ones do',
        async ({ page }) => {

        await expect(page.locator('#siteTrend-metrics .owa_metricInfobox').first())
            .toBeVisible({ timeout: 20_000 });

        const underTrend = await page.evaluate(() => ({
            boxes: document.querySelectorAll('#siteTrend-metrics .owa_metricInfobox').length,
            sparklines: document.querySelectorAll('#siteTrend-metrics .owa_metricInfobox canvas').length,
        }));

        expect(underTrend.boxes).toBeGreaterThan(1);
        expect(underTrend.sparklines).toBe(0);

        /*
         * ...and a widget of boxes with no chart above them is untouched.
         *
         * `goals`, not `traffic`: traffic used to carry three standalone
         * metric-boxes widgets counting visits per medium, and it has three
         * PIES of the same splits now -- the number stated beside the shape of
         * it was the same fact twice. The goals report's Goal Performance
         * panel is the remaining standalone one.
         */
        await page.goto(
            `?owa_do=base.report&owa_reportId=goals&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
            { waitUntil: 'networkidle' });

        await expect(page.locator('.owa_metricInfobox').first()).toBeVisible({ timeout: 20_000 });

        const standalone = await page.evaluate(() => {
            const rows = [];

            document.querySelectorAll('.owa_reportGridItem').forEach((item) => {

                const boxes = item.querySelectorAll('.owa_metricInfobox');

                if (boxes.length && !item.querySelector('.owa_areaChart')) {
                    rows.push({
                        boxes: boxes.length,
                        sparklines: item.querySelectorAll('.owa_metricInfobox canvas').length,
                    });
                }
            });

            return rows;
        });

        expect(standalone.length).toBeGreaterThan(0);

        for (const row of standalone) {
            expect(row.sparklines).toBe(row.boxes);
        }
    });

    /**
     * Every chart that draws more than one thing draws it in the same colours.
     *
     * A report shows traffic sources as a pie and the same sources as lines
     * over time. Two palettes make the reader work out twice which colour is
     * which, when the colour existed to save them that.
     *
     * Ten, because the pie draws up to six slices from what used to be a list
     * of four -- so its fifth and sixth repeated its first and second -- and a
     * trend needs seven.
     */
    test('the pie and the trend draw from one palette', async ({ page }) => {
        const shared = await page.evaluate(() => ({
            palette: window.OWA.chartColors,
            trend: window.siteTrend.areaChart.options.colors,
        }));

        expect(shared.palette.length).toBeGreaterThanOrEqual(10);
        expect(new Set(shared.palette).size).toBe(shared.palette.length);
        expect(shared.trend).toEqual(shared.palette);

        // The pie reads the same list. Asserted on a fresh instance rather than
        // on a rendered chart, because a pie's own options are merged down onto
        // whatever the widget passed it.
        const pie = await page.evaluate(() => new window.OWA.pieChart().options.colors);

        expect(pie).toEqual(shared.palette);
    });

    /**
     * The metric boxes read in the order the QUERY asked for them.
     *
     * ORDER IS MEANING. A widget's first metric is the one its chart draws, and
     * the boxes are how a reader picks a different one -- so a row that reads
     * in a different order than the definition says makes "the first metric is
     * charted" look arbitrary rather than like a rule.
     *
     * It did read differently. kpiBox walked `resultSet.aggregates`, which is
     * keyed by the server and arrives in whatever order the reduction produced.
     * Site Metrics asked for uniqueVisitors, pageViews, bounceRate,
     * pagesPerVisit, visitDuration and drew uniqueVisitors, pageViews,
     * visitDuration, bounceRate, pagesPerVisit.
     *
     * The order is recovered from resultSet.self -- the URL that produced this
     * data carries `metrics` verbatim -- so it comes from the answer's own
     * question and cannot drift from it.
     */
    test('the metric boxes read in the order the query asked for', async ({ page }) => {

        await page.waitForSelector('.owa_trendCardMetrics .owa_metricInfobox', { timeout: 20_000 });

        const state = await page.evaluate(() => {

            const box = document.querySelector('.owa_trendCardMetrics');
            const rs  = window.siteTrend.resultSet;

            // The server's own ordering, before kpiBox reorders it.
            const served = [];

            for (const k in rs.aggregates) {
                if (Object.prototype.hasOwnProperty.call(rs.aggregates, k)) {
                    served.push(rs.aggregates[k].name);
                }
            }

            return {
                asked: new URL(rs.self).searchParams.get('metrics').split(','),
                drawn: [...box.querySelectorAll('.owa_metricInfobox')]
                    .map((b) => b.getAttribute('data-metric')),
                served,
                charted: [...box.querySelectorAll('.owa_metricInfoboxCharted')]
                    .map((b) => b.getAttribute('data-metric')),
            };
        });

        expect(state.drawn).toEqual(state.asked);

        /*
         * ...and this fixture actually EXERCISES the reordering. If the server
         * happened to answer in the requested order, the assertion above would
         * pass with kpiBox doing nothing at all -- so the case is only a case
         * while these two differ.
         */
        expect(state.served,
            'the server now answers in query order, so this test no longer proves anything -- '
            + 'pick a widget whose metrics it still reorders')
            .not.toEqual(state.asked);

        // The consequence that matters: the charted metric is the first box.
        expect(state.charted).toEqual([state.asked[0]]);
    });

    /**
     * The y axis is labelled in the units of the metric being charted.
     *
     * A bounce rate is stored 0 to 1 and money in minor units, so an axis of
     * bare numbers labelled a rate "0, 0, 0, 1" and a revenue figure in a
     * currency nobody named. The metric's data_type is what the server formats
     * its values with, so the axis is read from the same answer.
     */
    test('the y axis is labelled in the metric\'s own units', async ({ page }) => {
        const ticks = async (metric) => page.evaluate((m) => {

            window.siteTrend.areaChart.changeMetric(m);

            return [...document.querySelectorAll('#trend-chart .flot-y-axis .flot-tick-label')]
                .map((e) => e.textContent.trim());

        }, metric);

        // A count: thousands separated, no decimals.
        //
        // pageViews, not visits: Site Metrics is a trend CARD now and names its
        // own metrics, which deliberately exclude visits. A card cannot chart
        // what it does not measure.
        const counts = await ticks('pageViews');
        expect(counts.length).toBeGreaterThan(1);
        expect(counts.every((t) => /^[\d,]+$/.test(t))).toBe(true);

        // A rate: stored as a fraction, labelled as a percentage.
        const rates = await ticks('bounceRate');
        expect(rates.every((t) => t.endsWith('%'))).toBe(true);

        /*
         * ...and starting at zero. flot scales to the data, which is right
         * until the data is flat: a bounce rate of zero all month gave an axis
         * running -100% to 100%, because a series with no range has none to
         * scale to.
         */
        expect(rates[0]).toBe('0%');
        expect(rates.some((t) => t.startsWith('-'))).toBe(false);

        // A duration: seconds read as a duration, not as a number.
        const durations = await ticks('visitDuration');
        expect(durations.every((t) => /^\d+:\d{2}(:\d{2})?$/.test(t))).toBe(true);

        /*
         * ...and a metric the widget does NOT measure is refused rather than
         * drawn. changeMetric() redraws from the result set already in hand, so
         * a name that is not in it read `.data_type` off an undefined cell and
         * threw out of the redraw -- leaving the chart blank with nothing but a
         * console line to say why.
         */
        const refused = await page.evaluate(
            () => window.siteTrend.areaChart.changeMetric('visits'));

        expect(refused).toBe(false);

        // ...and the chart is still the one it was drawing.
        expect(await page.evaluate(() => window.siteTrend.areaChart.chartedMetric()))
            .toBe('visitDuration');
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

    test('jqGrid renders the seeded page rows', async ({ page }) => {
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

        /*
         * By PATH, not by title. Top Content is a grid-card grouped by pagePath
         * -- one metric against one dimension, which is what a card is -- where
         * it used to be a grid grouped by pageTitle with the path carried
         * alongside it and hidden.
         */
        const cells = await page.evaluate(() =>
            [...document.querySelectorAll('#top-pages tr.jqgrow td:first-child')]
                .map((c) => c.innerText.trim())
        );
        for (const path of FIXTURE.pagePaths) {
            expect(cells).toContain(path);
        }
        // ...and the metric column renders a figure beside each of them. The
        // fixture's /about has exactly 2 page views.
        const counts = await page.evaluate(() =>
            [...document.querySelectorAll('#top-pages tr.jqgrow td:last-child')]
                .map((c) => c.innerText.trim())
        );

        expect(counts).toHaveLength(FIXTURE.expectedGridRows);
        expect(counts.every((c) => /^\d+$/.test(c))).toBe(true);
        expect(counts).toContain('2');
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
        //
        // NOT on the dashboard any more. Its only boxes are the ones under the
        // trend, and those deliberately draw no sparkline -- the chart above
        // them already shows the shape over time. `goals` has a metric-boxes
        // widget with no chart above it, which is where sparklines still
        // belong and so where the library can still be pinned. (It was
        // `traffic`; that report's standalone boxes became pies.)
        await page.goto(
            `?owa_do=base.report&owa_reportId=goals&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
            { waitUntil: 'networkidle' });

        const sparkCanvases = page.locator('p.sparkline canvas');

        await expect(sparkCanvases.first()).toBeAttached({ timeout: 20_000 });
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

        // The toggle is an ICON, not a jQuery-UI button -- it carries no label
        // and no dropdown triangle, because it sits beside the dimension
        // pickers and a second labelled box there reads as another dimension.
        // Its font-awesome glyph is what makes it a filter.
        await expect(toggle).toHaveClass(/owa_filterToggle/);
        await expect(toggle).not.toHaveClass(/ui-button/);
        await expect(toggle.locator('i.fa-filter')).toHaveCount(1);
        await expect(toggle).toHaveText('');

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

    /**
     * The Live View switch is the same kind of control as the metric-set tabs
     * -- two segments, one of which is the one you are in -- and it sits on the
     * same reports. Its selected segment carries the same blue for the same
     * reason: jQuery-UI's base theme paints an active widget #007fff, which is
     * a different blue from the one the chart above it draws its total in.
     *
     * Asserted on the OFF segment, which is checked at render. Clicking On
     * would be the same assertion plus a polling timer.
     */
    test('the selected Live View segment carries the trend blue', async ({ page }) => {
        const control = page.locator('.autoRefreshControl').first();
        await expect(control).toBeVisible();

        const selected = control.locator('label.ui-button.ui-state-active');
        await expect(selected).toHaveCount(1);
        await expect(selected).toHaveText('Off');

        const bg = await selected.evaluate(el => getComputedStyle(el).backgroundColor);
        expect(bg).toBe('rgb(24, 116, 205)');
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
