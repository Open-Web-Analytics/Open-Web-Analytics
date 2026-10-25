const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openConfiguredReport, configuredReportIds } = require('./fixtures');

/**
 * Every configured report, loaded in a real browser.
 *
 * The rest of the suite verifies the JavaScript a report EMITS -- the
 * characterization fixtures record the query URLs, the explorer commands and
 * the load calls, and a test can read them without a browser. None of that
 * proves the emitted script RUNS.
 *
 * That distinction has already cost this project once. base.reportEcommerce
 * set sort='actions', neither a metric it queried nor a dimension it grouped
 * on; the unresolvable sort arrived as a null sortColumn, jqGrid (the grid
 * then) called .toLowerCase() on it, and the grid threw while building. The page still
 * rendered. The only trace was the browser console, which nothing was
 * watching.
 *
 * The reports converted to configuration are exactly where that class of bug
 * would land now, and until this spec existed only five of the fifty-three
 * were ever opened in a browser.
 *
 * Prereq: run `php tests/e2e/seed_reporting_fixtures.php seed` first.
 */
test.describe('every configured report renders in a browser', () => {

    /**
     * One test, not fifty-three.
     *
     * A test per report would give a nicer failure name, but it would also log
     * in fifty-three times and pay a fresh browser context for each. Sweeping
     * in one context and COLLECTING the failures gets the same information --
     * the assertion names every offending report rather than dying on the
     * first -- for one login.
     */
    /**
     * A grid does not offer a picker for a column nobody can see.
     *
     * transactions is grouped by timestamp AND transactionId with timestamp in
     * excludeColumns -- it is there only so the rows can carry it. The bar drew
     * a picker for it all the same, so a grid showing one column offered two
     * pickers and the second named a column that is not in the table.
     *
     * transactions rather than action-group, which has the same shape but takes
     * a parameter: opened with a sentinel it matches nothing and draws no grid
     * to carry a bar, so it cannot answer this either.
     *
     * This has now moved twice, both times because its subject became a
     * grid-card: first from Top Content, then from Top Referrers. A card draws
     * no explorer bar at all, so it cannot answer this question -- and the
     * dashboard no longer has a grid with a hidden dimension on it, which is why
     * this lives here with the other configured-report checks rather than in the
     * dashboard spec.
     */
    test('a grid with a hidden dimension shows one picker and a plus', async ({ page }) => {

        await login(page);
        await openConfiguredReport(page, { reportId: 'transactions' });

        const bars = await page.locator('.owa_reportGridItem').evaluateAll((els) => els.map((e) => ({
            slots: e.querySelectorAll('.owa_gridDimButton').length,
            add: e.querySelectorAll('.owa_gridDimAdd').length,
        })).filter((r) => r.slots || r.add));

        expect(bars.length, 'transactions drew no explorer bar to count pickers on')
            .toBeGreaterThan(0);

        expect(bars[0].slots).toBe(1);
        expect(bars[0].add).toBe(1);
    });

    test('no configured report raises a browser console error', async ({ page }) => {
        test.setTimeout(300_000);

        const ids = configuredReportIds();

        // 33 definitions ship on v2; the floor guards against a sweep of nothing.
        expect(ids.length, 'no report definitions were found to sweep').toBeGreaterThan(25);

        await login(page);

        const failures = {};

        for (const reportId of ids) {

            const errors = [];
            const onError = (e) => errors.push(e.message);

            page.on('pageerror', onError);

            try {
                await openConfiguredReport(page, { reportId });
            } catch (e) {
                // A timeout here means the renderer drew no widget cell at
                // all, which is a rendering failure and not a flaky wait.
                errors.push(`did not render a widget: ${e.message.split('\n')[0]}`);
            }

            page.off('pageerror', onError);

            if (errors.length) {
                failures[reportId] = errors;
            }
        }

        expect(failures).toEqual({});
    });

    /**
     * The Forms report constrains on a regular expression,
     * eventName=~^form_(start|submit)$, the only shipped report that does. The
     * pattern rides the widgets' query strings to the REST endpoint, so this
     * watches those requests: each must carry the pattern intact and come back
     * without an error.
     */
    test('the Forms report queries through its pattern constraint', async ({ page }) => {

        const queries = [];

        page.on('response', (r) => {
            const url = decodeURIComponent(r.url());

            if (url.includes('form_(start|submit)')) {
                queries.push({ url, response: r });
            }
        });

        await login(page);
        await openConfiguredReport(page, { reportId: 'forms' });
        await page.waitForLoadState('networkidle');

        expect(queries.length, 'no request carried the Forms constraint').toBeGreaterThan(0);

        for (const q of queries) {

            expect(q.url, 'the pattern lost its anchors on the way')
                .toContain('eventName=~^form_(start|submit)$');
            // The REST endpoint answers a report query with 201.
            expect(q.response.ok(), q.url).toBe(true);

            const body = await q.response.json();

            expect(body.error, q.url).toEqual([]);
            expect(body.data.errors, q.url).toEqual([]);
            expect(body.data.request_errors || [], q.url).toEqual([]);
            // ...and the server read the same pattern the browser sent.
            expect(body.data.queryParams.constraints).toBe('eventName=~^form_(start|submit)$');
        }
    });

    /**
     * The widget types that only Traffic uses.
     *
     * `pie` and `metric-boxes` were added when traffic became configuration
     * and are the newest code in the renderer. Traffic is also the only report
     * whose widgets each constrain to different rows, so it exercises the
     * per-widget constraint path end to end.
     */
    /**
     * The goals report measures goal conversions: events the ingest marked
     * is_goal_event because they matched an active goal. The seeded goal is
     * declared before the seeded page views, so the /docs views are marked.
     */
    test.describe('the goals report measures the goals this site has', () => {

        test.beforeEach(async ({ page }) => {
            await login(page);
            await openConfiguredReport(page, { reportId: 'goals' });
        });

        test('the trend chart paints a canvas', async ({ page }) => {
            await expect(page.locator('#trend-chart canvas').first())
                .toBeVisible({ timeout: 20_000 });
        });

        test('the Goal Performance panel counts the marked conversions', async ({ page }) => {
            const box = page.locator('#goalMetrics .owa_metricInfobox').first();

            await expect(box).toBeVisible({ timeout: 20_000 });

            // Labelled by the METRIC, not by the panel.
            await expect(box).toContainText('Goal Conversions');
            await expect(box).not.toContainText('Goal Performance');

            const count = Number((await box.locator('.owa_metricInfoboxLargeNumber')
                .innerText()).replace(/[^0-9]/g, ''));
            expect(count).toBeGreaterThan(0);
        });

        test('the panel keeps its own section header', async ({ page }) => {
            // The header is the widget's, drawn like any other widget's,
            // because the boxes no longer borrow the title.
            await expect(page.locator('.owa_reportSectionHeader', { hasText: 'Goal Performance' }).first())
                .toBeVisible({ timeout: 20_000 });
        });

        /**
         * The way to a funnel is the nav, not a link on this report.
         *
         * The goals report used to carry a Related Reports widget pointing at
         * goal-funnel. A funnel is not a report any more -- it is a
         * visualization, one somebody made, and there is no single one to
         * point at. So the route is Custom Reports in the rail, whose list
         * holds the funnels that exist.
         *
         * Asserted rather than left as an absence: a reader looking at goal
         * performance and wanting to see the path to it must still have
         * somewhere to go from here.
         */
        test('funnels are reachable from the goals report, through the nav', async ({ page }) => {
            await expect(
                page.locator('a', { hasText: 'Conversion Funnels' }),
                'the goals report still links to a funnel REPORT, which no longer exists'
            ).toHaveCount(0);

            await expect(
                page.locator('.owa_rail').getByRole('link', { name: 'Custom Reports', exact: true })
            ).toHaveCount(1);
        });
    });

    test.describe('the traffic report draws its widgets', () => {

        test.beforeEach(async ({ page }) => {
            await login(page);
            await openConfiguredReport(page, { reportId: 'traffic' });
        });

        test('the trend chart paints a canvas', async ({ page }) => {
            // Flot draws into a <canvas> inside the chart container. Its
            // presence is the difference between "the script ran" and "the
            // script was emitted".
            await expect(page.locator('#trend-chart canvas').first())
                .toBeVisible({ timeout: 20_000 });
        });

        /**
         * THREE share charts, each into its own container.
         *
         * The report splits its visits three ways -- by channel, by source, by
         * campaign -- and draws each split as its own chart. Three widgets of
         * one type on one report is the case that catches a template writing
         * them all into the first container, or into a container it names from
         * the type rather than from the widget: the traffic report used to
         * have three metric-boxes widgets and that is exactly the bug they
         * were guarding, so the guard follows the shape rather than the type.
         */
        test('each share chart draws into its own container', async ({ page }) => {
            for (const [container, title] of [
                ['traffic-sources',          'Channels'],
                ['traffic-sources-source',   'Sources'],
                ['traffic-sources-campaign', 'Campaigns'],
            ]) {
                const widget = page.locator(`#${container}`).locator('xpath=ancestor::*[contains(@class,"owa_widget-pie")][1]');

                await expect(widget, `${container} is not in a pie widget`)
                    .toHaveCount(1, { timeout: 20_000 });

                await expect(widget.locator('.owa_reportSectionTitle'))
                    .toHaveText(title);
            }

            // All three draw rows: an untagged visit's campaign is a placeholder, not empty.
            for (const container of ['traffic-sources', 'traffic-sources-source', 'traffic-sources-campaign']) {
                await expect(page.locator(`#${container} .owa_shareRow`).first(),
                    `${container} drew no rows`).toBeVisible({ timeout: 20_000 });
            }
        });

        /**
         * A share chart with nothing to draw says so, rather than drawing nothing.
         *
         * Every share chart on the report has rows for the fixture's own
         * period, so the empty case is the same report over a week with no
         * traffic at all. An empty panel with no message reads as a broken
         * widget rather than as an answer.
         */
        test('a share chart with no data says so instead of drawing', async ({ page }) => {
            const drawn = page.locator('#traffic-sources');

            /*
             * The fixture's period first, and what stops the rest from passing
             * on a report where every chart is always empty: this one has rows
             * and does NOT carry the message.
             */
            await expect(drawn.locator('.owa_shareRow').first()).toBeVisible({ timeout: 20_000 });
            await expect(drawn).not.toContainText(/no data/i);

            await openConfiguredReport(page, {
                reportId: 'traffic',
                period: 'date_range',
                params: { startDate: '20100104', endDate: '20100110' },
            });

            const empty = page.locator('#traffic-sources');

            await expect(empty).toContainText(/no data/i, { timeout: 20_000 });

            // ...and no rows to read it against.
            await expect(empty.locator('.owa_shareRow')).toHaveCount(0);
        });

        /**
         * The three cards, each showing the fixture's own traffic.
         *
         * Content rather than mere presence: a card that lost its dimension or
         * its constraint still builds a grid, and these are the four dimensions
         * -- entry page, referring site, country -- that separate "the widget
         * rendered" from "the widget rendered the right query".
         */
        test('the entry pages card lists the pages traffic arrived on', async ({ page }) => {
            await expect(page.locator('#entry-pages tr.owa_gridRow').first(),
                'entry-pages drew no rows').toBeVisible({ timeout: 20_000 });

            /*
             * By PATH, as the Entry Pages report it opens groups, so a row on
             * the card is a row there. The cell is compared whole: '/' is in
             * every path.
             */
            const cells = page.locator('#entry-pages tr.owa_gridRow td[data-column="landingPage"]');

            await expect(cells.filter({ hasText: new RegExp('^' + FIXTURE.pagePaths[0] + '$') })).toHaveCount(1);
        });

        test('the referring sites card lists the referring host', async ({ page }) => {
            await expect(page.locator('#referring-sites tr.owa_gridRow').first(),
                'referring-sites drew no rows').toBeVisible({ timeout: 20_000 });

            /*
             * By SITE, not by page. The old grid on this report groups by
             * referral page URL and is still here, so a card that had taken
             * the wrong dimension would show the same host inside a full URL
             * and read as correct. The cell is compared whole.
             *
             */
            const cells = page.locator('#referring-sites tr.owa_gridRow td[data-column="sessionSource"]');

            await expect(cells.filter({ hasText: FIXTURE.traffic.refererHost }))
                .toHaveCount(1);

            /*
             * ...and it leads to the report about the same thing.
             *
             * This link did not exist at first: Referring Web Sites grouped by
             * referral page URL, so a card of SITES leading there was a link
             * the report builder itself refuses to offer -- a fuller report
             * has to be about the dimension the card shows. The report was
             * repointed at referralWebSite, which is what its nav entry had
             * always called it, and the link followed.
             */
            const more = page.locator('#referring-sites')
                .locator('xpath=ancestor::*[contains(@class,"owa_widget-grid-card")][1]')
                .locator('.owa_moreLinks a');

            await expect(more).toHaveAttribute( 'href', /reportId=referring-sites/ );
        });

        test('the countries card links to the geolocation report', async ({ page }) => {
            await expect(page.locator('#visitor-countries tr.owa_gridRow').first(),
                'visitor-countries drew no rows').toBeVisible({ timeout: 20_000 });

            const more = page.locator('#visitor-countries')
                .locator('xpath=ancestor::*[contains(@class,"owa_widget-grid-card")][1]')
                .locator('.owa_moreLinks a');

            await expect(more).toHaveAttribute( 'href', /reportId=geolocation/ );
        });

        test('the sources grid lists the seeded sources', async ({ page }) => {
            const grid = page.locator('#top-sources .owa_gridTable');

            await expect(grid, 'top-sources built no grid').toHaveCount(1, { timeout: 20_000 });

            for (const source of FIXTURE.traffic.sources) {
                await expect(page.locator('#top-sources')).toContainText(source);
            }
        });

        /*
         * NO referrals test either: Top Referrals was a duplicate of the
         * dashboard's Top Referrers card and is off this report. Referring Web
         * Sites, which is what the report keeps, is covered by the grid-card
         * assertions above.
         *
         * NO keywords test and NO related-reports test here any more: Top
         * Keywords and the links block are off this report.
         *
         * Neither property went with them. That a widget's declared constraint
         * reaches its query is measured on the dashboard, where #top-referers
         * is constrained to medium==referral and the assertion is its row COUNT
         * -- of the four seeded visits only the referral may arrive. That a
         * links block prints its heading once is measured on the goals report,
         * which still has one.
         */
    });
});

/**
 * A widget has a floor.
 *
 * The report grid is twelve tracks of minmax(0, 1fr), and a quarter-width card
 * on a 1280-wide laptop was 213px: twelve tracks and eleven 20px gaps leave
 * 64px a track. It did not overflow -- .owa_reportGridItem sets min-width:0, so
 * the widget shrank and its table scrolled inside it -- which is the worst
 * shape for a layout bug to take. The report looks intact and is unreadable,
 * and nothing in the console says a word.
 *
 * The floor is held by container queries that promote a widget's SPAN as the
 * container narrows, because a min-width on a grid item cannot hold it: a
 * minmax(0, 1fr) track has a literal 0 minimum, so an item wider than its track
 * overflows into its neighbour rather than widening the track.
 *
 * Which makes this a test only a browser can run. The span classes are static
 * and a PHP test can read them; what they RESOLVE to at a given width is the
 * thing that was broken.
 */
test.describe('the report grid gives every widget a usable width', () => {

    /*
     * Content Overview is the case: a full-width trend and three quarter-width
     * cards, which is the layout the floor exists for. Widths chosen around the
     * breakpoints (1260 / 940 / 620 of GRID width, not viewport) rather than at
     * them, so the test is not asserting a rounding.
     */
    const VIEWPORTS = [1600, 1440, 1280, 1150, 1024, 900, 760];

    /*
     * 260, not 300. The rules are solved for 300px of GRID AREA and a widget's
     * own box is 20px narrower -- .owa_reportSectionContent carries 10px of side
     * margin -- so the usable floor is around 280. The assertion sits under
     * that with room for a scrollbar, because what it is guarding against is
     * 213px, not a ten-pixel drift.
     */
    const FLOOR = 260;

    test('no widget collapses below a readable width at any window size', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        const tooNarrow = [];

        for (const width of VIEWPORTS) {

            await page.setViewportSize({ width, height: 1000 });

            await page.goto(
                `?owa_do=base.report&owa_reportId=content&owa_siteId=${FIXTURE.siteId}`
                + '&owa_period=last_thirty_days',
                { waitUntil: 'networkidle' }
            );

            await page.waitForSelector('.owa_reportGridItem', { timeout: 20_000 });

            const measured = await page.evaluate(() => {

                const grid = document.querySelector('.owa_reportGrid');

                return {
                    grid: Math.round(grid.getBoundingClientRect().width),
                    items: Array.from(document.querySelectorAll('.owa_reportGridItem'))
                        .map(el => Math.round(el.getBoundingClientRect().width)),
                };
            });

            for (const w of measured.items) {

                /*
                 * Except when the grid ITSELF is under the floor. Below about
                 * 620px of grid the ladder has run out -- one column is the
                 * whole container -- and a widget cannot be wider than what
                 * holds it.
                 */
                if (w < FLOOR && measured.grid >= FLOOR) {

                    tooNarrow.push(`viewport ${width}: grid ${measured.grid}, widget ${w}`);
                }
            }
        }

        expect(tooNarrow, 'widgets collapsed below the floor').toEqual([]);
    });

    /**
     * The grid has to still BE a grid inside a tab.
     *
     * On a report rendered per metric set the tab PANEL is the grid -- one
     * panel per set, each holding that set's widgets -- and jQuery-UI's
     * `.ui-tabs .ui-tabs-panel { display: block }` is two classes to
     * `.owa_reportGrid`'s one. It won on every tabbed report: the tracks were
     * never created and every widget was a full-width block.
     *
     * It stayed invisible because every widget on every tabbed report was full
     * width anyway, so a grid of one column per row looked exactly like the
     * grid working. The first half-width widget on a tabbed report is what
     * would have found it, which is a long time to wait for a stylesheet to be
     * checked.
     */
    test('a tabbed report lays its widgets out on the grid', async ({ page }) => {
        await login(page);

        await page.setViewportSize({ width: 1400, height: 1000 });

        // Browser Types renders per metric set, so its panels ARE the grids.
        await page.goto(
            `?owa_do=base.report&owa_reportId=browsers&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#report-tabs.ui-tabs', { timeout: 20_000 });

        const panel = page.locator('#report-tabs > div.owa_reportGrid').first();
        await expect(panel).toBeAttached();

        // It is a tab panel...
        await expect(panel).toHaveClass(/ui-tabs-panel/);

        // ...and a grid of twelve tracks, which is the part that was lost.
        const layout = await panel.evaluate(el => ({
            display: getComputedStyle(el).display,
            tracks:  getComputedStyle(el).gridTemplateColumns.split(' ').length,
        }));

        expect(layout.display).toBe('grid');
        expect(layout.tracks).toBe(12);
    });

    /**
     * THE METRIC BOXES SCROLL; THEY DO NOT WRAP.
     *
     * A widget shows as many metrics as its author asked for and is as wide as
     * the layout gives it, so the two disagree regularly. Wrapping resolved it
     * by growing the widget downward -- which moves the chart under it, changes
     * the panel's height, and leaves the fifth metric alone on a line where it
     * reads as a different kind of thing from the four above.
     *
     * The arrows appear only when there is somewhere to go, which is the part
     * a fixed-width fixture cannot show on its own: the dashboard's card holds
     * five metrics and needs them at one width and not at another.
     */
    test('the metric boxes stay on one line and scroll when they must', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        const carousel = async (width) => {

            await page.setViewportSize({ width, height: 1500 });

            await page.goto(
                `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
                + '&owa_period=last_thirty_days',
                { waitUntil: 'networkidle' }
            );

            await page.waitForSelector('.owa_metricCarousel .owa_metricInfobox', { timeout: 20_000 });
            await page.waitForTimeout(1200);

            return page.evaluate(() => {

                const c = document.querySelector('.owa_metricCarousel');
                const track = c.querySelector('.metricInfoboxesContainer');
                const boxes = [...track.querySelectorAll('.owa_metricInfobox')];
                const prev = c.querySelector('.owa_metricCarouselPrev');
                const next = c.querySelector('.owa_metricCarouselNext');

                return {
                    boxes: boxes.length,
                    rows: new Set(boxes.map((b) => Math.round(b.getBoundingClientRect().top))).size,
                    overflow: Math.round(track.scrollWidth - track.clientWidth),
                    scrollLeft: Math.round(track.scrollLeft),
                    back: getComputedStyle(prev).display !== 'none',
                    forward: getComputedStyle(next).display !== 'none',
                };
            });
        };

        // Wide enough for all five: nothing hidden either way, so no arrows.
        const wide = await carousel(1800);

        expect(wide.boxes).toBeGreaterThanOrEqual(4);
        expect(wide.rows, 'the boxes wrapped instead of staying on one line').toBe(1);
        expect(wide.overflow).toBeLessThanOrEqual(1);
        expect(wide.back, 'a back arrow with nothing to the left').toBe(false);
        expect(wide.forward, 'a forward arrow with nothing to the right').toBe(false);

        // Narrow enough that they do not fit -- same count, still one line.
        // The dashboard's Site Metrics card is full width, so that takes a
        // phone-sized viewport: measured, 640px overflows by 134px and 900px
        // not at all.
        const start = await carousel(640);

        expect(start.boxes).toBe(wide.boxes);
        expect(start.rows, 'the boxes wrapped instead of scrolling').toBe(1);
        expect(start.overflow,
            'this width no longer overflows, so the arrows prove nothing').toBeGreaterThan(10);

        /*
         * EACH SIDE ANSWERS FOR ITSELF. At the start of the row nothing is
         * hidden to the left, so there is no back arrow at all -- not a greyed
         * one. This is the assertion that separates "the row scrolls" from
         * "something is hidden THIS way".
         */
        expect(start.back, 'a back arrow at the start of the row').toBe(false);
        expect(start.forward, 'no forward arrow with boxes hidden to the right').toBe(true);

        const track = '.owa_metricCarousel .metricInfoboxesContainer';

        const scrollLeft = () => page.evaluate(
            (sel) => Math.round(document.querySelector(sel).scrollLeft), track);

        const shown = (side) => page.evaluate((s) => {
            const el = document.querySelector('.owa_metricCarousel' + s);
            return getComputedStyle(el).display !== 'none';
        }, side);

        // Forward once: the row moves, and now BOTH sides hide something.
        await page.locator('.owa_metricCarouselNext').first().click();
        await page.waitForTimeout(700);

        expect(await scrollLeft(),
            'the forward arrow did not scroll the row').toBeGreaterThan(0);

        expect(await shown('Prev'), 'no back arrow after scrolling away from the start').toBe(true);

        // Forward to the end: nothing left to the right, so that arrow goes.
        for (let i = 0; i < 4; i++) {

            if (!await shown('Next')) { break; }

            await page.locator('.owa_metricCarouselNext').first().click();
            await page.waitForTimeout(700);
        }

        expect(await shown('Next'), 'a forward arrow at the end of the row').toBe(false);
        expect(await shown('Prev'), 'no back arrow at the end of the row').toBe(true);

        // ...and back again returns the forward arrow.
        await page.locator('.owa_metricCarouselPrev').first().click();
        await page.waitForTimeout(700);

        expect(await shown('Next'), 'no forward arrow after scrolling back').toBe(true);
    });

    /**
     * AN EMPTY PERIOD IS A CHART OF ZERO.
     *
     * It used to replace the whole widget with a line of text and collapse it
     * to 50px, so a report with no data changed SHAPE and the reader had to
     * work out whether the chart was missing or the data was.
     *
     * Drawn as a real trend instead, with a point per day of the period and
     * every one of them zero. For a count that IS the answer rather than a
     * stand-in for one -- and the days come from the period the result set was
     * queried for, which is the only place they can come from when the rows are
     * empty.
     *
     * WHICH REPORT IS EMPTY IS A FIXTURE FACT, AND IT MOVED.
     *
     * This used the Clicks report, on the grounds that the fixture seeded no
     * clicks. It seeds six now -- the click reports had no coverage precisely
     * because nothing produced a click -- so the Clicks report is a chart of
     * real data and no longer says anything about the empty case.
     *
     * Pointed at Feeds instead, which nothing seeds: feedRequests, feedReaders
     * and feedSubscriptions have no fixture and no handler firing in this
     * suite. Moved rather than weakened, per the note that used to be here: an
     * empty-state test whose subject stopped being empty should change subject,
     * not lower its standards.
     */
    test('a trend with no data is drawn as a zero-filled period', async ({ page }) => {
        await login(page);
        await page.setViewportSize({ width: 1500, height: 1200 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=scroll-depth&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#trend-chart', { timeout: 20_000 });
        await page.waitForTimeout(1500);

        const state = await page.evaluate(() => {

            const c = document.querySelector('#trend-chart');

            const chart = window.trend.areaChart;
            const drawn = chart.dataseries[0];

            return {
                height: Math.round(c.getBoundingClientRect().height),
                canvas: !!c.querySelector('canvas'),
                rowsReturned: window.trend.resultSet.resultsRows.length,
                series: chart.dataseries.length,
                points: drawn ? drawn.data.length : 0,
                allZero: drawn ? drawn.data.every((p) => p[1] === 0) : null,
                xTicks: c.querySelectorAll('.flot-x-axis .flot-tick-label').length,
                yTicks: c.querySelectorAll('.flot-y-axis .flot-tick-label').length,
            };
        });

        // The premise: this really is an empty period.
        expect(state.rowsReturned,
            'the Clicks report has data now -- pick another empty report').toBe(0);

        expect(state.canvas, 'an empty period drew no chart at all').toBe(true);

        // Not collapsed to a line of text.
        expect(state.height, 'the widget collapsed instead of keeping its shape')
            .toBeGreaterThan(90);

        /*
         * A point per day of the period, all zero. Thirty days is
         * last_thirty_days; asserted as a range rather than exactly, because
         * whether a period includes both endpoints is not what this is about.
         */
        expect(state.series).toBe(1);
        expect(state.points, 'the empty period was not filled with days')
            .toBeGreaterThanOrEqual(28);
        expect(state.points).toBeLessThanOrEqual(32);
        expect(state.allZero, 'the synthetic series is not all zero').toBe(true);

        // ...and it is drawn against real dates, not a bare box.
        expect(state.xTicks, 'no dated axis under the zero line').toBeGreaterThan(0);
        expect(state.yTicks, 'no value axis beside the zero line').toBeGreaterThan(0);
    });

    /**
     * A trend card's chart FILLS what the panel has left.
     *
     * The card has a floor under its height, and the chart was drawing at its
     * default 125px however tall the panel was -- a small plot floating in the
     * top of a large empty box. The chart is the only part of a card that can
     * absorb space, so it is the part that should.
     */
    test('a trend card\'s chart fills the height the panel has left', async ({ page }) => {
        await login(page);
        await page.setViewportSize({ width: 1600, height: 1500 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('.owa_widget-trend-card .owa_areaChart canvas', { timeout: 20_000 });
        await page.waitForTimeout(1200);

        const m = await page.evaluate(() => {

            const card = document.querySelector('.owa_widget-trend-card');
            const chart = card.querySelector('.owa_trendChart');
            const plot = card.querySelector('.owa_areaChart');

            return {
                card: Math.round(card.getBoundingClientRect().height),
                chart: Math.round(chart.getBoundingClientRect().height),
                plot: Math.round(plot.getBoundingClientRect().height),
            };
        });

        // Comfortably past the 125px default the chart used to draw at.
        expect(m.plot, 'the chart is still at its default height').toBeGreaterThan(180);

        // ...and the plot really is filling the space the chart element has.
        expect(m.chart - m.plot).toBeLessThanOrEqual(12);

        // The chart is the biggest part of the card, which is what "fills" means.
        expect(m.chart).toBeGreaterThan(m.card / 2);
    });

    /**
     * A widget is a section of the page, not a panel: no border, no background
     * of its own, and its full-report link on its heading's line.
     */
    test('widgets are sections, with no panel round them', async ({ page }) => {
        await login(page);
        await page.setViewportSize({ width: 1600, height: 1500 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('.owa_widget-pie .owa_shareChart', { timeout: 20_000 });

        const widgets = await page.evaluate(() =>
            [...document.querySelectorAll('.owa_reportGridItem')].map((el) => {

                const cs = getComputedStyle(el);
                const head = el.querySelector('.owa_reportSectionHeader');
                const more = el.querySelector('.owa_moreLinks');

                return {
                    type: (el.className.match(/owa_widget-([a-z-]+)/) || [])[1] || '',
                    border: cs.borderTopWidth,
                    background: cs.backgroundColor,
                    // How far the link's middle is from the heading's: 0 on one line.
                    linkOffset: head && more
                        ? Math.abs((head.getBoundingClientRect().top + head.getBoundingClientRect().bottom)
                            - (more.getBoundingClientRect().top + more.getBoundingClientRect().bottom)) / 2
                        : null,
                    linkInHead: !! (head && more && head.contains(more)),
                };
            }));

        // The fixture has to contain each kind or this proves nothing.
        for (const type of ['grid', 'grid-card', 'trend-card', 'pie']) {
            expect(widgets.filter((w) => w.type === type).length, `no ${type} widget`).toBeGreaterThanOrEqual(1);
        }

        for (const w of widgets) {
            expect(w.border, `${w.type} has a border`).toBe('0px');
            expect(w.background, `${w.type} has a background`).toBe('rgba(0, 0, 0, 0)');
        }

        const linked = widgets.filter((w) => w.linkOffset !== null);

        expect(linked.length).toBeGreaterThanOrEqual(2);

        for (const w of linked) {
            expect(w.linkInHead, `${w.type}'s full-report link is not in its heading`).toBe(true);
            expect(w.linkOffset, `${w.type}'s full-report link is off the heading's line`).toBeLessThan(6);
        }
    });

    /**
     * A share chart is as wide as its widget, at a quarter of a row on the
     * dashboard and a third on Traffic, and a long name is cut short with an
     * ellipsis rather than pushing the percentage out of the widget.
     */
    test('a share chart fills its widget, its percentages inside it', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        const measure = async (reportId, width) => {

            await page.setViewportSize({ width, height: 1500 });

            await page.goto(
                `?owa_do=base.report&owa_reportId=${reportId}&owa_siteId=${FIXTURE.siteId}`
                + '&owa_period=last_thirty_days',
                { waitUntil: 'networkidle' }
            );

            await page.waitForSelector('.owa_shareChart .owa_shareRow', { timeout: 20_000 });

            return page.evaluate(() => [...document.querySelectorAll('.owa_shareChart')].map((list) => {

                const item = list.closest('.owa_reportGridItem').getBoundingClientRect();
                const bar = list.querySelector('.owa_gridBar').getBoundingClientRect();
                const values = [...list.querySelectorAll('.owa_shareValue')].map((v) => v.getBoundingClientRect());

                return {
                    item: Math.round(item.width),
                    bar: Math.round(bar.width),
                    valuesInside: values.every((v) => v.right <= item.right + 0.5 && v.width > 0),
                };
            }));
        };

        const seen = [];

        for (const [reportId, width] of [['dashboard', 1600], ['dashboard', 1000], ['traffic', 1600]]) {
            seen.push(...await measure(reportId, width));
        }

        expect(seen.length).toBe(2 + 2 + 3);

        for (const c of seen) {
            expect(Math.abs(c.bar - c.item), `a bar is ${c.bar}px in a ${c.item}px widget`).toBeLessThanOrEqual(1);
            expect(c.valuesInside, 'a percentage is outside its widget').toBe(true);
        }
    });

    /**
     * A CHART GROWS BACK.
     *
     * It shrank and never recovered: 1600 -> 1000 redrew the canvas at 678px,
     * and going back to 1600 left it at 678 inside a 1272px placeholder -- a
     * chart occupying half its widget with white space beside it.
     *
     * flot's own resize plugin was supposed to do this and could not. It polls
     * elements it was told about, and setupAreaChart() REPLACES the chart
     * element on every redraw, so the node it registered was detached and a
     * detached node reads as invisible. OWA.onWidthChange watches the widget
     * CONTAINER instead, which is the one element never replaced.
     *
     * BOTH directions and TWICE, because shrinking always worked -- a one-way
     * test would have passed throughout the bug.
     */
    test('a trend chart is redrawn at the width it is given, both ways', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        /*
         * From 1800: the trend is half a row there, and at 1000 it is promoted
         * to the whole row, which at 1600 would be a little WIDER than half.
         */
        const canvasWidth = () => page.evaluate(() => {
            const c = document.querySelector('.owa_areaChart canvas');
            const h = document.querySelector('.owa_areaChart');
            return c && h ? {
                canvas: Math.round(c.getBoundingClientRect().width),
                holder: Math.round(h.getBoundingClientRect().width),
            } : null;
        });

        await page.setViewportSize({ width: 1800, height: 1000 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=content&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('.owa_areaChart canvas', { timeout: 20_000 });

        const wide = await canvasWidth();
        expect(wide.canvas).toBe(wide.holder);

        // Narrow: the canvas follows the placeholder down.
        await page.setViewportSize({ width: 1000, height: 1000 });

        await expect.poll(async () => (await canvasWidth()).canvas, { timeout: 10_000 })
            .toBeLessThan(wide.canvas);

        // ...and back up, which is the half that was broken.
        await page.setViewportSize({ width: 1800, height: 1000 });

        await expect.poll(async () => {
            const m = await canvasWidth();
            return m.canvas === m.holder;
        }, { timeout: 10_000 }).toBe(true);

        expect((await canvasWidth()).canvas).toBe(wide.canvas);

        // Twice, so a single lucky redraw cannot pass this.
        await page.setViewportSize({ width: 800, height: 1000 });

        await expect.poll(async () => (await canvasWidth()).canvas, { timeout: 10_000 })
            .toBeLessThan(wide.canvas);

        await page.setViewportSize({ width: 1800, height: 1000 });

        await expect.poll(async () => (await canvasWidth()).canvas, { timeout: 10_000 })
            .toBe(wide.canvas);
    });

    /**
     * ...and so does the GRID.
     *
     * It is a table at the width of its box, so it follows its widget with no
     * resize handler of its own: narrower with the window, and back. A table
     * whose columns need more than the widget has scrolls inside its box.
     */
    test('a grid follows its widget when the window changes', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        const measure = () => page.evaluate(() => {
            const box = document.querySelector('#latest-visits .owa_gridScroll');
            const table = box && box.querySelector('.owa_gridTable');
            return table ? {
                box:   box.clientWidth,
                table: Math.round(table.getBoundingClientRect().width),
                scrolls: box.scrollWidth > box.clientWidth,
            } : null;
        });

        // The table fills its box, or is wider and the box scrolls.
        const fits = (m) => m.table >= m.box - 1 && (m.table <= m.box + 1 || m.scrolls);

        await page.setViewportSize({ width: 1600, height: 1000 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#latest-visits tr.owa_gridRow', { timeout: 20_000 });

        const wide = await measure();

        expect(fits(wide), JSON.stringify(wide)).toBe(true);

        await page.setViewportSize({ width: 1000, height: 1000 });

        await expect.poll(async () => fits(await measure()), { timeout: 10_000 }).toBe(true);

        const narrow = await measure();
        expect(narrow.box).toBeLessThan(wide.box);

        // ...and back.
        await page.setViewportSize({ width: 1600, height: 1000 });

        await expect.poll(async () => (await measure()).box, { timeout: 10_000 }).toBe(wide.box);
    });

    /**
     * Resizing must not throw.
     *
     * flot's resize plugin threw two uncaught TypeErrors on every window
     * resize, in a bundle where nothing was watching the console. It is gone
     * now; this is what notices if it -- or anything like it -- comes back.
     */
    test('resizing a report raises no console error', async ({ page }) => {
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await login(page);

        await page.setViewportSize({ width: 1600, height: 1000 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#latest-visits tr.owa_gridRow', { timeout: 20_000 });

        for (const width of [1000, 1600, 800, 1600]) {

            await page.setViewportSize({ width, height: 1000 });
            await page.waitForTimeout(700);
        }

        expect(errors).toEqual([]);
    });

    /**
     * ...and the promotion is what does it, not luck.
     *
     * A widget declaring a quarter must actually be resolving to more than
     * three columns once the container is under 1260 -- otherwise the test
     * above could pass on a report whose widgets happen to be wide.
     */
    test('a quarter-width widget is promoted once a quarter stops fitting', async ({ page }) => {
        await login(page);

        // 1280 with the old 200px nav column; the rail and column are 336px.
        await page.setViewportSize({ width: 1416, height: 1000 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=content&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        const card = page.locator('.owa_reportGridItem.owa_span-3').first();
        await expect(card).toBeAttached();

        // Declared a quarter...
        await expect(card).toHaveClass(/owa_span-3/);

        // ...and resolving to more than a quarter at this width.
        const span = await card.evaluate(el => getComputedStyle(el).gridColumnStart);
        expect(span).toBe('span 4');
    });
});
