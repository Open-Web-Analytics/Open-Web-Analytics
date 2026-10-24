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
 *   - the grid    -> .owa_gridTable tables with data rows
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
        /*
         * The account menu, not the word "Logout".
         *
         * Logout lives inside that menu now and is hidden until it is opened,
         * so a visibility check on the text fails for a perfectly good session.
         * The toggle is a better signal anyway: it is rendered only for an
         * authenticated request.
         */
        await expect(page.locator('#owa_userMenuToggle')).toBeVisible();
        await openDashboard(page);
    });

    test('the fixture user can authenticate and reach the dashboard', async ({ page }) => {
        // openDashboard already waited for a data grid row; being here means
        // login + site-access (analyst view_reports on the fixture site) worked.
        await expect(page.locator('.owa_gridTable').first()).toBeVisible();
    });

    /**
     * The Events card comes before Top Content and beside it, under a full-width
     * Site Metrics, counting events by name with a link to the Events report.
     * Paired on one row so the three small cards after them still fill theirs
     * at the widths where a 3-column card is widened to 4.
     */
    test('the events card sits before top content and links to the events report', async ({ page }) => {
        const names = page.locator('#event-names');

        await expect(names.locator('.owa_gridTable')).toBeVisible({ timeout: 20_000 });

        const before = await names.evaluate((el) => {
            const other = document.getElementById('top-pages');
            return !!(el.compareDocumentPosition(other) & Node.DOCUMENT_POSITION_FOLLOWING);
        });
        expect(before, 'Events is placed before Top Content').toBe(true);

        const a = await names.boundingBox();
        const b = await page.locator('#top-pages').boundingBox();
        expect(Math.abs(a.y - b.y), 'Events and Top Content share a row').toBeLessThan(2);
        expect(a.x).toBeLessThan(b.x);

        if (process.env.OWA_DASH_SCREENSHOT) {
            await page.screenshot({ path: process.env.OWA_DASH_SCREENSHOT, fullPage: true });
        }

        await expect(names.locator('.owa_gridTable thead')).toContainText('Event Name');
        await expect(names.locator('.owa_gridTable tbody')).toContainText('page_view');

        await expect(names.locator('xpath=..').locator('.owa_reportSectionTitle')).toHaveText('Events');

        const more = names.locator('xpath=..').locator('.owa_moreLinks a');
        await expect(more).toHaveCount(1);
        expect(await more.getAttribute('href')).toContain('reportId=events');
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

        await page.locator('.owa_chartGranularity').selectOption('yearMonth');

        await expect.poll(async () => page.evaluate(
            () => window.siteTrend.areaChart.xDimension), { timeout: 20_000 }).toBe('yearMonth');

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

        /*
         * THE SERVER IS MADE TO ANSWER OUT OF ORDER. It answers in query order
         * now, so on its own this fixture no longer exercised kpiBox's
         * reordering at all -- the guard below said so and failed. Reversing the
         * aggregates on the way in puts the reordering back under test.
         */
        await page.route('**/api/index.php?*', async (route) => {
            const url = route.request().url();
            if (!/[?&]do=reports/.test(url) || !/[?&]metrics=/.test(url)) {
                return route.continue();
            }
            const response = await route.fetch();
            const body = await response.json();
            const aggregates = body && body.data && body.data.aggregates;
            if (aggregates && typeof aggregates === 'object') {
                body.data.aggregates = Object.fromEntries(Object.entries(aggregates).reverse());
            }
            return route.fulfill({ response, json: body });
        });

        await openDashboard(page);

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
            'the served aggregates are in query order, so the reversal above did not reach '
            + 'the widget and this test proves nothing')
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
        const rates = await ticks('engagementRate');
        expect(rates.every((t) => t.endsWith('%'))).toBe(true);

        /*
         * ...and starting at zero. flot scales to the data, which is right
         * until the data is flat: a rate of zero all month gave an axis
         * running -100% to 100%, because a series with no range has none to
         * scale to.
         */
        expect(rates[0]).toBe('0%');
        expect(rates.some((t) => t.startsWith('-'))).toBe(false);

        // A duration: seconds read as a duration, not as a number.
        const durations = await ticks('averageEngagementTimePerSession');
        expect(durations.every((t) => /^\d+:\d{2}(:\d{2})?$/.test(t))).toBe(true);

        /*
         * ...and a metric the widget does NOT measure is refused rather than
         * drawn. changeMetric() redraws from the result set already in hand, so
         * a name that is not in it read `.data_type` off an undefined cell and
         * threw out of the redraw -- leaving the chart blank with nothing but a
         * console line to say why.
         */
        const refused = await page.evaluate(
            () => window.siteTrend.areaChart.changeMetric('sessions'));

        expect(refused).toBe(false);

        // ...and the chart is still the one it was drawing.
        expect(await page.evaluate(() => window.siteTrend.areaChart.chartedMetric()))
            .toBe('averageEngagementTimePerSession');
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

    test('the grid renders the seeded page rows', async ({ page }) => {
        // At least one grid with exactly the seeded rows. The "top pages" grid
        // has one row per seeded page title.
        const grids = page.locator('.owa_gridTable');
        expect(await grids.count()).toBeGreaterThanOrEqual(1);

        /*
         * Scoped to #top-pages, not the whole page.
         *
         * Counting every grid row on the dashboard only ever matched the
         * seeded page count because the OTHER grids had nothing to draw --
         * the fixture attributed no traffic, so #top-referers took the
         * explorer's empty branch. Now that it has a referral to show, a
         * page-wide count measures both grids and this read 5 for 4 pages.
         */
        const rows = page.locator('#top-pages tr.owa_gridRow');
        await expect(rows).toHaveCount(FIXTURE.expectedGridRows, { timeout: 20_000 });

        /*
         * By PATH, not by title. Top Content is a grid-card grouped by pagePath
         * -- one metric against one dimension, which is what a card is -- where
         * it used to be a grid grouped by pageTitle with the path carried
         * alongside it and hidden.
         */
        const cells = await page.evaluate(() =>
            [...document.querySelectorAll('#top-pages tr.owa_gridRow td:first-child')]
                .map((c) => c.innerText.trim())
        );
        for (const path of FIXTURE.pagePaths) {
            expect(cells).toContain(path);
        }
        // ...and the metric column renders a figure beside each of them. The
        // fixture's /about has exactly 2 page views. A card shows no share, so
        // the cell is the figure alone.
        const counts = await page.evaluate(() =>
            [...document.querySelectorAll('#top-pages tr.owa_gridRow td:last-child')]
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
    test('the grid renders the seeded referring site', async ({ page }) => {
        await expect(page.locator('#top-referers tr.owa_gridRow'))
            .toHaveCount(1, { timeout: 20_000 });

        await expect(page.locator('#top-referers'))
            .toContainText(FIXTURE.traffic.refererHost);

        await expect(page.locator('#top-referers'), 'the unfillable title is still shown')
            .not.toContainText('(not set)');
    });

    test('no grid on the dashboard scrolls sideways', async ({ page }) => {
        // A dimension's value wraps rather than widening its table, so at this
        // width every grid fits its widget.
        const overflow = await page.evaluate(() =>
            [...document.querySelectorAll('.owa_gridScroll')].map((el) => el.scrollWidth - el.clientWidth));

        expect(overflow.length).toBeGreaterThan(0);
        overflow.forEach((o) => expect(o).toBeLessThanOrEqual(0));
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

    test('jQuery-UI initializes the datepicker widget', async ({ page }) => {
        // Pins the jQuery-UI 1.13.3 widget surface so a regression in the
        // datepicker fails loudly. The date range control is two jQuery-UI
        // datepickers. (The button widget is pinned on the funnel's filter.)
        const startInput = page.locator('#owa_report-datepicker-start');
        await expect(startInput).toHaveClass(/hasDatepicker/);
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

    test('a dimension heading opens the dimensions to choose from, by family', async ({ page }) => {
        const pick = page.locator('.owa_gridDimButton').first();

        await expect(pick).toHaveText('Browser');
        await pick.click();

        const pop = page.locator('.owa_gridPop');

        await expect(pop).toBeVisible();
        await expect(pick).toHaveAttribute('aria-expanded', 'true');
        await expect(pop.locator('.owa_gridPopSearch')).toBeFocused();
        expect(await pop.locator('.owa_gridPopHeading').count()).toBeGreaterThanOrEqual(2);
        await expect(pop.locator('.owa_gridPopOption.is-current')).toContainText('Browser');

        // The list is in the window, however near the foot of it the heading is.
        const box = await pop.boundingBox();
        expect(box.y + box.height).toBeLessThanOrEqual(page.viewportSize().height);

        await page.keyboard.press('Escape');
        await expect(pop).toHaveCount(0);
        await expect(pick).toBeFocused();
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
        await expect(page.locator('tr.owa_gridRow').first()).toBeVisible();
    });

    test('selecting a secondary dimension requeries and splits the grid by that dimension', async ({ page }) => {
        // FUNCTIONAL test (not just "a column appeared"): the Browser Types report
        // has ONE row for the fixture data -- every seeded pageview is Chrome
        // (seeded UA), so the grid is a single "Chrome" row. The seeder spreads
        // those views across FIVE distinct days (day_ago 23/16/9/4/2, see
        // seed_reporting_fixtures.php -- the day_ago 4 visit is the one that walks
        // the goal funnel in order), so adding "Date" as the secondary dimension
        // must requery (dimension_list_change -> setDimensionList -> getNewResultSet
        // with owa_dimensions=browserType,date) and split the one Chrome row into
        // one row per day (seven: see below), each carrying a rendered Date value.
        // This pins the real outcome: the right dimension is added AND the server
        // returns the correctly grouped result set -- catching a break anywhere in
        // pick -> event -> URL rewrite -> requery -> re-render, not just DOM width.

        // Baseline: single Chrome row, no Date column.
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1);
        const headsBefore = await page.locator('.owa_gridTable thead th').allInnerTexts();
        expect(headsBefore.map((h) => h.trim())).not.toContain('Date');

        // Grouped by exactly one dimension to begin with, so the split below is
        // the plus doing something rather than a second picker already present.
        await expect(page.locator('.owa_gridDimButton')).toHaveCount(1);

        /*
         * ADD a dimension. The heading's own picker holds browserType, and
         * choosing in it would REPLACE rather than add; the plus beside it adds.
         */
        await page.locator('.owa_gridDimAdd').first().click();
        await page.locator('.owa_gridPop .owa_gridPopOption[data-name="date"]').click();

        // The requery must add a "Date" column and split into one row per seeded day.
        await expect
            .poll(async () => (await page.locator('.owa_gridTable thead th').allInnerTexts()).map((h) => h.trim()),
                { timeout: 15_000 })
            .toContain('Date');
        /*
         * Seven, not five: the five seeded visits, and the sessions the click
         * and action fixtures run in (days 5 and 6). They have no page view, and
         * on v2 a session opens on its first event of any kind -- so they are
         * sessions, and each is Chrome on its own date.
         */
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(7);

        // Added, not swapped: Browser Type is still a column.
        expect((await page.locator('.owa_gridTable thead th').allInnerTexts()).map((h) => h.trim()))
            .toContain('Browser');

        // Every row is still a Chrome row and now carries a YYYYMMDD date value,
        // and the dates are DISTINCT -- i.e. the grid really grouped by date.
        const rowText = await page.locator('tr.owa_gridRow').allInnerTexts();
        expect(rowText.every((t) => t.includes('Chrome'))).toBe(true);
        const dates = rowText.map((t) => (t.match(/\b(20\d{6})\b/) || [])[1]).filter(Boolean);
        expect(dates).toHaveLength(7);
        expect(new Set(dates).size).toBe(7);
    });

    /*
     * The Filter button, its dialog and its chips, end to end: a filter is
     * added, the grid requeries with it (constraint_change ->
     * changeConstraints -> getNewResultSet with constraints=...), and its chip
     * takes it off again.
     *
     * Deterministic against the fixture: the Browser Types grid is a single
     * "Chrome" row, so "contains Firefox" empties it and "contains Chrome"
     * keeps it. Both directions, so the filter is shown to discriminate rather
     * than merely to reload.
     */
    const addFilter = async (page, { field = 'browserType', op, value }) => {
        await page.locator('.owa_gridFilterButton').click();

        const pop = page.locator('.owa_gridPop');

        await expect(pop).toBeVisible();
        await pop.locator('.owa_gridFilterField').selectOption(field);

        if (op) {
            await pop.locator(`.owa_gridFilterOps input[value="${op}"]`).check();
        }

        if (value !== undefined) {
            await pop.locator('.owa_gridFilterValue').fill(value);
        }

        const request = page.waitForRequest((r) => r.url().includes('constraints='));

        await pop.locator('.owa_gridPrimary').click();

        return decodeURIComponent((await request).url().replace(/\+/g, ' '));
    };

    test('a filter requeries the grid, and its chip takes it off', async ({ page }) => {
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1);

        const url = await addFilter(page, { value: 'Firefox' });

        expect(url).toContain('browserType=@Firefox');
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(0, { timeout: 15_000 });
        await expect(page.locator('.owa_gridEmpty')).toHaveText('No rows match these filters.');

        const chip = page.locator('.owa_gridChip');

        await expect(chip).toHaveCount(1);
        await expect(chip).toContainText('Browser contains Firefox');

        await chip.locator('.owa_gridChipRemove').click();
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1, { timeout: 15_000 });
        await expect(page.locator('.owa_gridChip')).toHaveCount(0);

        await addFilter(page, { value: 'Chrome' });
        await expect(page.locator('.owa_gridChip')).toContainText('Browser contains Chrome');
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1, { timeout: 15_000 });
        await expect(page.locator('tr.owa_gridRow').first()).toContainText('Chrome');
    });

    /**
     * "Has a value" / "Is not set" filter on emptiness, with no value typed.
     *
     * Every seeded row has a browser, so Is not set empties the grid and Has a
     * value keeps its one row. The requery carries `browserType==(not set)` --
     * the server reads that as "is empty", not as a comparison with the label.
     */
    test('the empty-test filters requery on whether the dimension has a value', async ({ page }) => {
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1);

        const notSet = await addFilter(page, { op: '==(not set)' });

        expect(notSet).toContain('browserType==(not set)');
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(0, { timeout: 15_000 });
        await expect(page.locator('.owa_gridChip')).toContainText('Browser is not set');

        await page.locator('.owa_gridChipRemove').click();
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1, { timeout: 15_000 });

        const isSet = await addFilter(page, { op: '!=(not set)' });

        expect(isSet).toContain('browserType!=(not set)');
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(1, { timeout: 15_000 });
    });

    test('Rows per page and the pager requery the grid', async ({ page }) => {
        // Split by date first, so there is more than a page of rows to page through.
        await page.locator('.owa_gridDimAdd').first().click();
        await page.locator('.owa_gridPop .owa_gridPopOption[data-name="date"]').click();
        await expect(page.locator('tr.owa_gridRow')).toHaveCount(7, { timeout: 15_000 });

        let request = page.waitForRequest((r) => r.url().includes('resultsPerPage=10'));
        await page.locator('.owa_gridPageSize').selectOption('10');
        await request;

        await expect(page.locator('.owa_gridRange')).toHaveText('1–7 of 7');
        await expect(page.locator('.owa_gridNext')).toBeDisabled();
        await expect(page.locator('.owa_gridPrev')).toBeDisabled();
    });

    test('the dimension report loads without uncaught page errors', async ({ page }) => {
        expect(page.__owaErrors).toEqual([]);
    });
});

/**
 * "Data as of" in the header, where Live View was.
 *
 * Live View re-polled every widget on a timer, but widgets read the reporting
 * cube, which moves only when a build runs, so it refreshed nothing. The
 * header now says when the numbers were built, for a period that reaches into
 * days a build has not settled.
 */
test.describe('report freshness', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('the header says when the numbers were built, and there is no Live View', async ({ page }) => {
        await openDashboard(page);

        const asOf = page.locator('#owa_reportAsOf');
        await expect(asOf).toBeVisible();

        // A widget's result arrived and filled the time in -- the placeholder
        // is an ellipsis, so "any text" would pass before anything came back.
        await expect(asOf).toHaveClass(/owa_reportAsOfKnown/);
        await expect(asOf.locator('.owa_reportAsOfTime')).toHaveText(/\d{4}/);
        await expect(asOf).toContainText('Data as of');

        await expect(page.locator('#liveViewSwitch, .autoRefreshControl')).toHaveCount(0);
    });

    test('a closed period has no as-of line', async ({ page }) => {
        // A fixed week long past. last_month reaches yesterday on the 1st of
        // every month, and then the line is rightly there.
        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=date_range&owa_startDate=20100104&owa_endDate=20100110',
            { waitUntil: 'networkidle' });

        await expect(page.locator('.owa_reportTitle')).toBeVisible();
        await expect(page.locator('#owa_reportAsOf')).toHaveCount(0);
    });

    test('nothing polls: a loaded report makes no further data requests', async ({ page }) => {
        const requests = [];
        page.on('request', (r) => {
            if (/[?&]do=reports(&|$)/.test(r.url())) {
                requests.push(r.url());
            }
        });

        await openDashboard(page);

        const loaded = requests.length;
        expect(loaded, 'the dashboard fetches its widgets').toBeGreaterThan(0);

        // Longer than the old Live View interval (15s) and explorer default (10s).
        await page.waitForTimeout(16_000);

        expect(requests.length, 'no widget refreshed itself').toBe(loaded);
    });
});
