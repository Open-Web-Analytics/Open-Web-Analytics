const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Funnels: their own screens, because a funnel is its own thing.
 *
 * They were a section on the goal event form, which said a funnel belongs to a
 * goal event and cannot exist without one. It can: "where do people drop out of
 * checkout" is a question about a path, and answering it should not require
 * first declaring something worth counting.
 */

async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

async function confirmAndWait(page, locator) {
    await locator.click();
    await expect(page.locator('#owa_confirmDialog')).toBeVisible();

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('.owa_confirmProceed').click(),
    ]);
}

test.describe('funnels', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('create with steps, edit, then delete', async ({ page }) => {
        const name = 'E2E Funnel ' + Date.now();

        await gotoAction(page, 'base.funnelEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', name);

        // A funnel can count as NOTHING. That option existing is the whole
        // point of the loose coupling.
        await expect(page.locator('select[name="goalEventId"] option[value=""]')).toHaveCount(1);

        // Steps are a repeatable list, sharing the report builder's row markup.
        const steps = page.locator('#owa_goalEventFunnel .constraintRow');

        await page.locator('#owa_goalEventFunnel .constraintAddButton').first().click();
        await expect(steps).toHaveCount(2);

        await page.locator('#owa_goalEventFunnel .constraintRemoveButton').first().click();
        await expect(steps).toHaveCount(1);

        // The last row is cleared rather than removed, or there would be no way
        // to add a step back without reloading.
        await page.locator('#owa_goalEventFunnel .constraintRemoveButton').first().click();
        await expect(steps).toHaveCount(1);

        await page.locator('input[name="stepName[]"]').first().fill('Basket');
        await page.locator('input[name="stepPath[]"]').first().fill('/basket');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Funnel"]').click(),
        ]);

        const row = page.locator('table.management tbody tr', { hasText: name });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('1');

        // --- EDIT --------------------------------------------------------------
        const href = await row.locator('a[href*="base.funnelEdit"]').first().getAttribute('href');
        const params = new URL(href, page.url()).searchParams;
        const id = params.get('owa_funnelId') || params.get('funnelId');

        expect(id, 'the list must expose the funnel id').toBeTruthy();

        await gotoAction(page, 'base.funnelEdit', `&owa_siteId=${FIXTURE.siteId}&owa_funnelId=${id}`);

        await expect(page.locator('input[name="name"]')).toHaveValue(name);
        await expect(page.locator('input[name="stepPath[]"]').first()).toHaveValue('/basket');

        // --- DELETE ------------------------------------------------------------
        await confirmAndWait(page, page.locator('input[value="Delete Funnel"]'));

        await expect(
            page.locator('table.management tbody tr', { hasText: name })
        ).toHaveCount(0);
    });

    /**
     * A funnel is nothing but its steps, so one with none describes no path.
     *
     * The opposite of the old rule, and deliberately: most GOALS never had a
     * funnel, so having no steps had to be normal there. A funnel is created on
     * purpose.
     */
    test('a funnel with no steps is refused', async ({ page }) => {
        await gotoAction(page, 'base.funnelEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Empty Funnel');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Funnel"]').click(),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Empty Funnel');
    });

    /**
     * A step is a PATH.
     *
     * Every consumer matches on the path alone, so a full web address matches
     * nothing: the funnel reports zero with nothing logged. Refused rather than
     * silently trimmed.
     */
    test('a step given a full web address is refused', async ({ page }) => {
        await gotoAction(page, 'base.funnelEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Bad Step');
        await page.locator('input[name="stepName[]"]').first().fill('Basket');
        await page.locator('input[name="stepPath[]"]').first().fill('https://example.test/basket');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Funnel"]').click(),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Bad Step');
    });
});
