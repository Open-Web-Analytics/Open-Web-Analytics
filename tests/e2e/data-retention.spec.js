const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * The Data Retention pages in a real browser: a save that would delete event
 * data stops and asks, with what the server says it would do; Cancel stores
 * nothing; a save that deletes nothing goes straight through.
 *
 * The window used is 1200 months. It is shorter than "keep everything", so it
 * asks as a deletion would, and it deletes nothing on any real installation.
 * Each test puts the setting back to blank, which keeps everything.
 */

const INSTALL = '?owa_do=base.optionsRetention';

const raw = (page) => page.locator('input[name$="config[base.raw_retention_months]"]');
const dialog = (page) => page.locator('#owa_confirmDialog');

async function saveInstall(page) {
    await page.locator('button[value="base.optionsRetentionUpdate"]').click();
}

async function restoreRaw(page) {
    await page.goto(INSTALL, { waitUntil: 'networkidle' });

    if ((await raw(page).inputValue()) !== '') {
        await raw(page).fill('');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), saveInstall(page)]);
    }
}

test('shortening the event-data window asks first, and Cancel stores nothing', async ({ page }) => {
    await adminLogin(page);
    await restoreRaw(page);

    try {
        await raw(page).fill('1200');
        await saveInstall(page);

        await expect(dialog(page)).toBeVisible();
        await expect(dialog(page)).toContainText('cannot be recovered');
        await expect(page.locator('.owa_confirmProceed')).toHaveText('Save and delete');
        await expect(page.locator('.owa_confirmProceed')).toHaveClass(/owa-button-danger/);

        await page.locator('.owa_confirmCancel').click();
        await expect(dialog(page)).toBeHidden();

        await page.goto(INSTALL, { waitUntil: 'networkidle' });
        await expect(raw(page)).toHaveValue('');
        await expect(raw(page)).toHaveAttribute('placeholder', 'Keep everything');

        // Asked again, and this time saved.
        await raw(page).fill('1200');
        await saveInstall(page);
        await expect(dialog(page)).toBeVisible();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('.owa_confirmProceed').click(),
        ]);

        await page.goto(INSTALL, { waitUntil: 'networkidle' });
        await expect(raw(page)).toHaveValue('1200');

        // Back to keeping everything deletes nothing, so nothing is asked.
        await raw(page).fill('');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), saveInstall(page)]);
        await expect(dialog(page)).toHaveCount(0);
        await page.goto(INSTALL, { waitUntil: 'networkidle' });
        await expect(raw(page)).toHaveValue('');
    } finally {
        await restoreRaw(page);
    }
});

test('a Property reaches its own Data Retention page from the settings nav', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`?owa_do=base.sitesProfile&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('a', { hasText: 'Data Retention' }).last().click(),
    ]);

    await expect(page.locator('.panel_headline')).toHaveText('Data Retention');
    await expect(page.locator('form[data-owa-retention-form="property"]')).toHaveCount(1);
    await expect(page.locator('input[name$="config[base.cube_retention_months]"]')).toHaveCount(1);
});
