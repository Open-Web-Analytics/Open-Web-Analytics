const { test, expect } = require('@playwright/test');
const { FIXTURE, loginAs } = require('./fixtures');

/**
 * A trend, and the grid of the rows behind it.
 *
 * A trend broken out by a dimension draws a line per value, and the question a
 * reader asks next is always the same one: which values, and how much each.
 * That is a grid, so a broken-out trend grows one under its metric boxes.
 *
 * WHAT THIS SUITE IS ACTUALLY FOR is the coupling between them, because that is
 * the part with a decision in it. Three controls sit on the grid's bar and each
 * one means something different:
 *
 *   - the FIRST dimension is what the report is about, so it drives both.
 *   - a SECOND dimension refines the ROWS. A trend cannot draw it -- a line per
 *     (medium, browser) pair is not a trend of anything -- so it must not try.
 *   - a FILTER narrows the POPULATION, which is a statement about who is being
 *     counted, so a chart and a table disagreeing about it would be two answers
 *     to one question.
 *
 * Every one of those is a browser-only question: it is an event fired by a
 * control, a URL rewritten in JavaScript and a refetch. Nothing about it is
 * visible to a PHP test, which can see only that the template emitted a second
 * result-set explorer.
 *
 * Content Overview is the fixture: its trend is pageViews broken out by
 * pagePath, over four seeded pages and five visits across five days.
 *
 * Prereq: run `php tests/e2e/seed_reporting_fixtures.php seed` first.
 */

/*
 * An AUTHORED report, not a shipped one.
 *
 * No shipped report has a broken-out trend any more: Content's became a card,
 * and a card cannot be broken out. The feature is still reachable to anyone
 * building a report, so it is driven through one -- rather than giving a
 * shipped report a shape nobody asked it to have, purely to keep a test alive.
 *
 * The seeder plants it; this finds it by name on the roster, because a report
 * id is minted at seed time and cannot be written down here.
 */
let breakdownUrl = '';

async function findBreakdownReport(page) {

    if (breakdownUrl) {

        return breakdownUrl;
    }

    await page.goto('?owa_do=base.customReports', { waitUntil: 'networkidle' });

    const href = await page
        .locator(`table.owa_customReportRoster a:text-is("${FIXTURE.breakdownReportName}")`)
        .first().getAttribute('href');

    expect(href, 'the broken-out-trend fixture is missing -- re-run the seeder').toBeTruthy();

    const id = (href.match(/reportId=(custom-[0-9]+)/) || [])[1];

    expect(id, `no report id in the roster link: ${href}`).toBeTruthy();

    breakdownUrl = `?owa_do=base.report&owa_reportId=${id}&owa_siteId=${FIXTURE.siteId}`
        + '&owa_period=last_thirty_days';

    return breakdownUrl;
}

/** The trend's own query, as the explorer last fetched it. */
const trendQuery = (page) => page.evaluate(() => ({
    dimensions: new URL(window.trend.resultSet.self).searchParams.get('dimensions'),
    constraints: new URL(window.trend.resultSet.self).searchParams.get('constraints'),
    // What the chart believes it is broken out by, which is a separate thing
    // from the URL: they disagreeing is exactly the bug worth catching.
    series: window.trend.areaChart.options.series[0].series || '',
}));

const breakdownRows = (page) => page.locator('#trend-breakdown tr.jqgrow');

/** Column headings of the companion grid, trimmed. */
async function breakdownColumns(page) {
    return (await page.locator('#trend-breakdown .ui-jqgrid-htable th').allInnerTexts())
        .map(h => h.trim()).filter(Boolean);
}

/**
 * Choose a dimension in one of the grid's pickers, through the real click path.
 *
 * chosen hides the native <select> and renders its own field, so selectOption()
 * cannot reach it -- and setting the hidden select directly would pass with the
 * visible control broken, which is the only part a reader touches.
 */
async function pickDimension(page, index, label) {
    const picker = page.locator('#trend-breakdown .owa_dimSlot .chosen-container').nth(index);

    await picker.click();
    await picker.locator('.chosen-results li.active-result', { hasText: new RegExp(`^${label}$`) })
        .first().click();
}

async function openContent(page) {
    await page.setViewportSize({ width: 1400, height: 1200 });

    await page.goto(await findBreakdownReport(page), { waitUntil: 'networkidle' });

    // The grid builds from its own fetch, so wait for a row rather than the
    // container -- the container is in the markup before anything is queried.
    await page.waitForSelector('#trend-breakdown tr.jqgrow', { timeout: 20_000 });
}

test.describe('a broken-out trend and its companion grid', () => {

    test.beforeEach(async ({ page }) => {
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        page.__owaErrors = errors;

        // The author, because the roster lists only your own reports and the
        // fixture report is found there by name.
        await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
    });

    test('a trend with a breakdown grows a grid of those rows', async ({ page }) => {
        await openContent(page);

        // One row per seeded page.
        await expect(breakdownRows(page)).toHaveCount(4);
        expect(await breakdownColumns(page)).toContain('Page Path');

        /*
         * ITS OWN QUERY, not a view of the trend's. The trend is grouped by
         * (date, pagePath) so it has a shape over time; the grid is grouped by
         * pagePath alone so it is a ranking. Deriving one from the other would
         * mean summing rows in the browser.
         */
        const gridUrl = await page.evaluate(
            () => new URL(window.trendBreakdown.resultSet.self).searchParams.get('dimensions'));

        expect(gridUrl).toBe('pagePath');
        expect((await trendQuery(page)).dimensions).toBe('date,pagePath');

        // Ordered by what the chart draws, so the top rows are the top lines.
        const sort = await page.evaluate(
            () => new URL(window.trendBreakdown.resultSet.self).searchParams.get('sort'));

        expect(sort).toBe('pageViews-');

        expect(page.__owaErrors).toEqual([]);
    });

    /**
     * A trend over date ALONE is the filled area a trend has always been, and
     * there is no breakdown to list. Guards the condition rather than the
     * feature: a grid under every trend in the install would be the failure.
     */
    test('a trend with no breakdown grows no grid', async ({ page }) => {
        await page.goto(
            `?owa_do=base.report&owa_reportId=browsers&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days',
            { waitUntil: 'networkidle' });

        await page.waitForSelector('#report-tabs.ui-tabs', { timeout: 20_000 });

        await expect(page.locator('[id$="-breakdown"]')).toHaveCount(0);
    });

    test('changing the grid\'s first dimension re-breaks out the trend', async ({ page }) => {
        await openContent(page);

        const before = await trendQuery(page);
        expect(before.series).toBe('pagePath');

        await pickDimension(page, 0, 'Medium');

        // The grid regrouped...
        await expect.poll(async () => await breakdownColumns(page), { timeout: 15_000 })
            .toContain('Medium');
        expect(await breakdownColumns(page)).not.toContain('Page Path');

        // ...and so did the trend, which is the whole point.
        await expect.poll(async () => (await trendQuery(page)).dimensions, { timeout: 15_000 })
            .toBe('date,medium');

        expect((await trendQuery(page)).series).toBe('medium');

        /*
         * And the reader can SEE it. The chart's legend is a label per line, so
         * a legend still naming pages would mean the URL changed and the chart
         * did not -- which is the failure mode the two assertions above cannot
         * tell apart on their own.
         */
        const legend = await page.locator('#trend-chart .owa_chartLegend').innerText();

        expect(legend).toContain('Total');
        expect(legend).not.toContain('/pricing');

        expect(page.__owaErrors).toEqual([]);
    });

    /**
     * The one that says NOTHING should happen.
     *
     * A second dimension is a way of reading the table, not a different
     * subject. The trend must be left exactly as it was -- and "exactly" is the
     * assertion, because a refetch that happened to come back with the same
     * rows would look identical on screen.
     */
    test('adding a second dimension changes the grid and leaves the trend alone', async ({ page }) => {
        await openContent(page);

        const before = await trendQuery(page);

        await page.locator('#trend-breakdown .owa_dimAdd').first().click();
        await pickDimension(page, 1, 'Medium');

        // The grid gained a column and split its rows.
        await expect.poll(async () => await breakdownColumns(page), { timeout: 15_000 })
            .toContain('Medium');

        expect(await breakdownColumns(page)).toContain('Page Path');

        const after = await trendQuery(page);

        expect(after.dimensions).toBe(before.dimensions);
        expect(after.series).toBe(before.series);

        expect(page.__owaErrors).toEqual([]);
    });

    /**
     * A filter is a statement about who is being counted, so it travels to
     * both. Asserted in both directions -- a constraint that matches nothing
     * and one that matches some -- because a filter that merely reloaded the
     * chart would pass a one-directional test.
     */
    test('a filter on the grid narrows the trend too', async ({ page }) => {
        await openContent(page);

        const setFilter = async (value) => {

            const toggle = page.locator('#trend-breakdown .constraintPickerContainer > .toggle-button');
            const panel  = page.locator('#trend-breakdown .constraintPickerContainer > .builder');

            if (!(await panel.isVisible())) {
                await toggle.click();
            }

            await expect(panel).toBeVisible();

            await page.evaluate((val) => {
                const row = document.querySelector(
                    '#trend-breakdown .constraintPickerContainer .builder li.constraintRow');

                jQuery(row).find('.constraintDimensionPicker select.dim-list')
                    .val('pagePath').trigger('chosen:updated');
                jQuery(row).find('.constraintOperatorPicker select.operator-list').val('=@');
                jQuery(row).find('.constraintValueField').val(val);
            }, value);

            await page.locator('#trend-breakdown .apply-button').click();
        };

        // Matches one of the four seeded pages.
        await setFilter('docs');

        await expect(breakdownRows(page)).toHaveCount(1, { timeout: 15_000 });

        await expect.poll(async () => (await trendQuery(page)).constraints, { timeout: 15_000 })
            .toBe('pagePath=@docs');

        // Matches none of them: the grid empties and the trend goes with it.
        await setFilter('nosuchpage');

        await expect(breakdownRows(page)).toHaveCount(0, { timeout: 15_000 });

        await expect.poll(async () => (await trendQuery(page)).constraints, { timeout: 15_000 })
            .toBe('pagePath=@nosuchpage');

        expect(page.__owaErrors).toEqual([]);
    });
});
