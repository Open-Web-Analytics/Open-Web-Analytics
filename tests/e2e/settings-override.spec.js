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

/**
 * The Tracking Tag screen (PLAN 2.24.4): Base's tag fieldset first, a module's
 * after it, and a setting saved through its switch at Profile scope.
 */
test('the Tracking Tag screen saves a Profile override', async ({ page }) => {
    const TAG = `?owa_do=base.sitesInvocation&owa_siteId=${FIXTURE.siteId}`;
    const field = () => page.locator('select[name="config[base.tracker_clicks]"]');
    const sw = () => page.locator('input[name="override[base.tracker_clicks]"]');
    const saveTag = () => Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[type="submit"][value="Save Tag Settings"]').click(),
    ]);

    await adminLogin(page);
    await page.goto(TAG, { waitUntil: 'networkidle' });

    const legends = await page.locator('form[name="owa_tag_settings"] legend').allInnerTexts();
    expect(legends[0].toLowerCase()).toBe('tracking tag');

    // The visitor cookie is set per Property, so the Profile screen does not offer it.
    await expect(page.locator('[name="config[base.tracker_visitor_cookie_days]"]')).toHaveCount(0);

    if (await sw().isChecked()) {
        await sw().uncheck();
        await saveTag();
        await page.goto(TAG, { waitUntil: 'networkidle' });
    }

    try {
        await sw().check();
        await field().selectOption('0');
        await saveTag();

        await page.goto(TAG, { waitUntil: 'networkidle' });
        await expect(sw()).toBeChecked();
        await expect(field()).toHaveValue('0');
    } finally {
        await page.goto(TAG, { waitUntil: 'networkidle' });
        if (await sw().isChecked()) {
            await sw().uncheck();
            await saveTag();
        }
    }

    await page.goto(TAG, { waitUntil: 'networkidle' });
    await expect(sw()).not.toBeChecked();
    await expect(field()).toBeDisabled();
});
