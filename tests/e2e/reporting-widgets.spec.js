const { test, expect } = require('@playwright/test');
const { login, openConfiguredReport, configuredReportIds } = require('./fixtures');

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
         * The grids load and dispatch, which is as much as this fixture can
         * show.
         *
         * seed_reporting_fixtures.php seeds page views and transactions and no
         * referrer at all -- no medium, no source, no search terms. So Traffic's
         * three grids legitimately return zero rows here, and the explorer takes
         * its empty branch, which writes a message instead of building a grid.
         * Asserting a grid would be asserting the fixture, not the renderer.
         *
         * What IS checkable is that each container was reached and rendered one
         * of the two outcomes. A widget whose explorer never loaded -- the
         * failure this whole spec exists to catch -- leaves its container empty.
         *
         * Seeding referral traffic would make this a real assertion;
         * reporting_facets_helper.php already provisions source/medium data for
         * the API-level facet tests and is the obvious place to borrow from.
         */
        test('each grid widget loads and renders an outcome', async ({ page }) => {
            for (const container of ['top-sources', 'top-referrals', 'top-keywords']) {

                const cell = page.locator(`#${container}`);

                await expect(cell, `${container} was never rendered`)
                    .toHaveCount(1);

                await expect(cell, `${container} stayed empty -- its explorer never loaded`)
                    .not.toBeEmpty({ timeout: 20_000 });

                const built = await cell.locator('.ui-jqgrid').count();
                const empty = (await cell.innerText()).includes('No data is available');

                expect(built === 1 || empty,
                    `${container} rendered neither a grid nor an empty-state`).toBe(true);
            }
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
