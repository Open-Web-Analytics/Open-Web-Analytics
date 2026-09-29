const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Custom Dimensions: the list, and registering from its own screen.
 *
 * Registering is base.customDimensionEdit, reached from the list's legend link
 * the way Goal Events reaches its editor. A refused registration comes back to
 * that screen with the registrar's reason beside the name and what was typed
 * still in the fields; an accepted one lands on the list.
 *
 * The fixture site's Property has a cube (seedCube() in the seeder), so the
 * screen offers the form at all. A registration this spec makes, it removes.
 */

async function gotoList(page) {
    await page.goto(`?owa_do=base.customDimensions&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });
}

async function openRegister(page) {
    await gotoList(page);

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.getByRole('link', { name: 'Register New Custom Dimension' }).click(),
    ]);

    await expect(page.locator('.panel_headline')).toHaveText('New Custom Dimension');
}

async function submit(page) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[type=submit][value="Register Custom Dimension"]').click(),
    ]);
}

test.describe('custom dimensions', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the list links to a register screen and carries no form of its own', async ({ page }) => {
        await gotoList(page);

        await expect(page.locator('form[name="owa-custom-dimension-form"]')).toHaveCount(0);

        await openRegister(page);

        await expect(page.locator('#owa-cd-key')).toBeVisible();
        await expect(page.locator('#owa-cd-scope')).toBeVisible();
        await expect(page.locator('#owa-cd-type')).toBeVisible();
    });

    test('a refused name comes back to the register screen with the reason and what was typed', async ({ page }) => {
        await openRegister(page);

        await page.locator('#owa-cd-key').fill('not.a.name');
        await page.locator('#owa-cd-label').fill('Refused label');
        await submit(page);

        await expect(page.locator('.panel_headline')).toHaveText('New Custom Dimension');
        await expect(page.locator('#owa-cd-key ~ .validation_error')).not.toBeEmpty();
        await expect(page.locator('#owa-cd-key')).toHaveValue('not.a.name');
        await expect(page.locator('#owa-cd-label')).toHaveValue('Refused label');
    });

    test('a valid name registers, lands on the list, and can be removed again', async ({ page }) => {
        const key = `e2e_cd_${Date.now()}`;

        await openRegister(page);

        await page.locator('#owa-cd-key').fill(key);
        await submit(page);

        await expect(page.locator('.panel_headline')).toHaveText('Custom Dimensions');

        const row = page.locator('table.management tr', { hasText: key });
        await expect(row).toHaveCount(1);

        await row.locator('input[type=submit][value="Remove"]').click();
        await expect(page.locator('#owa_confirmDialog')).toBeVisible();

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('.owa_confirmProceed').click(),
        ]);

        await expect(page.locator('table.management tr', { hasText: key })).toHaveCount(0);
    });
});
