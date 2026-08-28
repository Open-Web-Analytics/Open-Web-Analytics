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
 * on; the unresolvable sort arrived as a null sortColumn, jqGrid called
 * .toLowerCase() on it, and the grid threw while building. The page still
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
    test('no configured report raises a browser console error', async ({ page }) => {
        test.setTimeout(300_000);

        const ids = configuredReportIds();

        expect(ids.length, 'no report definitions were found to sweep').toBeGreaterThan(40);

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
     * The widget types that only Traffic uses.
     *
     * `pie` and `metric-boxes` were added when traffic became configuration
     * and are the newest code in the renderer. Traffic is also the only report
     * whose widgets each constrain to different rows, so it exercises the
     * per-widget constraint path end to end.
     */
    /**
     * The goals report is the first definition whose metrics are DERIVED per
     * site: the boxes measure one metric per goal the site has configured, so
     * a static list could not express them. Nothing but a browser against a
     * seeded goal exercises that resolution end to end.
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

        test('the Goal Performance panel draws a box for the seeded goal', async ({ page }) => {
            // The panel exists only because the site has an active goal. On a
            // site with none the widget is dropped rather than drawn empty,
            // which is why this asserts a BOX and not merely the container.
            const box = page.locator('#goalMetrics .owa_metricInfobox').first();

            await expect(box).toBeVisible({ timeout: 20_000 });

            // Labelled by the METRIC, which names the goal -- not by the
            // panel. A panel measuring one metric per goal that labelled every
            // box "Goal Performance" would say nothing about which goal.
            await expect(box).toContainText(FIXTURE.goal.name);
            await expect(box).not.toContainText('Goal Performance');
        });

        test('the panel keeps its own section header', async ({ page }) => {
            // The header is the widget's, drawn like any other widget's,
            // because the boxes no longer borrow the title.
            await expect(page.locator('.owa_reportSectionHeader', { hasText: 'Goal Performance' }).first())
                .toBeVisible({ timeout: 20_000 });
        });

        test('the related reports link to the funnel', async ({ page }) => {
            await expect(page.locator('a', { hasText: 'Conversion Funnels' }).first())
                .toBeVisible();
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
         * THREE pies, each into its own container.
         *
         * The report splits its visits three ways -- by medium, by source, by
         * campaign -- and draws each split as its own chart. Three widgets of
         * one type on one report is the case that catches a template writing
         * them all into the first container, or into a container it names from
         * the type rather than from the widget: the traffic report used to
         * have three metric-boxes widgets and that is exactly the bug they
         * were guarding, so the guard follows the shape rather than the type.
         *
         * Campaign has no canvas ASSERTED and that is deliberate -- see the
         * empty-pie test below.
         */
        test('each pie draws into its own container', async ({ page }) => {
            for (const [container, title] of [
                ['traffic-sources',          'Mediums'],
                ['traffic-sources-source',   'Sources'],
                ['traffic-sources-campaign', 'Campaigns'],
            ]) {
                const widget = page.locator(`#${container}`).locator('xpath=ancestor::*[contains(@class,"owa_widget-pie")][1]');

                await expect(widget, `${container} is not in a pie widget`)
                    .toHaveCount(1, { timeout: 20_000 });

                await expect(widget.locator('.owa_reportSectionHeader'))
                    .toHaveText(title);
            }

            // The two the fixture attributes traffic to actually paint.
            for (const container of ['traffic-sources', 'traffic-sources-source']) {
                await expect(page.locator(`#${container} canvas`).first(),
                    `${container} painted no canvas`).toBeVisible({ timeout: 20_000 });
            }
        });

        /**
         * A pie with nothing to draw says so, rather than drawing nothing.
         *
         * The fixture attributes no campaign to any visit, so the campaign pie
         * is the one shipped widget on a seeded report with an empty result
         * set. An empty pie that painted a blank canvas -- or an empty panel
         * with no message at all -- reads as a broken widget rather than as an
         * answer, and the two are indistinguishable from outside.
         */
        test('a pie with no data says so instead of drawing', async ({ page }) => {
            const empty = page.locator('#traffic-sources-campaign');

            await expect(empty).toContainText(/no data/i, { timeout: 20_000 });

            /*
             * ...and no slices to read it against. flot builds its two
             * canvases either way -- they are just painted blank -- so the
             * canvas is not the difference. The LEGEND is: one entry per
             * slice, and an empty pie has none.
             */
            await expect(empty.locator('.legend tr')).toHaveCount(0);

            /*
             * The other half of the same question, and what stops the above
             * from passing on a report where every pie is empty: the pie next
             * to it has slices and does NOT carry the message.
             */
            const drawn = page.locator('#traffic-sources');

            await expect(drawn.locator('.legend tr').first()).toBeVisible({ timeout: 20_000 });
            await expect(drawn).not.toContainText(/no data/i);
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
            await expect(page.locator('#entry-pages tr.jqgrow').first(),
                'entry-pages drew no rows').toBeVisible({ timeout: 20_000 });

            await expect(page.locator('#entry-pages')).toContainText(FIXTURE.pageTitles[0]);
        });

        test('the referring sites card lists the referring host', async ({ page }) => {
            await expect(page.locator('#referring-sites tr.jqgrow').first(),
                'referring-sites drew no rows').toBeVisible({ timeout: 20_000 });

            /*
             * By SITE, not by page. The old grid on this report groups by
             * referral page URL and is still here, so a card that had taken
             * the wrong dimension would show the same host inside a full URL
             * and read as correct. The cell is compared whole.
             *
             */
            const cells = page.locator('#referring-sites tr.jqgrow td[aria-describedby$="referralWebSite"]');

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
            await expect(page.locator('#visitor-countries tr.jqgrow').first(),
                'visitor-countries drew no rows').toBeVisible({ timeout: 20_000 });

            const more = page.locator('#visitor-countries')
                .locator('xpath=ancestor::*[contains(@class,"owa_widget-grid-card")][1]')
                .locator('.owa_moreLinks a');

            await expect(more).toHaveAttribute( 'href', /reportId=geolocation/ );
        });

        test('the sources grid lists the seeded sources', async ({ page }) => {
            const grid = page.locator('#top-sources .ui-jqgrid');

            await expect(grid, 'top-sources built no grid').toHaveCount(1, { timeout: 20_000 });

            for (const source of FIXTURE.traffic.sources) {
                await expect(page.locator('#top-sources')).toContainText(source);
            }
        });

        test('the referrals grid lists the referring page', async ({ page }) => {
            const grid = page.locator('#top-referrals .ui-jqgrid');

            await expect(grid, 'top-referrals built no grid').toHaveCount(1, { timeout: 20_000 });

            await expect(page.locator('#top-referrals'))
                .toContainText(FIXTURE.traffic.refererHost);
        });

        /*
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
        const wide = await carousel(1600);

        expect(wide.boxes).toBeGreaterThanOrEqual(4);
        expect(wide.rows, 'the boxes wrapped instead of staying on one line').toBe(1);
        expect(wide.overflow).toBeLessThanOrEqual(1);
        expect(wide.back, 'a back arrow with nothing to the left').toBe(false);
        expect(wide.forward, 'a forward arrow with nothing to the right').toBe(false);

        // Narrow enough that they do not fit -- same count, still one line.
        const start = await carousel(1100);

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
     * The fixture seeds no clicks, which is what makes the Clicks report a
     * reliable empty state. If that ever changes, point this at another report
     * with nothing in it rather than weakening the assertions.
     */
    test('a trend with no data is drawn as a zero-filled period', async ({ page }) => {
        await login(page);
        await page.setViewportSize({ width: 1500, height: 1200 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=clicks&owa_siteId=${FIXTURE.siteId}`
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
     * A CARD IS A PANEL, and stays one when the data is thin.
     *
     * A card and a pie are bounded things you read at a glance and then follow
     * to the full report -- so they carry a border, a background and a floor
     * under their height. A full-width table or trend is not that; it is the
     * page itself, and a box round it would be a box round everything.
     *
     * The FLOOR is the part a fixture cannot fake convincingly, so it is the
     * part asserted hardest. align-items:stretch already makes a row uniform,
     * but a row holding only short widgets was still short: the dashboard's
     * Top Content has four rows in it, and drew a panel barely taller than its
     * own heading. A card is the same object whether or not the data filled it.
     */
    test('a card and a pie are panels with a floor under their height', async ({ page }) => {
        await login(page);
        await page.setViewportSize({ width: 1600, height: 1500 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('.owa_widget-pie canvas', { timeout: 20_000 });
        await page.waitForTimeout(1200);

        const widgets = await page.evaluate(() =>
            [...document.querySelectorAll('.owa_reportGridItem')].map((el) => {

                const cs = getComputedStyle(el);

                return {
                    type: (el.className.match(/owa_widget-([a-z-]+)/) || [])[1] || '',
                    height: Math.round(el.getBoundingClientRect().height),
                    border: cs.borderTopWidth,
                    radius: parseInt(cs.borderTopLeftRadius, 10),
                    padding: parseInt(cs.paddingTop, 10),
                    background: cs.backgroundColor,
                };
            }));

        const cards = widgets.filter((w) =>
            ['grid-card', 'trend-card', 'pie'].includes(w.type));

        const plain = widgets.filter((w) => w.type === 'grid');

        // The fixture has to contain both kinds or this proves nothing.
        expect(cards.length).toBeGreaterThanOrEqual(3);
        expect(plain.length).toBeGreaterThanOrEqual(1);

        for (const c of cards) {

            expect(c.border, `${c.type} has no border`).toBe('1px');
            expect(c.radius, `${c.type} has square corners`).toBeGreaterThan(0);
            expect(c.background, `${c.type} is not explicitly white`).toBe('rgb(255, 255, 255)');

            // Content must not abut the edge.
            expect(c.padding, `${c.type} has no padding inside its border`)
                .toBeGreaterThanOrEqual(10);

            expect(c.height, `${c.type} is below the floor`).toBeGreaterThanOrEqual(320);
        }

        // ...and a full-width table is a panel too, so a report reads as a set
        // of panels rather than two kinds of thing.
        for (const p of plain) {
            expect(p.border, 'a grid did not get the panel border').toBe('1px');
            expect(p.height, 'a grid is below the floor').toBeGreaterThanOrEqual(320);
        }

        /*
         * The FOOTER sits on the panel's floor. A panel has a floor under its
         * height, so a four-row table leaves space below it -- and the pager
         * and the "View Full Report" link were landing against the last row
         * with the rest of the panel empty underneath.
         */
        const footers = await page.evaluate(() =>
            [...document.querySelectorAll('.owa_reportGridItem')]
                .map((el) => {
                    const more = el.querySelector('.owa_moreLinks');
                    if (!more) { return null; }
                    return Math.round(el.getBoundingClientRect().bottom
                        - more.getBoundingClientRect().bottom);
                })
                .filter((g) => g !== null));

        expect(footers.length).toBeGreaterThanOrEqual(2);

        // Within the panel's own padding of the bottom edge.
        for (const gap of footers) {
            expect(gap, `a "View Full Report" link is ${gap}px off the floor`)
                .toBeLessThanOrEqual(20);
        }
    });

    /**
     * EVERY PIE DRAWS THE SAME CIRCLE, whatever width its widget is.
     *
     * A pie used to be a SQUARE the size of whatever held it -- the height was
     * literally the container's width. At a quarter of a row that is a 286px
     * box; at half a row it is a 619px one, and flot sizes the circle from
     * min(width, height), so the same pie drew at 174px on the dashboard and
     * around 446px on Traffic. Two pies, two and a half times apart, with
     * nothing in the definitions to say so.
     *
     * The height is the pie's own option now and is capped at the width, so
     * min(w, h) is the same number everywhere and so is the circle. The extra
     * width on a wide widget becomes margin, which is what it should have been.
     *
     * Measured off the CANVAS rather than from the element box: the box was
     * always the same as its container and told you nothing about what was
     * drawn in it. flot renders pie labels as HTML, so the canvas holds the
     * circle alone and its filled extent IS the diameter.
     */
    test('a pie draws the same size circle on every report', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);
        await page.setViewportSize({ width: 1600, height: 1400 });

        const circles = async (reportId) => {

            await page.goto(
                `?owa_do=base.report&owa_reportId=${reportId}&owa_siteId=${FIXTURE.siteId}`
                + '&owa_period=last_thirty_days',
                { waitUntil: 'networkidle' }
            );

            await page.waitForSelector('.owa_pieChart canvas', { timeout: 20_000 });
            await page.waitForTimeout(1200);

            return page.evaluate(() => [...document.querySelectorAll('.owa_pieChart')].map((holder) => {

                let widest = 0;

                for (const c of holder.querySelectorAll('canvas')) {

                    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;

                    let minX = 1e9, maxX = -1, n = 0;

                    for (let y = 0; y < c.height; y++) {
                        for (let x = 0; x < c.width; x++) {
                            if (d[(y * c.width + x) * 4 + 3] > 20) {
                                n++;
                                if (x < minX) { minX = x; }
                                if (x > maxX) { maxX = x; }
                            }
                        }
                    }

                    // The slice layer, not flot's empty base canvas.
                    if (n > 500) { widest = Math.max(widest, maxX - minX + 1); }
                }

                /*
                 * The WIDGET's width, not the plot's. The plot is a fixed box
                 * now, so comparing plots would make the "is one of these
                 * actually wider" guard below compare two equal numbers and
                 * fail on a report that is set up correctly.
                 */
                const item = holder.closest('.owa_reportGridItem');

                return {
                    widget: Math.round(item.getBoundingClientRect().width),
                    circle: widest,
                };
            }));
        };

        // The dashboard's two pies are a quarter of a row...
        const narrow = await circles('dashboard');

        expect(narrow.length).toBe(2);

        /*
         * ...and Traffic's are a THIRD of one each, in wider containers.
         *
         * Two, not three: the report declares a third pie for campaigns, and
         * the fixture runs none, so that one writes its no-data message and
         * never builds a plot at all. A pie that drew an empty circle would
         * turn up here as a third entry with a circle of 0 and fail the
         * same-size assertion below, which is the right answer.
         */
        const wide = await circles('traffic');

        expect(wide.length).toBe(2);

        /*
         * A quarter of a row against a third of one. Not the 1.5x this used to
         * ask for -- the pie was half a row when it was the only one -- but
         * still a real difference, and still enough for the same-circle
         * assertion below to be about something: at equal widths it would be
         * comparing a number to itself.
         */
        expect(wide[0].widget,
            'the wide pie is no longer in a wider widget, so this proves nothing')
            .toBeGreaterThan(narrow[0].widget * 1.2);

        // Same circle in all four, within a pixel of rounding.
        const all = [...narrow, ...wide].map((p) => p.circle);

        expect(Math.min(...all)).toBeGreaterThan(0);
        expect(Math.max(...all) - Math.min(...all),
            `pie circles differ across reports: ${JSON.stringify(all)}`)
            .toBeLessThanOrEqual(2);
    });

    /**
     * The plot area is a FIXED BOX, and the legend fits inside it.
     *
     * The slice names sit to the right of the circle, so a plot that tracked
     * its container would take that room away as the widget narrowed --
     * squeezing the legend into the pie or off the canvas. A circle and a
     * legend need what they need; below that the widget scrolls, which is the
     * answer a table already gets when its columns stop fitting.
     */
    test('a pie plot is a fixed box with its legend inside it', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        const boxes = async (reportId, width) => {

            await page.setViewportSize({ width, height: 1500 });

            await page.goto(
                `?owa_do=base.report&owa_reportId=${reportId}&owa_siteId=${FIXTURE.siteId}`
                + '&owa_period=last_thirty_days',
                { waitUntil: 'networkidle' }
            );

            await page.waitForSelector('.owa_pieChart canvas', { timeout: 20_000 });
            await page.waitForTimeout(1000);

            return page.evaluate(() => [...document.querySelectorAll('.owa_pieChart')].map((h) => {

                const plot = h.getBoundingClientRect();

                /*
                 * The CIRCLE, from the painted canvas -- not the plot element,
                 * which is the box the circle sits in and says nothing about
                 * where it actually is.
                 */
                let circle = null;

                for (const c of h.querySelectorAll('canvas')) {

                    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;

                    let x0 = 1e9, x1 = -1, y0 = 1e9, y1 = -1, n = 0;

                    for (let y = 0; y < c.height; y++) {
                        for (let x = 0; x < c.width; x++) {
                            if (d[(y * c.width + x) * 4 + 3] > 20) {
                                n++;
                                if (x < x0) { x0 = x; }
                                if (x > x1) { x1 = x; }
                                if (y < y0) { y0 = y; }
                                if (y > y1) { y1 = y; }
                            }
                        }
                    }

                    if (n > 500) {
                        const r = c.getBoundingClientRect();
                        circle = { l: r.left + x0, r: r.left + x1, t: r.top + y0, b: r.top + y1 };
                    }
                }

                // flot's .legend is a full-width positioned block; the TABLE is
                // the part with the names in it.
                const legend = h.querySelector('.legend');
                const table = legend ? legend.querySelector('table') : null;
                const t = table ? table.getBoundingClientRect() : null;

                return {
                    plot: { w: Math.round(plot.width), h: Math.round(plot.height) },
                    hasLegend: !!table,
                    legendInside: t
                        ? t.left >= plot.left - 1 && t.right <= plot.right + 1
                        : null,
                    overlapsCircle: (circle && t)
                        ? !(t.left >= circle.r || t.right <= circle.l
                            || t.top >= circle.b || t.bottom <= circle.t)
                        : null,
                };
            }));
        };

        const seen = [];

        // Two reports, and a widget twice as wide on one of them.
        for (const [reportId, width] of [['dashboard', 1600], ['dashboard', 1000], ['traffic', 1600]]) {
            seen.push(...await boxes(reportId, width));
        }

        expect(seen.length).toBeGreaterThanOrEqual(4);

        for (const p of seen) {

            expect(p.hasLegend, 'a pie drew no legend').toBe(true);

            expect(p.legendInside,
                'the legend is outside the plot area it is drawn in').toBe(true);

            /*
             * THE ASSERTION THAT MATTERS, and the one this test did not make
             * at first: inside the plot is not the same as clear of the pie.
             *
             * flot positions the circle by MEASURING the legend -- the width of
             * a background div it prepends, and only prepends when
             * backgroundOpacity is non-zero -- then shifting the centre by half
             * of it. That measurement came back as 40px for a legend 119px
             * wide, so Traffic Sources drew its names across its own pie while
             * "the legend is inside the plot" stayed true throughout.
             *
             * The legend has a reserved column now and the offset is derived
             * from it, so nothing is measured and nothing can be measured
             * wrong.
             */
            expect(p.overlapsCircle,
                'the legend is drawn over the pie').toBe(false);
        }

        // ...and every plot is the SAME box, whatever holds it.
        const sizes = [...new Set(seen.map((p) => `${p.plot.w}x${p.plot.h}`))];

        expect(sizes, `pie plots differ in size: ${sizes.join(', ')}`).toHaveLength(1);
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

        const canvasWidth = () => page.evaluate(() => {
            const c = document.querySelector('.owa_areaChart canvas');
            const h = document.querySelector('.owa_areaChart');
            return c && h ? {
                canvas: Math.round(c.getBoundingClientRect().width),
                holder: Math.round(h.getBoundingClientRect().width),
            } : null;
        });

        await page.setViewportSize({ width: 1600, height: 1000 });

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
        await page.setViewportSize({ width: 1600, height: 1000 });

        await expect.poll(async () => {
            const m = await canvasWidth();
            return m.canvas === m.holder;
        }, { timeout: 10_000 }).toBe(true);

        expect((await canvasWidth()).canvas).toBe(wide.canvas);

        // Twice, so a single lucky redraw cannot pass this.
        await page.setViewportSize({ width: 800, height: 1000 });

        await expect.poll(async () => (await canvasWidth()).canvas, { timeout: 10_000 })
            .toBeLessThan(wide.canvas);

        await page.setViewportSize({ width: 1600, height: 1000 });

        await expect.poll(async () => (await canvasWidth()).canvas, { timeout: 10_000 })
            .toBe(wide.canvas);
    });

    /**
     * ...and so does the GRID, which never resized at all.
     *
     * jqGrid's `autowidth` fits the container once, at build time, and never
     * looks again. A grid built at 1285px stayed 1285px inside a widget that
     * had become 685px -- it did not overflow the page, because the widget
     * scrolls, so it simply became a table you had to scroll sideways to read.
     * That is the "widgets collapse when the window is resized" report.
     */
    test('a grid is refitted to its widget when the window changes', async ({ page }) => {
        test.setTimeout(120_000);

        await login(page);

        /*
         * Latest Visits, not the trend's companion grid: Content's trend is a
         * card now and cannot be broken out, so no shipped report has one. Any
         * grid answers this question -- it is about jqGrid refitting to its
         * widget, not about which report it is on.
         */
        const measure = () => page.evaluate(() => {
            const w = document.querySelector('#latest-visits');
            const g = document.querySelector('#latest-visits .ui-jqgrid');
            return w && g ? {
                widget: Math.round(w.getBoundingClientRect().width),
                grid:   Math.round(g.getBoundingClientRect().width),
            } : null;
        });

        await page.setViewportSize({ width: 1600, height: 1000 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#latest-visits tr.jqgrow', { timeout: 20_000 });

        const wide = await measure();

        // Within a couple of pixels of its widget: jqGrid rounds.
        expect(Math.abs(wide.grid - wide.widget)).toBeLessThan(6);

        await page.setViewportSize({ width: 1000, height: 1000 });

        await expect.poll(async () => {
            const m = await measure();
            return Math.abs(m.grid - m.widget) < 6;
        }, { timeout: 10_000 }).toBe(true);

        const narrow = await measure();
        expect(narrow.grid).toBeLessThan(wide.grid);

        // ...and back, so this is a refit rather than a one-way shrink.
        await page.setViewportSize({ width: 1600, height: 1000 });

        await expect.poll(async () => {
            const m = await measure();
            return Math.abs(m.grid - m.widget) < 6;
        }, { timeout: 10_000 }).toBe(true);

        expect((await measure()).grid).toBeGreaterThan(narrow.grid);
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

        await page.waitForSelector('#latest-visits tr.jqgrow', { timeout: 20_000 });

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

        await page.setViewportSize({ width: 1280, height: 1000 });

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
