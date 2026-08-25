// @ts-check
/**
 * The notification bell: what replaced the OWA News panel.
 *
 * That panel fetched api.github.com SYNCHRONOUSLY during every dashboard
 * render. Now a scheduled job stores releases as notifications and the header
 * reads them locally, so these assert the two things that were not true
 * before: the count comes from content the page already holds, and dismissing
 * does not reload the page.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

test.describe('notifications', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
        await page.waitForSelector('#owa_notificationToggle', { timeout: 20_000 });
    });

    test('the badge counts the notifications behind it', async ({ page }) => {
        const badge = page.locator('#owa_notificationBadge');
        await expect(badge).toBeVisible({ timeout: 20_000 });

        await page.click('#owa_notificationToggle');

        const rows = page.locator('.owa_notification');
        const shown = await rows.count();

        expect(shown).toBeGreaterThan(0);
        // The badge is not computed separately -- it IS the length of the list.
        await expect(badge).toHaveText(String(shown));
    });

    test('dismissing removes it without reloading the page', async ({ page }) => {
        await page.click('#owa_notificationToggle');

        const rows = page.locator('.owa_notification');
        const before = await rows.count();
        expect(before).toBeGreaterThan(0);

        const url = page.url();

        // A navigation would reset this, which is what makes it a witness.
        await page.evaluate(() => { window.__stillHere = true; });

        // Its OWN fixture notification. Dismissing is permanent, so two specs
        // reaching for whatever is on top would consume each other's subject
        // and pass or fail depending on the order they ran in.
        await page.locator('.owa_notification', { hasText: FIXTURE.notifications.titles[2] })
            .locator('.owa_notificationDismiss').click();

        await expect(rows).toHaveCount(before - 1, { timeout: 15_000 });
        await expect(page.locator('#owa_notificationBadge')).toHaveText(String(before - 1));

        expect(page.url()).toBe(url);
        expect(await page.evaluate(() => window.__stillHere)).toBe(true);
    });

    test('a dismissal outlives the page', async ({ page }) => {
        await page.click('#owa_notificationToggle');

        const rows = page.locator('.owa_notification');
        const before = await rows.count();

        // Dismiss a FIXTURE notification by name, not whatever is on top: the
        // list also holds real release announcements, and dismissing is
        // permanent, so a spec that consumed those would pass once.
        const target = FIXTURE.notifications.titles[1];
        const row = page.locator('.owa_notification', { hasText: target });

        await row.locator('.owa_notificationDismiss').click();
        await expect(rows).toHaveCount(before - 1, { timeout: 15_000 });

        await page.reload();
        await page.waitForSelector('#owa_notificationToggle', { timeout: 20_000 });

        // Stored server-side, not in the page: a reload must not bring it back.
        await expect(page.locator('#owa_notificationBadge'))
            .toHaveText(String(before - 1), { timeout: 20_000 });

        await page.click('#owa_notificationToggle');
        await expect(page.locator('.owa_notification', { hasText: target })).toHaveCount(0);
    });

    test('the seeded notifications are listed newest first', async ({ page }) => {
        await page.click('#owa_notificationToggle');

        const titles = await page.locator('.owa_notificationTitle').allTextContents();
        const seeded = titles.map(t => t.trim()).filter(t => t.startsWith('E2E Notification'));

        // published_at descending. Compared as a SUBSEQUENCE of the expected
        // order rather than as the whole list: sibling specs dismiss their own
        // fixtures, and this is an assertion about ORDER, not about how many
        // survive.
        expect(seeded).toEqual(FIXTURE.notifications.titles.filter(t => seeded.includes(t)));
        expect(seeded.length).toBeGreaterThan(0);
    });
});
