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

    test('the badge counts the UNREAD rows behind it', async ({ page }) => {
        const badge = page.locator('#owa_notificationBadge');
        await expect(badge).toBeVisible({ timeout: 20_000 });

        await page.click('#owa_notificationToggle');

        const unread = await page.locator('.owa_notification.is-unread').count();

        expect(unread).toBeGreaterThan(0);
        // Derived from the content and nowhere else -- but from the UNREAD
        // rows, not from how many rows there are, because a read notification
        // stays on screen without counting.
        await expect(badge).toHaveText(String(unread));
    });

    /**
     * Reading is not dismissing. Clicking clears the count and the bold, and
     * the notification STAYS -- the behaviour every social notification list
     * has, and the reason read and dismissed are separate columns.
     */
    test('reading a notification keeps it, unbolds it and clears its count', async ({ page }) => {
        await page.click('#owa_notificationToggle');

        const rows = page.locator('.owa_notification');
        const before = await rows.count();
        const unreadBefore = await page.locator('.owa_notification.is-unread').count();

        // Its OWN fixture: reading persists, so a spec that grabbed "the first
        // unread" would take whatever a sibling had not consumed yet.
        const row = page.locator('.owa_notification', { hasText: FIXTURE.notifications.toRead });
        const title = row.locator('.owa_notificationTitle');

        await expect(title).toHaveCSS('font-weight', '700');

        await row.click();

        await expect(page.locator('.owa_notification.is-unread')).toHaveCount(unreadBefore - 1);
        await expect(rows).toHaveCount(before, { timeout: 10_000 });
        await expect(title).toHaveCSS('font-weight', '400');
        await expect(page.locator('#owa_notificationBadge')).toHaveText(String(unreadBefore - 1));
    });

    test('the badge sits clear of the bell, at the far right of the nav', async ({ page }) => {
        const geometry = await page.evaluate(() => {
            const btn   = document.querySelector('.owa_notificationToggle');
            const glyph = btn.querySelector('i').getBoundingClientRect();
            const badge = document.querySelector('#owa_notificationBadge').getBoundingClientRect();
            const bell  = document.querySelector('.owa_notificationBell').getBoundingClientRect();
            const greet = document.querySelector('.user-greating');

            return {
                // Overlap needs BOTH axes; the badge clears the glyph on x.
                overlapsGlyph: !(badge.right < glyph.left || badge.left > glyph.right ||
                                 badge.bottom < glyph.top || badge.top > glyph.bottom),
                clipped: badge.right > window.innerWidth,
                bellRight: bell.right,
                greetingRight: greet ? greet.getBoundingClientRect().right : 0,
            };
        });

        expect(geometry.overlapsGlyph, 'the badge must not cover the bell').toBe(false);
        expect(geometry.clipped, 'the badge overhangs, so it must not be cut off at the edge').toBe(false);
        expect(geometry.bellRight).toBeGreaterThan(geometry.greetingRight);
    });

    test('the badge is always present, even at zero', async ({ page }) => {
        // A control that comes and goes moves the bell under the cursor. At
        // zero it goes quiet rather than away.
        await expect(page.locator('#owa_notificationBadge')).toBeVisible();
    });

    test('a row shows an icon, a bold linked headline and a short excerpt', async ({ page }) => {
        await page.click('#owa_notificationToggle');

        // A fixture no other spec touches, so it is still unread and still bold.
        const row = page.locator('.owa_notification', { hasText: FIXTURE.notifications.untouched });

        // A release is a package. An unknown type still gets an icon rather
        // than an empty hole where one should be.
        await expect(row.locator('.owa_notificationIcon i')).toHaveClass(/fa-box/);

        const title = row.locator('.owa_notificationTitle');
        await expect(title.locator('a')).toHaveCount(1);
        await expect(title).toHaveCSS('font-weight', '700');

        // A hint, not the body: bounded so it cannot push the row open.
        const excerpt = (await row.locator('.owa_notificationExcerpt').textContent()) || '';
        expect(excerpt.trim().split(/\s+/).length).toBeLessThanOrEqual(21);
    });

    test('the badge is red, and the panel is pinned to the right edge', async ({ page }) => {
        const badge = page.locator('#owa_notificationBadge');

        await expect(badge).toBeVisible({ timeout: 20_000 });
        await expect(badge).toHaveCSS('background-color', 'rgb(228, 30, 63)');

        await page.click('#owa_notificationToggle');

        const panel = page.locator('#owa_notificationPanel');
        await expect(panel).toHaveCSS('position', 'fixed');

        // Against the right edge of the window, not floating mid-page.
        const gap = await panel.evaluate(
            el => Math.round(window.innerWidth - el.getBoundingClientRect().right));

        expect(gap).toBeLessThanOrEqual(24);
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
        await page.locator('.owa_notification', { hasText: FIXTURE.notifications.toDismiss })
            .locator('.owa_notificationDismiss').click();

        await expect(rows).toHaveCount(before - 1, { timeout: 15_000 });

        expect(page.url()).toBe(url);
        expect(await page.evaluate(() => window.__stillHere)).toBe(true);
    });

    test('a dismissal outlives the page', async ({ page }) => {
        await page.click('#owa_notificationToggle');

        const rows = page.locator('.owa_notification');
        const before = await rows.count();

        // The badge counts UNREAD, which is not the same as the number of rows
        // once a sibling spec has read one. Track them separately or this
        // asserts a number that only holds when nothing has been read yet.
        const unreadBefore = await page.locator('.owa_notification.is-unread').count();

        // Dismiss a FIXTURE notification by name, not whatever is on top: the
        // list also holds real release announcements, and dismissing is
        // permanent, so a spec that consumed those would pass once.
        const target = FIXTURE.notifications.toDismissAndReload;
        const row = page.locator('.owa_notification', { hasText: target });

        await row.hover();
        await row.locator('.owa_notificationDismiss').click();
        await expect(rows).toHaveCount(before - 1, { timeout: 15_000 });

        await page.reload();
        await page.waitForSelector('#owa_notificationToggle', { timeout: 20_000 });

        // Stored server-side, not in the page: a reload must not bring it back.
        // The dismissed row was unread, so the count drops with it.
        await expect(page.locator('#owa_notificationBadge'))
            .toHaveText(String(unreadBefore - 1), { timeout: 20_000 });

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
