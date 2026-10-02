const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * The Override switch on a setting rendered below the install
 * (SettingsForm::scopedField), driven in a real browser on the Profile's
 * Observation Settings screen: inheriting shows the value disabled with a
 * note naming the level; switching on makes it editable and saving stores it;
 * switching off and saving puts the Profile back to inheriting. A setting
 * with nothing set above it is a plain field with no switch.
 */

const SCREEN = `?owa_do=base.profileSettings&owa_siteId=${FIXTURE.siteId}`;

function controls(page) {
    return {
        field: page.locator('select[name="config[base.enableEcommerceReporting]"]'),
        sw: page.locator('input[name="override[base.enableEcommerceReporting]"]'),
        inheritNote: page.locator('[data-owa-note-inherit="owa-setting-base-enableEcommerceReporting"]'),
        overrideNote: page.locator('[data-owa-note-override="owa-setting-base-enableEcommerceReporting"]'),
    };
}

async function save(page) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[type="submit"][value="Save Settings"]').click(),
    ]);
}

test('a Profile setting inherits until its Override switch is on', async ({ page }) => {
    await adminLogin(page);
    await page.goto(SCREEN, { waitUntil: 'networkidle' });

    let c = controls(page);

    // Start from inheriting, whatever an earlier run left.
    if (await c.sw.isChecked()) {
        await c.sw.uncheck();
        await save(page);
        await page.goto(SCREEN, { waitUntil: 'networkidle' });
        c = controls(page);
    }

    const inherited = await c.field.inputValue();

    // default_page has nothing set above it: a plain field, no switch to turn on.
    await expect(page.locator('input[name="config[base.default_page]"]')).toBeEnabled();
    await expect(page.locator('input[name="override[base.default_page]"]')).toHaveCount(0);

    await expect(c.field).toBeDisabled();
    await expect(c.sw).not.toBeChecked();
    await expect(c.inheritNote).toBeVisible();
    await expect(c.inheritNote).toHaveText(/^Currently set at the (install|Organization|Property) level\.$/);
    await expect(c.overrideNote).toBeHidden();

    try {
        await c.sw.check();
        await expect(c.field).toBeEnabled();
        await expect(c.overrideNote).toBeVisible();
        const chosen = inherited === '1' ? '0' : '1';
        await c.field.selectOption(chosen);
        await save(page);

        await page.goto(SCREEN, { waitUntil: 'networkidle' });
        c = controls(page);
        await expect(c.sw).toBeChecked();
        await expect(c.field).toBeEnabled();
        await expect(c.field).toHaveValue(chosen);

    } finally {
        await page.goto(SCREEN, { waitUntil: 'networkidle' });
        c = controls(page);
        if (await c.sw.isChecked()) {
            await c.sw.uncheck();
            // Switching off puts the inherited value back before the save.
            await expect(c.field).toHaveValue(inherited);
            await expect(c.field).toBeDisabled();
            await save(page);
        }
    }

    await page.goto(SCREEN, { waitUntil: 'networkidle' });
    c = controls(page);
    await expect(c.sw).not.toBeChecked();
    await expect(c.field).toBeDisabled();
    await expect(c.field).toHaveValue(inherited);
});
