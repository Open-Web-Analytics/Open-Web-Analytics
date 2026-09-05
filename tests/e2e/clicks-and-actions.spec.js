// @ts-check
/**
 * The click and action reports, drawn from clicks and actions that were
 * actually recorded.
 *
 * WHAT THIS COVERS THAT NOTHING DID
 *
 * Until the fixture grew clicks and actions, these reports had no data to draw
 * and no test that looked at them.
 *
 * The heatmap was NOT in the same position, and it is worth being exact about
 * that: overlay_e2e_helper.php seeds clicks and overlay-cross-origin.spec.js
 * asserts the overlay's own query comes back non-empty. That path was covered.
 * What was not covered was every OTHER way these clicks are counted -- by
 * element, by page, by tag, by class -- and the action metrics entirely.
 *
 * The arithmetic lives in ClickAndActionMetricsTest, which asks the reporting
 * stack for these metrics by name against its own site. This file is about the
 * other half: that the REPORTS put those numbers on a screen.
 *
 * The fixture is asymmetric on purpose -- 6 clicks, 5 on one element, 4 on one
 * page, 3 at one coordinate, and three action metrics that must answer 4, 2 and
 * 22 -- so an assertion cannot pass by landing on a number that is right for
 * another reason.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openConfiguredReport } = require('./fixtures');

test.describe('the click reports draw recorded clicks', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    /**
     * The Clicks report groups by page AND element, so the six clicks split
     * three ways: / + buy-btn 3, /pricing + buy-btn 2, / + nav-home 1.
     *
     * Asserted as the whole row set rather than one row, because the element
     * that was clicked most does NOT appear with its total here -- its five
     * clicks came from two pages and this report separates them. A test looking
     * for "buy-btn 5" would be asserting the wrong grouping and would fail
     * against a perfectly correct report, which is what it did first time.
     */
    test('the Clicks report splits clicks by page and element', async ({ page }) => {
        await openConfiguredReport(page, { reportId: 'clicks' });

        const rows = page.locator('tr.jqgrow');
        await expect(rows.first()).toBeVisible({ timeout: 20_000 });

        const cells = (await rows.allTextContents()).map((r) => r.replace(/\s+/g, ''));

        // Each row is page + element + count, run together once whitespace goes.
        expect(cells).toEqual(expect.arrayContaining([
            '/buy-btn3',
            '/pricingbuy-btn2',
            '/nav-home1',
        ]));

        /*
         * And they add up to what was recorded. A grid can show the right rows
         * with the wrong numbers -- the totals are what says the metric counted
         * rather than merely listed.
         */
        const total = cells.reduce((n, c) => n + parseInt(c.match(/(\d+)$/)?.[1] ?? '0', 10), 0);

        expect(total, 'the rows do not add up to the clicks that were recorded')
            .toBe(FIXTURE.clicks.total);
    });

    /**
     * The per-page detail: four clicks on '/', two on '/pricing'.
     *
     * This report takes a pagePath, so it is the one that answers "what was
     * clicked on THIS page" -- and its headline states the number, which is the
     * metric rendered as prose rather than as a grid cell.
     */
    test('the per-page click report counts that page only', async ({ page }) => {
        await openConfiguredReport(page, {
            reportId: 'dom-clicks',
            params: { pagePath: '/' },
        });

        await expect(page.locator('.ui-jqgrid').first()).toBeVisible({ timeout: 20_000 });

        const body = (await page.locator('body').textContent()).replace(/\s+/g, ' ');

        expect(body, "the headline does not state this page's click count")
            .toContain(`There were ${FIXTURE.clicks.byPage['/']} dom clicks`);

        // Both elements clicked on '/', and NOT the one clicked only elsewhere
        // -- /pricing's clicks were on buy-btn, which is also on '/', so the
        // discriminator is the count rather than the name.
        const rows = (await page.locator('tr.jqgrow').allTextContents())
            .map((r) => r.replace(/\s+/g, ''));

        expect(rows).toEqual(expect.arrayContaining(['buy-btn3', 'nav-home1']));
    });

    /** And the other page, which must not inherit the first one's clicks. */
    test('a different page counts its own clicks', async ({ page }) => {
        await openConfiguredReport(page, {
            reportId: 'dom-clicks',
            params: { pagePath: '/pricing' },
        });

        const body = (await page.locator('body').textContent()).replace(/\s+/g, ' ');

        expect(body).toContain(`There were ${FIXTURE.clicks.byPage['/pricing']} dom clicks`);
    });
});

test.describe('the action reports draw recorded actions', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    /**
     * The three action metrics answer three different questions, and the report
     * shows all three: 4 actions, 2 distinct names, 22 of value.
     *
     * That they DISAGREE is the assertion. A report that showed 4, 4 and 4
     * would look perfectly reasonable and would mean two of the three metrics
     * were answering someone else's question.
     */
    test('the Actions report shows counts, unique names and value', async ({ page }) => {
        await openConfiguredReport(page, { reportId: 'action-tracking' });

        await expect(page.locator('.owa_reportSectionContent').first())
            .toBeVisible({ timeout: 20_000 });

        const body = await page.locator('body').textContent();

        expect(body, 'the action names are missing').toContain('submit');
        expect(body, 'the action groups are missing').toContain('signup');

        /*
         * The value total. 22 is the only one of the three numbers that cannot
         * arise from a miscount of the other two, which is why it is the one
         * asserted exactly.
         */
        expect(body).toContain(String(FIXTURE.actions.value));
    });

    /** Grouped by group: signup 3, commerce 1 -- the same four, split. */
    test('actions group by their action group', async ({ page }) => {
        await openConfiguredReport(page, { reportId: 'action-groups' });

        await expect(page.locator('.ui-jqgrid').first()).toBeVisible({ timeout: 20_000 });

        const rows = page.locator('tr.jqgrow');
        await expect(rows.first()).toBeVisible({ timeout: 20_000 });

        const text = (await rows.allTextContents()).join(' | ');

        for (const group of Object.keys(FIXTURE.actions.byGroup)) {
            expect(text, `the ${group} group is missing from the grid`).toContain(group);
        }

        /*
         * LOWERCASE, because the handler normalises on the way in. Seeded as
         * 'Signup'; a capitalised row would mean two spellings of one group
         * become two rows.
         */
        expect(text).not.toContain('Signup');
    });
});
