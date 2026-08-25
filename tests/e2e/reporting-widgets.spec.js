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
