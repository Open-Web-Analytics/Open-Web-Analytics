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
    await page.waitForSelector('.funnel', { timeout: 20_000 });
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
            const stage = page.locator('.funnelStep').nth(i);

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

        const stages = page.locator('.funnelStep');
        await expect(stages).toHaveCount(FIXTURE.goal.steps.length + 1);

        const last = stages.last();

        await expect(last).toContainText(FIXTURE.goal.destination);
        await expect(last, 'the appended stage must be constrained on a path that matches')
            .not.toContainText('0 visitors');
    });

    test('every stage counts visitors, so none constrained on nothing', async ({ page }) => {
        await openFunnel(page);

        const counts = await page.locator('.funnelStepCount').allTextContents();

        expect(counts.length).toBe(FIXTURE.goal.steps.length + 1);
        expect(counts.every((c) => /\d+\s*visitors/.test(c))).toBe(true);
        expect(counts.some((c) => /^0\s*visitors/.test(c.trim()))).toBe(false);
    });
});
