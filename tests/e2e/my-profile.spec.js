// @ts-check
/**
 * The signed-in user's own account.
 *
 * WHAT ONLY THIS SUITE CAN CHECK
 *
 * The capability actually taking effect on a real session. edit_own_email
 * decides whether the address renders as a field or as text, and whether a
 * posted change is written or refused -- and both halves need a real signed-in
 * user of a real role, which a unit test cannot supply.
 *
 * And the password path end to end: verifying the current password, writing the
 * new hash, and the sign-out that follows from the auth cookie being derived
 * from that hash.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login, loginAs } = require('./fixtures');

/*
 * A password fixture of this spec's OWN.
 *
 * Not FIXTURE.pwUserId: admin-actions.spec.js leaves that user on a new
 * password deliberately, and nothing restores it until the next seeding run --
 * so sharing it works when this file runs alone and fails whenever
 * admin-actions has run first, which in a full run it always has.
 */
const PW_USER = FIXTURE.profilePwUserId;
const PW_PASS = FIXTURE.profilePwPassword;

async function openPreferences(page) {
    await page.goto(`?owa_do=base.myProfile&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });
    await page.waitForSelector('form[name=owa_myProfile]', { timeout: 20_000 });
}

async function save(page) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('input[value="Save Profile"]').click(),
    ]);
}

/** The notices on the page, as one string. */
async function notice(page) {
    return (await page.locator('.notice').allInnerTexts()).join(' ').replace(/\s+/g, ' ').trim();
}

test.describe('my preferences', () => {

    test.describe('as an admin', () => {

        test.beforeEach(async ({ page }) => {
            await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
        });

        /**
         * BOTH WAYS IN.
         *
         * The account menu in the top bar is where people reach for their own
         * account; the settings nav is where they look for it deliberately.
         * Asserted as links that actually arrive, not merely as present markup.
         */
        test('the account menu and the settings nav both lead here', async ({ page }) => {
            await page.goto(`?owa_do=base.reportingHome&owa_siteId=${FIXTURE.siteId}`,
                { waitUntil: 'networkidle' });

            await page.click('#owa_userMenuToggle');
            await page.locator('a.owa_myProfileLink').click();
            await page.waitForSelector('form[name=owa_myProfile]', { timeout: 20_000 });

            // ...and the settings nav carries it, in a group of its own rather
            // than filed under Instance with the install-wide screens.
            await expect(page.locator('.owa_hierarchyNavHead', { hasText: 'My Preferences' }))
                .toHaveCount(1);

            const navLink = page.locator('.owa_hierarchyNav a', { hasText: 'User Profile' });

            await expect(navLink).toHaveCount(1);
            await navLink.click();
            await page.waitForSelector('form[name=owa_myProfile]', { timeout: 20_000 });
        });

        /**
         * The username is shown and NOT editable.
         *
         * user_id identifies everything the account has made -- custom reports
         * are stored against it, and so are site grants -- so changing it here
         * would orphan them. It is stated rather than offered.
         */
        test('the username and role are shown but not editable', async ({ page }) => {
            await openPreferences(page);

            await expect(page.locator('.noedit').first()).toHaveText(FIXTURE.adminUserId);
            await expect(page.locator('input[name=user_id]')).toHaveCount(0);
            await expect(page.locator('input[name=role]')).toHaveCount(0);
        });

        test('an admin can change their own name and address', async ({ page }) => {
            await openPreferences(page);

            const name = 'E2E Admin ' + Date.now();

            await page.fill('input[name=real_name]', name);
            await save(page);

            expect(await notice(page)).toContain('saved');

            await openPreferences(page);
            await expect(page.locator('input[name=real_name]')).toHaveValue(name);

            // The address is a field for a role that may change it.
            await expect(page.locator('input[name=email_address]')).toHaveCount(1);
        });

        /**
         * An address that is not one is refused TWICE, and both matter.
         *
         * The field is type=email, so the browser refuses to submit it at all
         * -- which is why this cannot be driven through the Save button. That
         * is the fast answer, and it is not the enforcement: a post that never
         * went through the form reaches the save, and the emailAddress
         * validator is what actually refuses it there.
         */
        test('an address that is not an address is refused', async ({ page }) => {
            await openPreferences(page);

            await page.fill('input[name=email_address]', 'not-an-address');

            // The browser will not submit it.
            const valid = await page.locator('input[name=email_address]')
                .evaluate((el) => el.checkValidity());

            expect(valid).toBe(false);

            // ...and the server refuses it when the browser is bypassed.
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.evaluate(() => HTMLFormElement.prototype.submit.call(
                    document.forms.owa_myProfile)),
            ]);

            expect(await notice(page)).toContain('email address');
        });
    });

    test.describe('as an analyst', () => {

        test.beforeEach(async ({ page }) => {
            await login(page);
        });

        /**
         * An analyst reaches the screen, which is the point of gating it on
         * view_site_list rather than on edit_settings like the rest of the
         * Instance nav group.
         */
        test('an analyst can open it and change their name', async ({ page }) => {
            await openPreferences(page);

            await expect(page.locator('.owa_hierarchyNav a', { hasText: 'Profile' }))
                .toHaveCount(1);

            const name = 'E2E Analyst ' + Date.now();

            await page.fill('input[name=real_name]', name);
            await save(page);

            expect(await notice(page)).toContain('saved');

            await openPreferences(page);
            await expect(page.locator('input[name=real_name]')).toHaveValue(name);
        });

        /**
         * ...and the address is shown, but as text.
         *
         * Shown, because which address the account uses is worth knowing even
         * when it cannot be changed. As TEXT with no name attribute, so nothing
         * is posted -- which is also why the save has to treat an absent field
         * as "not offered" rather than as "cleared".
         */
        test('an analyst is shown their address but cannot edit it', async ({ page }) => {
            await openPreferences(page);

            await expect(page.locator('input[name=email_address]')).toHaveCount(0);
            await expect(page.locator('#panel')).toContainText(FIXTURE.userId);

            // Saving a name still works -- the absent address must not be read
            // as a change to empty, which refused every save when it was.
            await page.fill('input[name=real_name]', 'E2E Analyst Named');
            await save(page);

            expect(await notice(page)).toContain('saved');
        });

        /**
         * The capability is enforced by the SAVE, not only by the form.
         *
         * The field is not rendered, so posting one means something other than
         * the form did it.
         */
        test('an analyst posting an address is refused', async ({ page }) => {
            await openPreferences(page);

            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.evaluate(() => {
                    const form = document.forms.owa_myProfile;
                    const field = document.createElement('input');

                    field.type = 'hidden';
                    field.name = 'email_address';
                    field.value = 'hijacked@example.test';

                    form.appendChild(field);

                    HTMLFormElement.prototype.submit.call(form);
                }),
            ]);

            expect(await notice(page)).toContain('not something your role can do');

            // ...and it did not take.
            await openPreferences(page);
            await expect(page.locator('#panel')).not.toContainText('hijacked@example.test');
        });
    });

    test.describe('changing your own password', () => {

        test.beforeEach(async ({ page }) => {
            await loginAs(page, PW_USER, PW_PASS);
        });

        test('the current password is required and checked', async ({ page }) => {
            await openPreferences(page);

            await page.fill('input[name=current_password]', 'not-the-password');
            await page.fill('input[name=new_password]', 'a-new-password-1');
            await page.fill('input[name=new_password2]', 'a-new-password-1');
            await save(page);

            expect(await notice(page)).toContain('not your current password');

            /*
             * And the secret is not handed back. A refusal that redrew what was
             * typed would put a password into the page source of the refusal.
             */
            expect(await page.content()).not.toContain('not-the-password');
        });

        test('the new passwords must match and be long enough', async ({ page }) => {
            await openPreferences(page);

            await page.fill('input[name=current_password]', PW_PASS);
            await page.fill('input[name=new_password]', 'a-new-password-1');
            await page.fill('input[name=new_password2]', 'a-different-one-2');
            await save(page);

            expect(await notice(page)).toContain('do not match');

            await openPreferences(page);
            await page.fill('input[name=current_password]', PW_PASS);
            await page.fill('input[name=new_password]', 'abc');
            await page.fill('input[name=new_password2]', 'abc');
            await save(page);

            expect(await notice(page)).toContain('at least 6');
        });

        /**
         * A SUCCESSFUL CHANGE SIGNS YOU OUT, and it has to.
         *
         * The auth cookie is md5( user_id . password_hash ) -- see
         * Auth::saveCredentials() -- so the moment the hash changes the cookie
         * stops matching and the next request is anonymous. Left alone, the
         * author was silently signed out on their next click with no idea why.
         *
         * Restores the fixture password at the end, because every other test
         * signing in as this user depends on it.
         */
        test('a change signs you out, and the new password works', async ({ page }) => {
            const NEW = 'e2e-PwChange-New-9!';

            await openPreferences(page);

            await page.fill('input[name=current_password]', PW_PASS);
            await page.fill('input[name=new_password]', NEW);
            await page.fill('input[name=new_password2]', NEW);
            await save(page);

            // The login form, saying why.
            expect(page.url()).toContain('base.loginForm');
            await expect(page.locator('body')).toContainText('new password');

            await loginAs(page, PW_USER, NEW);
            await openPreferences(page);

            // Put it back, so the fixture user is as every other spec expects.
            await page.fill('input[name=current_password]', NEW);
            await page.fill('input[name=new_password]', PW_PASS);
            await page.fill('input[name=new_password2]', PW_PASS);
            await save(page);

            await loginAs(page, PW_USER, PW_PASS);
        });
    });
});
