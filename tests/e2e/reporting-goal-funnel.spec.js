// @ts-check
/**
 * The goal funnel renders every stage, including the one it appends itself.
 *
 * ReportGoalFunnel draws a bar per configured funnel step and then appends ONE
 * MORE from the goal's own goal_url -- the conversion itself, the last bar. That
 * appended element is built by the report rather than read from the goal, and
 * when the step key was renamed from `url` to `path` the constructor was missed:
 * the last bar constrained on `pagePath==`, matched nothing, and reported zero.
 *
 * Nothing caught it. Reaching that loop needs a configured funnel, a goalNumber
 * and traffic on the step paths, and no install had a single funnel step until
 * the seeder grew one.
 *
 * So this asserts CONTENT, not that a page rendered: the stage names, their
 * paths, and a non-zero visitor count on the appended stage -- which is the
 * assertion the regression would have failed.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

async function openFunnel(page) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=goal-funnel`
        + `&owa_siteId=${FIXTURE.siteId}`
        + `&owa_goalNumber=${FIXTURE.goal.number}`
        + `&owa_period=last_thirty_days`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });
}

/** The funnel drawn with a given counting scope. */
async function openFunnelAs(page, scope) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=goal-funnel`
        + `&owa_siteId=${FIXTURE.siteId}`
        + `&owa_goalNumber=${FIXTURE.goal.number}`
        + `&owa_period=last_thirty_days`
        + `&owa_funnelScope=${scope}`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });
}

test.describe('reporting: goal funnel', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('draws a stage for every configured step', async ({ page }) => {
        await openFunnel(page);

        // Assert per stage, not against the bare class: the name has to land on
        // the stage at its own step number, and a locator matching all three is
        // both strict-mode ambiguous and a weaker claim.
        for (const [i, step] of FIXTURE.goal.steps.entries()) {
            const stage = page.locator('.owa_funnelStepColumn').nth(i);

            await expect(stage.locator('.funnelStepName')).toContainText(step.name);
            await expect(stage.locator('.funnelStepPath')).toHaveText(step.path);
        }
    });

    /**
     * The regression guard. The goal's destination is the funnel's last bar and
     * is the only stage the report builds rather than reads, so it is the only
     * one a key mismatch can silently empty.
     */
    test('draws the appended goal destination, with visitors', async ({ page }) => {
        await openFunnel(page);

        const stages = page.locator('.owa_funnelStepColumn');
        await expect(stages).toHaveCount(FIXTURE.goal.steps.length + 1);

        const last = stages.last();

        await expect(last).toContainText(FIXTURE.goal.destination);

        // The fixture walks one visitor through every step in order, so the
        // goal stage MUST count somebody. Zero here means either the appended
        // stage is constrained on a path that matches nothing, or the ordering
        // dropped a visitor who really did go through.
        await expect(last).not.toContainText('0 visitors');
    });

    test('every stage counts visitors, so none constrained on nothing', async ({ page }) => {
        await openFunnel(page);

        const counts = await page.locator('.funnelStepCount').allTextContents();

        expect(counts.length).toBe(FIXTURE.goal.steps.length + 1);
        expect(counts.every((c) => /\d+\s*visitors/.test(c))).toBe(true);
        expect(counts.some((c) => /^0\s*visitors/.test(c.trim()))).toBe(false);
    });

    /**
     * The funnel is ORDERED: a step counts only those who reached it after the
     * step before. So a stage can never out-count the one in front of it, and a
     * fixture visitor who saw a later page without entering the funnel must not
     * appear in that later stage.
     */
    test('no stage out-counts the stage before it', async ({ page }) => {
        await openFunnel(page);

        const counts = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        for (let i = 1; i < counts.length; i++) {
            expect(counts[i]).toBeLessThanOrEqual(counts[i - 1]);
        }
    });

    test('the control bar offers the goal, the scope and an edit link', async ({ page }) => {
        await openFunnel(page);

        await expect(page.locator('.owa_funnelControls #goalChooser')).toBeVisible();
        await expect(page.locator('#funnelScopeSwitch')).toBeVisible();
        await expect(page.locator('.owa_funnelControls a[href*="optionsGoalEntry"]')).toBeVisible();
    });

    /**
     * Visits and visitors are different questions, and the fixture answers them
     * differently: one visitor completes the funnel across several visits, so
     * counting visits gives more entrants and a lower conversion rate.
     */
    test('the counting scope changes what is counted', async ({ page }) => {
        await openFunnelAs(page, 'visitor');
        const byVisitor = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));
        await expect(page.locator('.visitorCountLabel').first()).toHaveText('visitors');

        await openFunnelAs(page, 'session');
        const bySession = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));
        await expect(page.locator('.visitorCountLabel').first()).toHaveText('visits');

        expect(bySession[0]).toBeGreaterThan(byVisitor[0]);
    });

    /**
     * The segment filter: which people the funnel is drawn for.
     *
     * It offers what the reporting stack offers, so a name it lists is a name
     * the segment will actually accept -- a hand-written list would offer names
     * the query then refuses.
     */
    test('the control bar offers a filter, behind its own toggle', async ({ page }) => {
        await openFunnel(page);

        await expect(page.locator('#funnelFilter .constraintPickerContainer')).toBeVisible();

        // Behind a toggle: the builder itself starts hidden.
        await expect(page.locator('#funnelFilter .builder')).toBeHidden();

        await page.locator('#funnelFilter .toggle-button').click();
        await expect(page.locator('#funnelFilter .builder')).toBeVisible();
    });

    test('the filter offers dimensions the segment accepts', async ({ page }) => {
        await openFunnel(page);

        await page.locator('#funnelFilter .toggle-button').click();

        // Opening the builder already offers one empty constraint row, so this
        // does not add another -- clicking "add" would give two pickers and
        // every assertion below would count double.
        const options = page.locator('#funnelFilter .constraintDimensionPicker option');

        // A real list, not an empty picker.
        expect(await options.count()).toBeGreaterThan(10);

        // medium is what the segment is most obviously useful for, and it is one
        // the funnel's outer query can resolve.
        expect(await page.locator('#funnelFilter .constraintDimensionPicker option[value="medium"]').count())
            .toBeGreaterThan(0);
    });

    /**
     * A segment selects the PEOPLE and the funnel is then counted over all of
     * their pages, so a segment matching nobody empties the funnel rather than
     * leaving it unsegmented.
     */
    test('a segment that matches nobody empties the funnel', async ({ page }) => {
        await page.goto(
            `?owa_do=base.report&owa_reportId=goal-funnel`
            + `&owa_siteId=${FIXTURE.siteId}`
            + `&owa_goalNumber=${FIXTURE.goal.number}`
            + `&owa_period=last_thirty_days`
            + `&owa_constraints=` + encodeURIComponent('medium==no-such-medium'),
            { waitUntil: 'networkidle' }
        );
        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        const counts = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(counts.every((n) => n === 0)).toBe(true);
    });

    test('a refused segment says so rather than showing an unsegmented funnel', async ({ page }) => {
        await page.goto(
            `?owa_do=base.report&owa_reportId=goal-funnel`
            + `&owa_siteId=${FIXTURE.siteId}`
            + `&owa_goalNumber=${FIXTURE.goal.number}`
            + `&owa_period=last_thirty_days`
            + `&owa_constraints=` + encodeURIComponent('bogusDimension==x'),
            { waitUntil: 'networkidle' }
        );

        await expect(page.locator('.notice')).toContainText('not a dimension or a metric');
    });

    /** The steps table is the standard grid, drawn from the computed rows. */
    test('the steps table renders a row per stage', async ({ page }) => {
        await openFunnel(page);

        // jqGrid draws its data rows as tr.jqgrow inside a .ui-jqgrid wrapper.
        await expect(page.locator('#funnel-steps-grid .ui-jqgrid')).toBeVisible();

        const rows = page.locator('#funnel-steps-grid tr.jqgrow');

        await expect(rows).toHaveCount(FIXTURE.goal.steps.length + 1);

        // The rows carry the computed numbers, not just a shell.
        await expect(rows.first()).toContainText(FIXTURE.goal.steps[0].path);

        // Computed rows, so the grid must not offer controls that would
        // re-query a result set URL that does not exist.
        await expect(page.locator('#funnel-steps-grid .constraintPicker')).toHaveCount(0);
    });
});
