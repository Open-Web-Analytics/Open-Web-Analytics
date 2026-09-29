const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * The Custom Dimensions screen registers through a modal.
 *
 * The form is jQuery UI's to show: hidden in the page, lifted into a dialog by
 * the Register button. What only a browser can check is that it opens, that
 * Cancel closes it, that it submits as an ordinary form, and that a refusal
 * comes back with the dialog already open -- the reason inside it and what was
 * typed still in the fields.
 *
 * The fixture site's Property has a cube (seedCube() in the seeder), so the
 * screen offers the form at all. A registration this spec makes, it removes.
 */

async function gotoScreen(page) {
    await page.goto(`?owa_do=base.customDimensions&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });
}

test.describe('custom dimensions modal', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the Register button opens the form in a modal, and Cancel closes it', async ({ page }) => {
        await gotoScreen(page);

        const dialog = page.locator('#owa_cdDialog');
        await expect(dialog).toBeHidden();

        await page.locator('[data-owa-cd-open]').click();

        await expect(dialog).toBeVisible();
        await expect(page.locator('.owa_cdDialogFrame .ui-dialog-title'))
            .toHaveText('Register a custom dimension');
        await expect(page.locator('#owa-cd-key')).toBeFocused();

        await page.locator('[data-owa-cd-cancel]').click();
        await expect(dialog).toBeHidden();
    });

    test('a refused name comes back with the modal open, the reason and what was typed', async ({ page }) => {
        await gotoScreen(page);

        await page.locator('[data-owa-cd-open]').click();
        await page.locator('#owa-cd-key').fill('not.a.name');
        await page.locator('#owa-cd-label').fill('Refused label');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('#owa_cdDialog input[type=submit]').click(),
        ]);

        const dialog = page.locator('#owa_cdDialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.locator('.owa_cdDialogError')).not.toBeEmpty();
        await expect(page.locator('#owa-cd-key')).toHaveValue('not.a.name');
        await expect(page.locator('#owa-cd-label')).toHaveValue('Refused label');
    });

    test('a valid name registers from the modal and can be removed again', async ({ page }) => {
        const key = `e2e_cd_${Date.now()}`;

        await gotoScreen(page);

        await page.locator('[data-owa-cd-open]').click();
        await page.locator('#owa-cd-key').fill(key);

        // Enter submits: the form's own submit button, not a dialog button.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('#owa-cd-key').press('Enter'),
        ]);

        await expect(page.locator('#owa_cdDialog')).toBeHidden();

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
