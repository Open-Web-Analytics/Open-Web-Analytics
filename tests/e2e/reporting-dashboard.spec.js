const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openDashboard, openReport } = require('./fixtures');

/**
 * The seeded custom report of metric boxes with no chart above them, found by
 * name on the roster: its id is minted at seed time.
 */
async function boxesReport(page) {

    await page.goto(`?owa_do=base.customReports&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

    const href = await page
        .locator(`table.owa_customReportRoster a:text-is("${FIXTURE.boxesReportName}")`)
        .first().getAttribute('href');

    const id = ((href || '').match(/reportId=(custom-[0-9]+)/) || [])[1];

    expect(id, 'the standalone-boxes fixture is missing -- re-run the seeder').toBeTruthy();

    return `?owa_do=base.report&owa_reportId=${id}&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`;
}

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
     * Under a full-width Site Metrics: Events, Visitor Types and Channels a third
     * each on one row, then Top Content, Top Referrers and Most Recent Visits a
     * third each on the next. Measured at 1280, wide enough that a third is not
     * promoted to a half.
     */
    test('the dashboard rows are three cards and then three', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await expect(page.locator('#event-names .owa_gridTable')).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('#top-pages .owa_gridTable')).toBeVisible();

        const box = async (id) => page.locator(`#${id}`).locator('xpath=..').boundingBox();
        const rows = [
            ['event-names', 'visitor-types', 'visitor-mediums'],
            ['top-pages', 'top-referers', 'latest-visits'],
        ];
        const tops = [];
        for (const ids of rows) {
            const boxes = await Promise.all(ids.map(box));
            for (let i = 1; i < boxes.length; i++) {
                expect(Math.abs(boxes[i].y - boxes[0].y), `${ids[i]} shares a row with ${ids[0]}`).toBeLessThan(2);
                expect(boxes[i].x, `${ids[i]} sits right of ${ids[i - 1]}`).toBeGreaterThan(boxes[i - 1].x);
                expect(Math.abs(boxes[i].width - boxes[0].width), `${ids[i]} is as wide as ${ids[0]}`).toBeLessThan(2);
            }
            tops.push(boxes[0]);
        }
        expect(tops[1].y, 'Top Content is on the row after Events').toBeGreaterThan(tops[0].y + tops[0].height - 2);
        expect(Math.abs(tops[1].width - tops[0].width), 'both rows are thirds').toBeLessThan(2);
    });

    /**
     * Most Recent Visits: a card of visits by their start, newest first, each
     * with its page views. The seeded visits start at noon on their day, so a
     * row here is a day's visits; on real traffic it is one visit.
     */
    test('most recent visits lists visits by start, the newest first', async ({ page }) => {
        const card = page.locator('#latest-visits');

        await expect(card.locator('tr.owa_gridRow').first()).toBeVisible({ timeout: 20_000 });
        await expect(card.locator('xpath=..').locator('.owa_reportSectionTitle')).toHaveText('Most Recent Visits');

        const head = await card.locator('.owa_gridTable thead th').allTextContents();
        expect(head.map((h) => h.trim())).toEqual(['Visit Start', 'Page Views']);

        const starts = await card.locator('tr.owa_gridRow td:first-child').allTextContents();
        expect(starts.length).toBeGreaterThan(1);

        // "Nov 10, 12:00 PM", with the year only when it is not this one. No
        // "(not set)" row: events that carry no session start are left out.
        for (const s of starts) {
            expect(s.trim()).toMatch(/^[A-Z][a-z]{2} \d{1,2}, (\d{4}, )?\d{1,2}:\d{2} [AP]M$/);
        }

        const when = starts.map((s) => {
            const t = s.trim();
            return Date.parse(/, \d{4}, /.test(t) ? t.replace(/, (\d{1,2}:)/, ' $1') : t.replace(/^(\w+ \d+),/, `$1, ${new Date().getFullYear()}`));
        });
        expect(when.every((t) => !Number.isNaN(t)), JSON.stringify(starts)).toBe(true);

        for (let i = 1; i < when.length; i++) {
            expect(when[i], `${starts[i]} is not after ${starts[i - 1]}`).toBeLessThanOrEqual(when[i - 1]);
        }
    });

    /** Its more link is Latest Visits: the same visits, newest first, with their details. */
    test('most recent visits opens latest visits in the same order', async ({ page }) => {
        const card = page.locator('#latest-visits');

        await expect(card.locator('tr.owa_gridRow').first()).toBeVisible({ timeout: 20_000 });
        const first = (await card.locator('tr.owa_gridRow td:first-child').first().textContent()).trim();

        const more = card.locator('xpath=..').locator('.owa_moreLinks a');
        await expect(more).toHaveCount(1);
        expect(await more.getAttribute('href')).toContain('reportId=latest-visits');

        await page.goto(await more.getAttribute('href'), { waitUntil: 'networkidle' });

        const grid = page.locator('#dimension-grid');
        await expect(grid.locator('tr.owa_gridRow').first()).toBeVisible({ timeout: 20_000 });
        // Its first column after the row number.
        const head = (await grid.locator('.owa_gridTable thead th').allTextContents()).map((h) => h.trim());
        const col = head.indexOf('Visit Start') + 1;
        expect(head.filter((h) => h !== '' && h !== 'Row')[0], JSON.stringify(head)).toBe('Visit Start');

        const cells = grid.locator(`tr.owa_gridRow td:nth-child(${col})`);
        expect((await cells.first().textContent()).trim()).toBe(first);
        await expect(cells.filter({ hasText: '(not set)' })).toHaveCount(0);
    });

    /**
     * The Events card counts events by name with a link to the Events report.
     */
    test('the events card counts events by name and links to the events report', async ({ page }) => {
        const names = page.locator('#event-names');

        await expect(names.locator('.owa_gridTable')).toBeVisible({ timeout: 20_000 });

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
     * Visitor Types and Channels are share charts: each value's share of
     * sessions as a ranked row, its percentage printed and a bar under it
     * against the largest. They were pies, drawn by flot onto a canvas.
     */
    test('the dashboard share charts are ranked bars with percentages', async ({ page }) => {
        for (const id of ['#visitor-types', '#visitor-mediums']) {
            const chart = page.locator(`${id} .owa_shareChart`);

            await expect(chart).toBeVisible({ timeout: 20_000 });
            await expect(page.locator(`${id} canvas`)).toHaveCount(0);

            const rows = await chart.locator('.owa_shareRow').evaluateAll((lis) => lis.map((li) => ({
                label: li.querySelector('.owa_shareLabel').textContent,
                share: parseInt(li.querySelector('.owa_shareValue').textContent, 10),
                text: li.querySelector('.owa_shareValue').textContent,
                bar: parseFloat(li.querySelector('.owa_gridBarFill').style.width),
            })));

            expect(rows.length).toBeGreaterThan(1);
            rows.forEach((r) => expect(r.text).toMatch(/^(<1|\d+)%$/));

            // Ranked, the first bar the width of the row and the rest shorter.
            expect(rows[0].bar).toBe(100);
            for (let i = 1; i < rows.length; i++) {
                expect(rows[i].share).toBeLessThanOrEqual(rows[i - 1].share);
                expect(rows[i].bar).toBeLessThanOrEqual(rows[i - 1].bar);
            }

            // Every row says what it is.
            rows.forEach((r) => expect(r.label.trim()).not.toBe(''));
        }

        await expect(page.locator('#visitor-mediums .owa_shareChart')).toContainText('Organic Search');
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
     * The dashboard's trend is a card: one series over date alone, drawn as a
     * line. A card's chart is the shape behind its figures; a trend's is the
     * filled area (the Browser Types trend below).
     */
    test('a trend card over date alone is a single line, not filled', async ({ page }) => {
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
        expect(state.fill).toBe(false);

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
         * ...and a widget of boxes with no chart above them is untouched. No
         * shipped report has one any more, so it is the seeded custom report's.
         */
        await page.goto(await boxesReport(page), { waitUntil: 'networkidle' });

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
     * A trend's lines take their colours from OWA.chartColors, ten distinct
     * colours, so a value has one colour wherever a line draws it.
     */
    test('the trend draws from the shared palette', async ({ page }) => {
        const shared = await page.evaluate(() => ({
            palette: window.OWA.chartColors,
            trend: window.siteTrend.areaChart.options.colors,
        }));

        expect(shared.palette.length).toBeGreaterThanOrEqual(10);
        expect(new Set(shared.palette).size).toBe(shared.palette.length);
        expect(shared.trend).toEqual(shared.palette);
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
         * By PAGE, not by title. Top Content is a grid-card grouped by
         * pagePathPlusQuery -- one metric against one dimension, which is what
         * a card is -- where it used to be a grid grouped by pageTitle with the
         * path carried alongside it and hidden. The fixture's pages carry no
         * query string, so each reads as its path. The cell also holds the
         * row's Actions button, which is not the value.
         */
        const cells = await page.evaluate(() =>
            [...document.querySelectorAll('#top-pages tr.owa_gridRow td:first-child')]
                .map((c) => {
                    const v = c.cloneNode(true);
                    v.querySelectorAll('.owa_gridRowActions').forEach((b) => b.remove());
                    return v.innerText.trim();
                })
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
        // Assert the area chart painted (a base+overlay pair).
        const areaCanvases = page.locator('.owa_areaChart canvas');
        expect(await areaCanvases.count()).toBeGreaterThanOrEqual(2);

        // A painted Flot canvas has non-zero pixel dimensions.
        const dims = await page.evaluate(() => {
            const c = document.querySelector('.owa_areaChart canvas.flot-base');
            return c ? { w: c.width, h: c.height } : null;
        });
        expect(dims).not.toBeNull();
        expect(dims.w).toBeGreaterThan(0);
        expect(dims.h).toBeGreaterThan(0);
    });

    /**
     * A trend of one day is drawn by hour, on a 12-hour clock, and today's
     * stops at the hour it is now. Today because the seeded visits are on
     * other days: an empty day is still drawn over its hours, as zeroes.
     */
    test('a one-day trend is drawn by hour', async ({ page }) => {
        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}&owa_period=today`,
            { waitUntil: 'networkidle' });

        await expect.poll(async () => page.evaluate(
            () => !!(window.siteTrend && window.siteTrend.areaChart && window.siteTrend.areaChart.dataseries)),
            { timeout: 20_000 }).toBe(true);

        const drawn = await page.evaluate(() => {
            const chart = window.siteTrend.areaChart;
            const xs = chart.dataseries[0].data.map((p) => p[0]);
            return {
                url: decodeURIComponent(window.siteTrend.resultSet.self),
                x: chart.xDimension,
                hours: xs.map((t) => new Date(t).getUTCHours()),
                now: String(window.siteTrend.resultSet.timePeriod.currentHour),
                labels: Array.from(document.querySelectorAll('#trend-chart .flot-x-axis .flot-tick-label'))
                    .map((l) => l.textContent.trim()),
                options: Array.from(document.querySelectorAll('#trend-chart .owa_chartGranularity option'))
                    .map((o) => o.value),
            };
        });

        expect(drawn.x).toBe('dateHour');
        expect(drawn.url).toContain('dimensions=dateHour');
        expect(drawn.url).toContain('sort=dateHour');

        // Midnight to the hour it is now, one point each.
        const nowHour = Number(drawn.now.substring(8, 10));
        expect(drawn.hours).toEqual(Array.from({ length: nowHour + 1 }, (_, h) => h));

        expect(drawn.labels[0]).toBe('12am');
        expect(drawn.labels.every((l) => /^\d{1,2}(am|pm)$/.test(l))).toBe(true);

        expect(drawn.options).toEqual(['dateHour', 'date', 'yearMonth']);
    });

    test('sparkline paints kpi-box canvases', async ({ page }) => {
        // OWA renders each sparkline into <p class="sparkline">; jquery-sparkline
        // 2.4.0 (the jQuery-3.x-clean replacement for the vendored 1.2.1) draws a
        // <canvas> inside it. Assert at least one sparkline painted a canvas.
        //
        // NOT on the dashboard any more. Its only boxes are the ones under the
        // trend, and those deliberately draw no sparkline -- the chart above
        // them already shows the shape over time. No shipped report has boxes
        // without a chart, so the library is pinned on the seeded custom
        // report's.
        await page.goto(await boxesReport(page), { waitUntil: 'networkidle' });

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

    test('the period picker opens with the presets and a calendar', async ({ page }) => {
        // The period picker is OWA's own (owa.periodpicker.js), handed its
        // presets by the server. Open it and check both halves drew.
        await page.locator('.owa_periodTrigger').click();
        const panel = page.locator('.owa_periodPanel');
        await expect(panel).toBeVisible();
        expect(await panel.locator('.owa_periodList button[data-act="preset"]').count()).toBeGreaterThanOrEqual(13);
        expect(await panel.locator('.owa_periodDay').count()).toBeGreaterThan(27);
    });

    test('a preset applies through the URL and the picker reopens on it', async ({ page }) => {
        await page.locator('.owa_periodTrigger').click();
        await page.locator('.owa_periodList button', { hasText: 'Last 28 days' }).click();
        await page.locator('.owa_periodFoot [data-act="apply"]').click();

        await page.waitForURL(/period=last_28_days/);
        await expect(page.locator('.owa_periodBadge')).toHaveText('Last 28 days');
    });

    test('Compare adds a comparison to the URL and to the button', async ({ page }) => {
        await page.locator('.owa_periodTrigger').click();
        await page.locator('.owa_periodLeft .owa_periodSwitch').click();
        await page.locator('.owa_periodList .owa_periodCmpSelect').selectOption('previous_year');
        await page.locator('.owa_periodFoot [data-act="apply"]').click();

        await page.waitForURL(/compare=previous_year/);
        await expect(page.locator('.owa_periodTriggerCompare')).toContainText('Compare:');
    });

    test('an old link to a retired period opens on the default period', async ({ page }) => {
        const url = new URL(page.url());
        url.searchParams.set('owa_period', 'same_month_last_year');
        await page.goto(url.toString(), { waitUntil: 'networkidle' });

        await expect(page.locator('.owa_periodTrigger')).toBeVisible();
        await expect(page.locator('.owa_periodBadge')).not.toHaveCount(0);
    });

    test('a compared report puts the change under each metric box and a second line on the trend', async ({ page }) => {
        const url = new URL(page.url());
        url.searchParams.set('owa_compare', 'previous_period');
        await page.goto(url.toString(), { waitUntil: 'networkidle' });

        const lines = page.locator('.owa_metricInfoboxCompare');
        await expect(lines.first()).toBeVisible();
        // The change alone: the dates are in its title.
        await expect(lines.first()).toHaveText(/^(▲ |▼ )?\d+\.\d%$|^—$/);
        await expect(lines.first()).toHaveAttribute('title', /\d{4}: /);
        expect(await lines.count()).toEqual(await page.locator('.owa_metricInfobox').count());

        // The compared line is the same metric, so it is no entry in the legend.
        await expect(page.locator('.owa_chartLegend').first()).not.toContainText('Compared');
        // A key names the two periods instead: the period solid, the comparison dashed.
        await expect(page.locator('.owa_chartKey .owa_chartKeyPeriod').first()).toBeVisible();
        // Named as the picker names them; the dates are in the title.
        await expect(page.locator('.owa_chartKey .owa_chartKeyCompare').first()).toHaveText('Previous period');
        await expect(page.locator('.owa_chartKey .owa_chartKeyCompare').first()).toHaveAttribute('title', /\d{4}$/);

        // A label that wraps does not push its number below the rest of the row.
        const row = page.locator('.metricInfoboxesContainer').first();
        await row.locator('.owa_metricInfobox').first().evaluate((box) => {
            box.style.flex = '0 0 120px';
            box.style.minWidth = '0';
            box.querySelector('.owa_metricInfoboxLabel').textContent = 'A metric name long enough to wrap';
        });
        const tops = await row.locator('.owa_metricInfobox').evaluateAll((boxes) => {
            const firstTop = boxes[0].getBoundingClientRect().top;
            return boxes
                .filter((b) => Math.abs(b.getBoundingClientRect().top - firstTop) < 2)   // the first line of boxes
                .map((b) => Math.round(b.querySelector('.owa_metricInfoboxLargeNumber').getBoundingClientRect().top));
        });
        expect(tops.length).toBeGreaterThan(1);
        expect(new Set(tops).size).toBe(1);
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

    test('the trend draws a line for each of the top rows of the grid', async ({ page }) => {
        // Every seeded page view is Chrome: the grid's one row, and the chart's one line over the total.
        const legend = page.locator('.owa_chartLegend').first();
        await expect(legend).toContainText('Total');
        await expect(legend).toContainText('Chrome');

        // A trend, not a card: its total is a filled area under the lines.
        const fill = await page.evaluate(() => {
            const trend = Object.values(window).find((o) => o && o.areaChart && o.areaChart.dom_id === 'site_usage_trend-chart');
            return trend.areaChart.dataseries[0].lines.fill;
        });
        expect(fill).toBe(true);

        // A title over it says so, with the day/month control across from it.
        const head = page.locator('.owa_chartHead').first();
        await expect(head.locator('.owa_chartTitle')).toHaveText(/^.+ by Browser over time$/);
        await expect(head.locator('.owa_chartGranularity')).toBeVisible();
        expect((await page.locator('.owa_areaChart').first().boundingBox()).height).toBeGreaterThanOrEqual(250);
    });

    test('the charted metric and the grid\'s sort are one choice', async ({ page }) => {
        const boxes = page.locator('.owa_metricInfobox');
        const grid = page.locator('.owa_gridTable').first();
        const metrics = await grid.locator('thead th.owa_gridMetric').evaluateAll((ths) => ths.map((th) => th.dataset.column));
        // A box the grid also has a column for, and not the one charted now.
        const pick = await boxes.evaluateAll((els, cols) => {
            const el = els.find((e) => !e.classList.contains('owa_metricInfoboxCharted') && cols.includes(e.dataset.metric));
            return el ? el.dataset.metric : null;
        }, metrics);
        expect(pick, 'a second metric that is both a box and a grid column').toBeTruthy();

        // A box sorts the grid by its metric, largest first.
        await page.locator(`.owa_metricInfobox[data-metric="${pick}"]`).first().click();
        await expect(grid.locator(`thead th[data-column="${pick}"]`)).toHaveAttribute('aria-sort', 'descending');

        // Sorting the grid by another metric charts that one.
        const back = metrics.find((m) => m !== pick);
        await grid.locator(`thead th[data-column="${back}"] button`).first().click();
        await expect(grid.locator(`thead th[data-column="${back}"]`)).toHaveAttribute('aria-sort', 'descending');
        await expect(page.locator(`.owa_metricInfobox[data-metric="${back}"]`).first()).toHaveClass(/owa_metricInfoboxCharted/);
    });

    test('anywhere over the plot, the nearest day is active and the tooltip follows the pointer', async ({ page }) => {
        const area = page.locator('.owa_areaChart').first();
        await expect(page.locator('.owa_chartLegend').first()).toContainText('Chrome');
        const box = await area.boundingBox();

        // High in the plot, away from every line.
        await page.mouse.move(box.x + box.width * 0.5, box.y + 20);
        const tip = page.locator('#tooltip');
        await expect(tip).toBeVisible();
        await expect(tip.locator('.owa_chartTip tr').first()).toContainText('Total');
        const first = await tip.boundingBox();
        const active = () => tip.locator('.owa_chartTipHead').textContent();
        const dayA = await active();

        // Further along: the tooltip moved with the pointer and names another day.
        await page.mouse.move(box.x + box.width * 0.9, box.y + 20);
        await expect.poll(active).not.toBe(dayA);
        expect((await tip.boundingBox()).x).not.toBe(first.x);

        // Out of the plot: gone.
        await page.mouse.move(box.x + box.width * 0.5, box.y - 40);
        await expect(tip).toHaveCount(0);
    });

    test('the tooltip of the first point is not under the side nav', async ({ page }) => {
        const area = page.locator('.owa_areaChart').first();
        await expect(page.locator('.owa_chartLegend').first()).toContainText('Chrome');

        const at = await area.evaluate((el) => {
            const plot = window.jQuery(el).data('plot');
            const total = plot.getData()[0];
            const first = total.data[0];
            const o = plot.pointOffset({ x: first[0], y: first[1] });
            const box = el.getBoundingClientRect();
            return { x: box.left + o.left, y: box.top + o.top };
        });
        await page.mouse.move(at.x, at.y);

        const tip = page.locator('#tooltip');
        await expect(tip).toBeVisible();
        const b = await tip.boundingBox();
        // Right of the point, so inside the chart's own column, clear of the nav to its left.
        expect(b.x).toBeGreaterThan(at.x);
        // Below it.
        expect(b.y).toBeGreaterThan(at.y);
        expect(b.x).toBeGreaterThanOrEqual((await area.boundingBox()).x);
    });

    test('compared, the tooltip of the last point stays inside the window', async ({ page }) => {
        const url = new URL(page.url());
        url.searchParams.set('owa_compare', 'previous_period');
        await page.goto(url.toString(), { waitUntil: 'networkidle' });

        const area = page.locator('.owa_areaChart').first();
        await expect(page.locator('.owa_chartLegend').first()).toContainText('Chrome');

        const at = await area.evaluate((el) => {
            const plot = window.jQuery(el).data('plot');
            const total = plot.getData().find((s) => !s.isCompare);
            const last = total.data[total.data.length - 1];
            const o = plot.pointOffset({ x: last[0], y: last[1] });
            const box = el.getBoundingClientRect();
            return { x: box.left + o.left, y: box.top + o.top };
        });
        const yMax = () => area.evaluate((el) => window.jQuery(el).data('plot').getAxes().yaxis.max);
        const before = await yMax();

        await page.mouse.move(at.x, at.y);

        const tip = page.locator('#tooltip');
        await expect(tip).toBeVisible();
        await expect(tip.locator('.owa_chartTipHead')).toContainText(' vs ');
        // Each line's value on the compared day, beside its own.
        await expect(tip.locator('tr').first().locator('td.owa_chartTipWas')).toHaveText(/^[\d,.]+$|^—$/);
        const b = await tip.boundingBox();
        const width = page.viewportSize().width;
        expect(b.x).toBeGreaterThanOrEqual(0);
        expect(b.x + b.width).toBeLessThanOrEqual(width);
        // Flipped to the pointer's left, not over it.
        expect(b.x + b.width).toBeLessThan(at.x);

        // The hovered line's comparison is drawn now; the y axis was already scaled to it.
        expect(await yMax()).toBe(before);
    });

    test('compared, each metric cell shows its change under the value', async ({ page }) => {
        const url = new URL(page.url());
        url.searchParams.set('owa_compare', 'previous_period');
        await page.goto(url.toString(), { waitUntil: 'networkidle' });

        const metricCells = page.locator('.owa_gridTable tbody td.owa_gridMetric, .owa_gridTable tbody tr:not(.owa_gridTotal) td[data-column]').filter({ has: page.locator('.owa_gridChange') });
        await expect(metricCells.first()).toBeVisible();

        const change = page.locator('.owa_gridTable .owa_gridChange').first();
        await expect(change).toHaveAttribute('title', /\d{4}: /);
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
        await expect(page.locator('.owa_gridEmpty')).toHaveText('No data matches these filters.');

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
