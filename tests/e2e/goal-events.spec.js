const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Goal events: the screens that replaced the twenty numbered goal slots.
 *
 * The old screens listed twenty rows whether or not anyone had filled them in,
 * because the storage was a fixed-length array and the screen showed the
 * storage. These list what exists -- so "counts nothing yet" is a state worth
 * asserting, being the one the old screens could not represent.
 */

/** Land on an admin screen by its owa_do action. */
async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

/** Click something destructive and confirm it through the modal. */
async function confirmAndWait(page, locator) {
    await locator.click();
    await expect(page.locator('#owa_confirmDialog')).toBeVisible();

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('.owa_confirmProceed').click(),
    ]);
}

test.describe('goal events', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the list, the create, the edit and the delete', async ({ page }) => {
        const name = 'E2E Signup ' + Date.now();
        const renamed = name + ' renamed';

        await gotoAction(page, 'base.goalEvents', `&owa_siteId=${FIXTURE.siteId}`);

        // Every hierarchy screen says what it is for.
        await expect(page.locator('.owa_panelIntro')).toBeVisible();

        // --- CREATE ------------------------------------------------------------
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        // The condition is a constraint row -- the same markup and class names
        // the report builder uses. That is what makes naming a condition look
        // the same wherever it is done.
        //
        // Scoped to the condition list: the FUNNEL rows are constraint rows too,
        // which is the point, so an unscoped locator matches both and resolves
        // to two elements.
        const condition = page.locator('.owa_goalEventCondition li.constraintRow');

        await expect(condition.locator('.constraintDimensionPicker')).toBeVisible();
        await expect(condition.locator('.constraintOperatorPicker')).toBeVisible();

        // And the funnel really does reuse them, rather than looking like a
        // different application two fields further down the same form.
        await expect(
            page.locator('.owa_goalEventFunnel li.constraintRow')
        ).toHaveCount(1);

        await page.fill('input[name="name"]', name);
        await page.selectOption('select[name="conditionProperty"]', 'page_uri');
        await page.selectOption('select[name="conditionOperator"]', 'begins');
        await page.fill('input[name="conditionValue"]', '/thanks');
        await page.fill('input[name="value"]', '2.50');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        const row = page.locator('table.management tbody tr', { hasText: name });
        await expect(row).toHaveCount(1);

        // The condition shows, and the value survived the trip through cents.
        await expect(row).toContainText('/thanks');
        await expect(row).toContainText('2.50');

        // --- EDIT --------------------------------------------------------------
        const editHref = await row.locator('a[href*="base.goalEventEdit"]').first()
            .getAttribute('href');
        const params = new URL(editHref, page.url()).searchParams;
        const id = params.get('owa_goalEventId') || params.get('goalEventId');

        expect(id, 'the list must expose the goal event id').toBeTruthy();

        await gotoAction(page, 'base.goalEventEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventId=${id}`);

        // It came back carrying what was saved, not a blank form.
        await expect(page.locator('input[name="name"]')).toHaveValue(name);
        await expect(page.locator('input[name="conditionValue"]')).toHaveValue('/thanks');

        await page.fill('input[name="name"]', renamed);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        await expect(
            page.locator('table.management tbody tr', { hasText: renamed })
        ).toHaveCount(1);

        // Edited, not duplicated. The stored id is derived, so saving twice has
        // to update one row rather than leave two behind.
        await expect(page.locator('table.management tbody tr')).toHaveCount(1);

        // --- DELETE ------------------------------------------------------------
        await gotoAction(page, 'base.goalEventEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventId=${id}`);

        await confirmAndWait(page, page.locator('input[value="Delete Goal Event"]'));

        await expect(
            page.locator('table.management tbody tr', { hasText: renamed })
        ).toHaveCount(0);
    });

    /**
     * A condition with nothing to compare against counts nothing, and says
     * nothing about it. This install had a goal in exactly that state -- a type
     * the evaluator has no case for and no URL -- silently never firing since
     * it was made.
     */
    test('a condition with no value is refused', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E No Condition');
        await page.fill('input[name="conditionValue"]', '');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        // Still on the form, carrying what was typed rather than a blank one.
        await expect(page.locator('input[name="name"]')).toHaveValue('E2E No Condition');
    });

    /**
     * A funnel step is a PATH.
     *
     * Every consumer matches on the path alone -- the funnel report builds
     * `pagePath == <this>` and checkGoalStart matches it against page_uri -- so
     * a full web address matches nothing: the funnel reports zero and the goal
     * event never starts, with nothing logged. Refused rather than silently
     * trimmed, because quietly rewriting what someone typed is how they end up
     * not knowing what is stored.
     */
    test('a funnel step given a full web address is refused', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Funnel');
        await page.fill('input[name="conditionValue"]', '/done');
        await page.fill('input[name="stepName[]"]', 'Basket');
        await page.fill('input[name="stepPath[]"]', 'https://example.test/basket');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Funnel');
    });
});
