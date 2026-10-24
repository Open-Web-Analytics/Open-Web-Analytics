// @ts-check
/**
 * The navigation: a rail of sections, and beside it a column of the current
 * section's reports (nav_shell.php).
 *
 * The rail marks the section you are in and the column the report you are on,
 * each with a pale blue fill and the label in the reporting blue. A section
 * with no reports under it opens no column. Hovering another section shows its
 * reports as a flyout. The chevron at the top of the column hides it, and that
 * is remembered. Settings is at the foot of the rail.
 *
 * Which link is current compares every key of a link's ref: a report link is
 * {do: base.report, reportId: pages}, and comparing the action alone marks
 * every report at once (ReportNavigationLinkTest).
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login, adminLogin } = require('./fixtures');

/** The reporting UI's selected colour, #1874CD. */
const REPORT_BLUE = 'rgb(24, 116, 205)';

/** The current item's fill, #eef4fb. */
const CURRENT_FILL = 'rgb(238, 244, 251)';

/** A report inside a section, and a second one beside it. */
const REPORT = { id: 'pages', label: 'Pages', section: 'Content' };
const SIBLING = { id: 'entry-pages', label: 'Entry Pages' };

async function openReport(page, reportId) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=${reportId}`
        + `&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('#owa_navShell', { timeout: 20_000 });
}

const rail = (page) => page.locator('.owa_rail');
const column = (page) => page.locator('#owa_navColumn');
const railItem = (page, label) => rail(page).getByRole('link', { name: label, exact: true });

/** A link's propertyId; links are written either way. */
const propertyOf = (href, base) => {
    const q = new URL(href, base).searchParams;
    return q.get('propertyId') || q.get('owa_propertyId');
};

/** Colour and fill of an element, as drawn. */
function looks(locator) {
    return locator.evaluate((el) => {
        const s = getComputedStyle(el);
        return { color: s.color, fill: s.backgroundColor };
    });
}

test.describe('the navigation', () => {

    test.use({ viewport: { width: 1440, height: 900 } });

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('the rail marks the section you are in, and only it', async ({ page }) => {
        await openReport(page, REPORT.id);

        const current = rail(page).locator('.owa_railItem.is-current');

        await expect(current).toHaveCount(1);
        await expect(current).toHaveText(REPORT.section);
        await expect(current).toHaveAttribute('aria-current', 'page');
        expect(await looks(current)).toEqual({ color: REPORT_BLUE, fill: CURRENT_FILL });
    });

    test("the column is the section's reports, its own first, and marks the one you are on", async ({ page }) => {
        await openReport(page, REPORT.id);

        await expect(column(page).locator('.owa_navColumnTitle')).toHaveText(REPORT.section);

        const items = column(page).locator('.owa_navColumnItem');

        await expect(items.first()).toHaveText('Overview');
        await expect(items.filter({ hasText: SIBLING.label })).toBeVisible();

        const current = column(page).locator('.owa_navColumnItem.is-current');

        await expect(current).toHaveCount(1);
        await expect(current).toHaveText(REPORT.label);
        expect(await looks(current)).toEqual({ color: REPORT_BLUE, fill: CURRENT_FILL });
    });

    test('the mark follows the report being viewed', async ({ page }) => {
        await openReport(page, REPORT.id);

        await Promise.all([
            page.waitForURL(new RegExp(`reportId=${SIBLING.id}`)),
            column(page).locator('.owa_navColumnItem', { hasText: SIBLING.label }).click(),
        ]);
        await page.waitForSelector('#owa_navShell');

        await expect(column(page).locator('.owa_navColumnItem.is-current')).toHaveText(SIBLING.label);
    });

    test('a section with no reports under it opens no column', async ({ page }) => {
        await openReport(page, 'dashboard');

        await expect(railItem(page, 'Dashboard')).toHaveClass(/is-current/);
        await expect(column(page)).toHaveCount(0);
    });

    test("hovering another section shows its reports, and they go there", async ({ page }) => {
        await openReport(page, REPORT.id);

        const entry = rail(page).locator('.owa_railEntry', { has: page.locator('.owa_railItem', { hasText: 'Traffic' }) });
        const flyout = entry.locator('.owa_railFlyout');

        await expect(flyout).toBeHidden();

        await entry.locator('.owa_railItem').hover();
        await expect(flyout).toBeVisible();
        await expect(flyout.locator('.owa_railFlyoutTitle')).toHaveText('Traffic');

        // The pointer can cross from the item to the flyout without it closing.
        await flyout.locator('a', { hasText: 'Channels' }).hover();
        await expect(flyout).toBeVisible();

        await Promise.all([
            page.waitForURL(/reportId=channels/),
            flyout.locator('a', { hasText: 'Channels' }).click(),
        ]);
        await page.waitForSelector('#owa_navShell');

        await expect(rail(page).locator('.owa_railItem.is-current')).toHaveText('Traffic');
        await expect(column(page).locator('.owa_navColumnItem.is-current')).toHaveText('Channels');
    });

    test('the current section has no flyout: its reports are already in the column', async ({ page }) => {
        await openReport(page, REPORT.id);

        await expect(rail(page).locator('.owa_railEntry', { has: page.locator('.owa_railItem.is-current') })
            .locator('.owa_railFlyout')).toHaveCount(0);
    });

    test('the chevron hides the column, and it stays hidden on the next page', async ({ page }) => {
        await openReport(page, REPORT.id);

        const toggle = column(page).locator('.owa_navColumnToggle');
        const width = async () => (await column(page).boundingBox()).width;

        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(await width()).toBeGreaterThan(200);

        await toggle.click();

        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(toggle).toHaveAttribute('aria-label', 'Show this column');
        await expect(column(page).locator('.owa_navColumnBody')).toBeHidden();
        expect(await width()).toBeLessThan(60);

        await openReport(page, SIBLING.id);

        await expect(page.locator('#owa_navShell')).toHaveClass(/is-collapsed/);
        await expect(column(page).locator('.owa_navColumnToggle')).toHaveAttribute('aria-expanded', 'false');

        await column(page).locator('.owa_navColumnToggle').click();

        await expect(column(page).locator('.owa_navColumnBody')).toBeVisible();

        await openReport(page, REPORT.id);

        await expect(page.locator('#owa_navShell')).not.toHaveClass(/is-collapsed/);
    });

    test('Settings is at the foot of the rail, in view', async ({ page }) => {
        await openReport(page, REPORT.id);

        const settings = railItem(page, 'Settings');
        const items = rail(page).locator('.owa_railItem');

        await expect(items.last()).toHaveText('Settings');
        await expect(settings).toBeInViewport();

        const box = await settings.boundingBox();
        const navBox = await rail(page).boundingBox();

        // At the bottom of the rail, not under the last section.
        expect(navBox.y + navBox.height - (box.y + box.height)).toBeLessThan(40);
    });

    test('the column stays in view as the page scrolls', async ({ page }) => {
        await openReport(page, REPORT.id);

        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));

        await expect(railItem(page, REPORT.section)).toBeInViewport();
    });
});

test.describe('the navigation on a settings screen', () => {

    test.use({ viewport: { width: 1440, height: 900 } });

    test('Settings is current, and the column is the settings pages by level', async ({ page }) => {
        await adminLogin(page);
        await page.goto('?owa_do=base.optionsModules', { waitUntil: 'networkidle' });

        await expect(rail(page).locator('.owa_railItem.is-current')).toHaveText('Settings');
        await expect(column(page).locator('.owa_navColumnTitle')).toHaveText('Settings');

        await expect(column(page).locator('.owa_navColumnHeading')).toHaveText(
            ['My preferences', 'Server configuration', 'Organization', 'Property', 'Profiles']);

        await expect(column(page).locator('.owa_navColumnItem.is-current')).toHaveText('Modules');
    });

    test("the rail's reports are of the Property in context", async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.sitesInvocation&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

        // The Property this Profile is of, as its Property pages name it.
        const details = await column(page).locator('.owa_navColumnItem', { hasText: 'Goal events' }).getAttribute('href');
        const propertyId = propertyOf(details, page.url());

        expect(propertyId).toBeTruthy();

        await Promise.all([
            page.waitForURL(/do=base\.report/),
            railItem(page, 'Content').click(),
        ]);
        await page.waitForSelector('#owa_navShell');

        expect(propertyOf(page.url())).toBe(propertyId);
        await expect(rail(page).locator('.owa_railItem.is-current')).toHaveText('Content');
    });

    test("a Profile is one entry, and its pages are the tabs of its page", async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.sitesInvocation&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

        const tabs = page.locator('.owa_pageTabs .owa_pageTab');

        await expect(tabs).toHaveText(['Tracking tag', 'Observation settings', 'Details']);
        await expect(page.locator('.owa_pageTab.is-current')).toHaveText('Tracking tag');

        const profile = column(page).locator('.owa_navColumnItem.is-current');

        await expect(profile).toHaveCount(1);

        const name = (await profile.innerText()).trim();

        await Promise.all([
            page.waitForURL(/do=base\.profileSettings/),
            tabs.filter({ hasText: 'Observation settings' }).click(),
        ]);

        await expect(page.locator('.owa_pageTab.is-current')).toHaveText('Observation settings');
        await expect(column(page).locator('.owa_navColumnItem.is-current')).toHaveText(name);
    });
});
