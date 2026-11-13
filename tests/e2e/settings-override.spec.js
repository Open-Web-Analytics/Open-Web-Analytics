const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * The Override switch on a setting rendered below the install
 * (SettingsForm::scopedField), driven in a real browser on the Property's
 * Details screen: inheriting shows the value as text with a note naming the
 * level, the control hidden; switching on shows the control, editable and
 * focused, and saving stores it; switching off and saving puts the Property
 * back to inheriting. A setting with nothing set above it is a plain field
 * with no switch.
 *
 * e-commerce reporting is the boolean it drives, and that is a Property
 * setting: a report is of a Property and covers every Profile in it.
 */

const PROFILE_SCREEN = `?owa_do=base.profileSettings&owa_siteId=${FIXTURE.siteId}`;

/** The fixture Profile's Property Details, read off the Property nav. */
async function propertyScreen(page) {
    await page.goto(PROFILE_SCREEN, { waitUntil: 'networkidle' });

    const href = await page.locator('a[href*="do=base.propertyProfile&"]').first().getAttribute('href');
    const params = new URL(href, page.url()).searchParams;
    const propertyId = params.get('owa_propertyId') || params.get('propertyId');

    expect(propertyId, 'the fixture Profile has a Property').toBeTruthy();

    return `?owa_do=base.propertyProfile&owa_propertyId=${propertyId}`;
}

function controls(page) {
    const setting = page.locator('[data-ui="setting:base.enableEcommerceReporting"]');

    return {
        // Named by the setting's title; found hidden too, which getByLabel is not.
        field: setting.getByRole('combobox', { name: 'e-commerce Reporting', includeHidden: true }),
        sw: setting.getByRole('switch', { name: 'Override e-commerce Reporting' }),
        text: setting.locator('[data-ui="setting-inherited"]'),
        inheritNote: setting.getByText(/^Set at the .+ level\.$/),
        overrideNote: setting.getByText(/^Overrides the .+ level value of/),
    };
}

async function save(page) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[type="submit"][value="Save Property"]').click(),
    ]);
}

test('a Property setting inherits until its Override switch is on', async ({ page }) => {
    await adminLogin(page);

    // domain_aliases has nothing set above the Profile: a plain field, no switch to turn on.
    await page.goto(PROFILE_SCREEN, { waitUntil: 'networkidle' });
    await expect(page.locator('input[name="config[base.domain_aliases]"]')).toBeEnabled();
    await expect(page.locator('input[name="override[base.domain_aliases]"]')).toHaveCount(0);

    // And e-commerce is no longer offered per Profile.
    await expect(page.locator('[name="config[base.enableEcommerceReporting]"]')).toHaveCount(0);

    // Nor the retired default_page, nor its install-wide successor.
    await expect(page.locator('[name="config[base.default_page]"]')).toHaveCount(0);
    await expect(page.locator('[name="config[base.directory_index_pages]"]')).toHaveCount(0);

    const SCREEN = await propertyScreen(page);
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

    await expect(c.field).toBeHidden();
    await expect(c.field).toBeDisabled();
    await expect(c.sw).not.toBeChecked();
    await expect(c.text).toBeVisible();
    await expect(c.text).toHaveText(inherited === '1' ? 'On' : 'Off');
    await expect(c.inheritNote).toBeVisible();
    await expect(c.inheritNote).toHaveText(/^Set at the (install|Organization) level\.$/);
    await expect(c.overrideNote).toBeHidden();

    try {
        await c.sw.check();
        await expect(c.text).toBeHidden();
        await expect(c.field).toBeVisible();
        await expect(c.field).toBeEnabled();
        await expect(c.field).toBeFocused();
        await expect(c.field).toHaveValue(inherited);
        await expect(c.overrideNote).toBeVisible();
        const chosen = inherited === '1' ? '0' : '1';
        await c.field.selectOption(chosen);
        await save(page);

        await page.goto(SCREEN, { waitUntil: 'networkidle' });
        c = controls(page);
        await expect(c.sw).toBeChecked();
        await expect(c.field).toBeVisible();
        await expect(c.field).toBeEnabled();
        await expect(c.field).toHaveValue(chosen);
        await expect(c.text).toBeHidden();

    } finally {
        await page.goto(SCREEN, { waitUntil: 'networkidle' });
        c = controls(page);
        if (await c.sw.isChecked()) {
            await c.sw.uncheck();
            // Switching off puts the inherited value back before the save.
            await expect(c.field).toHaveValue(inherited);
            await expect(c.field).toBeDisabled();
            await expect(c.field).toBeHidden();
            await expect(c.text).toBeVisible();
            await save(page);
        }
    }

    await page.goto(SCREEN, { waitUntil: 'networkidle' });
    c = controls(page);
    await expect(c.sw).not.toBeChecked();
    await expect(c.field).toBeDisabled();
    await expect(c.field).toHaveValue(inherited);
    await expect(c.text).toBeVisible();
});

/**
 * The Tracking Tag screen (PLAN 2.24.4): Base's two tag fieldsets first, a module's
 * after it, and a setting saved through its switch at Profile scope.
 */
test('the Tracking Tag screen saves a Profile override', async ({ page }) => {
    const TAG = `?owa_do=base.sitesInvocation&owa_siteId=${FIXTURE.siteId}`;
    const setting = () => page.locator('[data-ui="setting:base.tracker_clicks"]');
    const field = () => setting().getByRole('combobox', { includeHidden: true });
    const sw = () => setting().getByRole('switch');
    const text = () => setting().locator('[data-ui="setting-inherited"]');
    const pills = () => page.locator('.owa-tagStatus .owa-pill');

    // Clicks are in their own group, closed on every load.
    const openClicks = () => page.locator('details[id="base.trackingClicks"] > summary').click();
    const gotoTag = async () => {
        await page.goto(TAG, { waitUntil: 'networkidle' });
        await openClicks();
    };
    const saveTag = () => Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[type="submit"][value="Save and republish"]').click(),
    ]);

    await adminLogin(page);
    await page.goto(TAG, { waitUntil: 'networkidle' });

    // Groups, each closed on load, Base's first and a module's after.
    const groups = page.locator('form[name="owa_tag_settings"] details.owa-settingsGroup');
    const titles = await groups.locator('.owa-settingsGroup__title').allInnerTexts();
    expect(titles.slice(0, 2)).toEqual(['Page views', 'Clicks and downloads']);
    expect(await groups.evaluateAll((all) => all.filter((d) => d.open).length)).toBe(0);
    await expect(sw()).toBeHidden();
    await openClicks();
    await expect(sw()).toBeVisible();

    // The visitor cookie is set per Property, so the Profile screen does not offer it.
    await expect(page.locator('[name="config[base.tracker_visitor_cookie_days]"]')).toHaveCount(0);

    if (await sw().isChecked()) {
        await sw().uncheck();
        await saveTag();
        await gotoTag();
    }

    try {
        await sw().check();
        await field().selectOption('0');
        await saveTag();

        await gotoTag();
        await expect(sw()).toBeChecked();
        await expect(field()).toHaveValue('0');

        // The pills follow what is saved: clicks are no longer tracked.
        await expect(pills()).not.toContainText(['Clicks']);
        await expect(pills()).toContainText(['Page views']);
    } finally {
        await gotoTag();
        if (await sw().isChecked()) {
            await sw().uncheck();
            await saveTag();
        }
    }

    await gotoTag();
    await expect(sw()).not.toBeChecked();
    await expect(pills()).toContainText(['Clicks']);
    await expect(field()).toBeDisabled();
    await expect(text()).toBeVisible();
});
