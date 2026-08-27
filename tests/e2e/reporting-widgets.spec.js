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

        test('the pie chart paints a canvas of its own', async ({ page }) => {
            await expect(page.locator('#traffic-sources canvas').first())
                .toBeVisible({ timeout: 20_000 });
        });

        test('each metric-boxes widget draws its own labelled box', async ({ page }) => {
            // Three boxes, one per medium, each into its OWN container -- the
            // template this replaced appended all three into one element, so
            // separate containers are the thing worth checking.
            for (const [container, label] of [
                ['trend-metrics-search',   'Visits From Search Engines'],
                ['trend-metrics-direct',   'Visits From Direct Navigation'],
                ['trend-metrics-referral', 'Visits From Referrals'],
            ]) {
                const box = page.locator(`#${container} .owa_metricInfobox`).first();

                await expect(box, `${container} drew no metric box`)
                    .toBeVisible({ timeout: 20_000 });

                await expect(box).toContainText(label);
            }
        });

        /**
         * Each grid builds and shows the traffic it was seeded.
         *
         * The fixture attributes its four visits -- two organic-search from
         * different engines, one referral, one direct -- so each of these three
         * grids has rows of its own to draw. Asserting the CONTENT is what
         * separates "the widget rendered" from "the widget rendered the right
         * query": top-keywords is constrained to organic-search, so a widget
         * that dropped its constraint would show the referral traffic too.
         */
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

        test('the keywords grid lists only organic-search terms', async ({ page }) => {
            const grid = page.locator('#top-keywords .ui-jqgrid');

            await expect(grid, 'top-keywords built no grid').toHaveCount(1, { timeout: 20_000 });

            const cell = page.locator('#top-keywords');

            for (const term of FIXTURE.traffic.searchTerms) {
                await expect(cell).toContainText(term);
            }

            /*
             * The widget's own constraint at work, measured by ROW COUNT.
             *
             * Checking that the referral's host is absent looked like a
             * constraint test and was vacuous: the referral visit has no search
             * term, so its host could never appear in a grid of search terms
             * whether the constraint applied or not. Removing the constraint
             * from the definition left that assertion passing.
             *
             * What the constraint actually changes is which SESSIONS reach the
             * grid. Unconstrained, the referral and direct visits arrive too --
             * with no search term of their own -- and the grid grows rows.
             */
            await expect(cell.locator('tr.jqgrow'),
                'the keywords grid is not constrained to organic-search')
                .toHaveCount(FIXTURE.traffic.searchTerms.length);
        });

        /**
         * The related-reports block, which used to be hand-written markup and
         * printed its own title twice.
         */
        test('the related reports are listed once', async ({ page }) => {
            await expect(page.locator('.owa_reportSectionHeader', { hasText: 'Related Reports' }))
                .toHaveCount(1);

            await expect(page.locator('.relatedReports a')).toHaveCount(4);
        });
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

        const measure = () => page.evaluate(() => {
            const w = document.querySelector('#trend-breakdown');
            const g = document.querySelector('#trend-breakdown .ui-jqgrid');
            return w && g ? {
                widget: Math.round(w.getBoundingClientRect().width),
                grid:   Math.round(g.getBoundingClientRect().width),
            } : null;
        });

        await page.setViewportSize({ width: 1600, height: 1000 });

        await page.goto(
            `?owa_do=base.report&owa_reportId=content&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#trend-breakdown tr.jqgrow', { timeout: 20_000 });

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
            `?owa_do=base.report&owa_reportId=content&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' }
        );

        await page.waitForSelector('#trend-breakdown tr.jqgrow', { timeout: 20_000 });

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
