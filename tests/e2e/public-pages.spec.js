// @ts-check
/**
 * The signed-out pages: login, password reset, set password.
 *
 * WHAT ONLY THIS SUITE CAN CHECK
 *
 * That the forms still WORK after being rewritten. Every field name on these
 * pages is read by a controller and none of them is optional -- `go` decides
 * where a login lands, `k` is the only thing identifying the account on the
 * set-password page, and `action` is what routes the post at all. A redesign
 * that dropped one would look perfect and lock people out.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE } = require('./fixtures');

test.describe('the signed-out pages', () => {

    test.use({ storageState: { cookies: [], origins: [] } });

    /**
     * THE MARKUP THAT WAS THERE.
     *
     * The reset form closed a table that was never opened -- </TD></TR>, a
     * <TR>, then </TABLE> -- left behind when it stopped being a table, and
     * browsers dropped the stray tags silently. Both it and the set-password
     * form were wrapped in "spiffy" rounded-corner spans.
     */
    test('none of the old markup survives', async ({ page }) => {
        for (const action of ['base.loginForm', 'base.passwordResetForm']) {
            await page.goto(`?owa_do=${action}`, { waitUntil: 'networkidle' });

            await expect(page.locator('.owa_publicCard')).toHaveCount(1);
            await expect(page.locator('.spiffy')).toHaveCount(0);
            await expect(page.locator('.owa_publicCard table')).toHaveCount(0);
            await expect(page.locator('.owa_publicCard td')).toHaveCount(0);
        }
    });

    /** They are styled at all, which is the thing that was actually wrong. */
    test('the card is painted, not a bare form on a white page', async ({ page }) => {
        await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });

        const card = page.locator('.owa_publicCard');

        await expect(card).toHaveCSS('border-top-style', 'solid');
        await expect(card).toHaveCSS('border-radius', '8px');

        // The same button the rest of the application uses.
        const button = page.locator('.owa_publicSubmit');

        const background = await button.evaluate((el) => getComputedStyle(el).backgroundColor);

        expect(background).toBe('rgb(255, 165, 0)');

        /*
         * White at rest, blue on hover. It used to go BLACK on hover, which is
         * not a colour used anywhere else on these screens.
         */
        expect(await button.evaluate((el) => getComputedStyle(el).color))
            .toBe('rgb(255, 255, 255)');

        await button.hover();

        expect(await button.evaluate((el) => getComputedStyle(el).color))
            .toBe('rgb(26, 95, 138)');
    });

    /**
     * ...and no console error on load.
     *
     * head.php wrote OWA.config unconditionally, and these pages load no
     * JavaScript at all, so each of them threw "OWA is not defined" on every
     * load -- harmless, permanent, and hiding anything that was not.
     */
    test('the signed-out pages load without a script error', async ({ page }) => {
        const errors = [];

        page.on('pageerror', (e) => errors.push(e.message));

        for (const action of ['base.loginForm', 'base.passwordResetForm']) {
            await page.goto(`?owa_do=${action}`, { waitUntil: 'networkidle' });
        }

        expect(errors).toEqual([]);
    });

    /** The form still signs people in, which the styling must not have cost. */
    test('login still works', async ({ page }) => {
        await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });

        await page.fill('input[name$="user_id"]', FIXTURE.adminUserId);
        await page.fill('input[name$="password"]', FIXTURE.adminPassword);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Login"]').click(),
        ]);

        // Signed in: the account menu is only rendered for a real session.
        await expect(page.locator('#owa_userMenuToggle')).toHaveCount(1);
    });

    /**
     * Every hidden field a controller reads is still posted.
     *
     * `action` routes the post, `go` is where a login from a deep link lands,
     * and `k` is the passkey that identifies the account on the set-password
     * page -- there is no session there, so it is the only thing that does.
     */
    test('the login form still carries its action and its destination', async ({ page }) => {
        await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });

        await expect(page.locator('input[name$="action"]')).toHaveValue('base.login');
        await expect(page.locator('input[name$="go"]')).toHaveCount(1);
    });

    test('the reset form still carries its action', async ({ page }) => {
        await page.goto('?owa_do=base.passwordResetForm', { waitUntil: 'networkidle' });

        await expect(page.locator('input[name$="action"]'))
            .toHaveValue('base.passwordResetRequest');
        await expect(page.locator('input[name$="email_address"]')).toHaveCount(1);
    });

    test('the set-password form still carries the passkey', async ({ page }) => {
        await page.goto(`?owa_do=base.usersPasswordEntry&owa_k=${FIXTURE.pwPasskey}`,
            { waitUntil: 'networkidle' });

        await expect(page.locator('.owa_publicCard')).toHaveCount(1);

        // The passkey from the emailed link, carried through to the post.
        await expect(page.locator('input[name$="k"]')).toHaveValue(FIXTURE.pwPasskey);
        await expect(page.locator('input[name$="action"]'))
            .toHaveValue('base.usersChangePassword');

        // Both password fields, or it cannot check them against each other.
        await expect(page.locator('input[type=password]')).toHaveCount(2);
    });
});
