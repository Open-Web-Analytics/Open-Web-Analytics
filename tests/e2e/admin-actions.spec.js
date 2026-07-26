const { test, expect } = require('@playwright/test');
const {
    FIXTURE,
    login,
    loginAs,
    adminLogin,
    logout,
    clickAndWait,
} = require('./fixtures');

/**
 * Real-browser characterization of the admin write flows (the edit_* screens the
 * reporting specs never touch). These drive the ACTUAL forms in headless
 * Chromium against the live install, so every assertion exercises the whole
 * chain the reporting tests can't: capability gate -> nonce mint/verify ->
 * controller action -> entity persistence -> redirect.
 *
 * Why browser-driven (not raw POSTs): every admin write is nonce-guarded
 * (owa_adminController::setNonceRequired). The nonce is tied to the action
 * string + current user_id + a time window and is baked into the rendered form
 * by createNonceFormField(). Navigating to the real form and submitting it means
 * the page mints the nonce the server will accept -- a hardcoded/raw POST would
 * be rejected (and would be brittle against the nonce scheme by design). This
 * mirrors the existing login() helper's approach.
 *
 * Isolation: the destructive/global-state tests each snapshot -> mutate ->
 * restore within the test (options value, module activation), and the CRUD
 * tests create-then-delete their own throwaway site/user (E2E_NEW_* -- the
 * seeder's teardown also mops these up if a test aborts mid-flow). The admin
 * user itself is provisioned by seed_reporting_fixtures.php (E2E_ADMIN_*).
 *
 * Prereq: run `php tests/e2e/seed_reporting_fixtures.php seed` first (globalSetup
 * does this automatically for `npm run test:e2e`).
 */

// --- helpers scoped to this spec ---------------------------------------------

/** Land on an admin screen by its owa_do action, authenticated as admin. */
async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

test.describe('admin: authentication (login / logout)', () => {

    test('the admin fixture user can authenticate and reach the admin UI', async ({ page }) => {
        await adminLogin(page);
        // Post-login lands on base.sites; the admin (edit_sites) sees the
        // "Add New" affordance the analyst never gets.
        await expect(page.locator('text=Logout').first()).toBeVisible();
        await gotoAction(page, 'base.sites');
        await expect(
            page.locator('a', { hasText: 'Add New' }).first()
        ).toBeVisible();
    });

    test('bad credentials do not authenticate', async ({ page }) => {
        await loginAs(page, FIXTURE.adminUserId, 'totally-wrong-password');
        // Login failure re-renders the login form (message 2002); it must NOT
        // land authenticated -- no Logout control, and the password field is
        // still on the page.
        await expect(page.locator('input[name="owa_password"]')).toBeVisible();
        expect(await page.locator('text=Logout').count()).toBe(0);
    });

    test('logout ends the session', async ({ page }) => {
        await adminLogin(page);
        await expect(page.locator('text=Logout').first()).toBeVisible();

        await logout(page);

        // After logout, hitting an admin screen must bounce to the login form
        // rather than render the users list (the session is gone).
        await gotoAction(page, 'base.users');
        await expect(page.locator('input[name="owa_password"]')).toBeVisible();
    });
});

test.describe('admin: capability gate', () => {

    test('an analyst cannot reach an edit_* admin screen', async ({ page }) => {
        // The reporter fixture user is an analyst: it has view_reports but NOT
        // edit_users. Hitting the user-management screen must be denied (the
        // adminController capability gate), not render the user roster.
        await login(page);
        await gotoAction(page, 'base.users');

        // The user-management table (with the admin/reporter rows) must NOT render.
        // A denied request shows the access error, not the "Add New User" form link.
        expect(await page.locator('a', { hasText: 'Add New User' }).count()).toBe(0);
        const body = (await page.locator('body').innerText()).toLowerCase();
        expect(body).toMatch(/privile|login|access/);
    });
});

test.describe('admin: options update (revertible, snapshot + restore)', () => {

    test('updating a general option persists and can be reverted', async ({ page }) => {
        await adminLogin(page);

        // base.excluded_ips is a free-text field (comma-separated IPs) that is
        // safe to change and trivially reverted -- exactly what we want for a
        // non-destructive options round-trip. Snapshot the current value first.
        await gotoAction(page, 'base.optionsGeneral');
        const field = page.locator('input[name="owa_config[base.excluded_ips]"]');
        await expect(field).toBeVisible();
        const original = await field.inputValue();

        // A unique, obviously-a-test sentinel value we can assert on and revert.
        const sentinel = '203.0.113.222';

        const submit = async (value) => {
            await gotoAction(page, 'base.optionsGeneral');
            const f = page.locator('input[name="owa_config[base.excluded_ips]"]');
            await f.fill(value);
            // The form's submit BUTTON carries name=owa_action value=base.optionsUpdate
            // (the nonce is already baked in by createNonceFormField).
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.locator('button[name="owa_action"][value="base.optionsUpdate"]').click(),
            ]);
        };

        try {
            // Mutate -> reload the form -> the sentinel must have persisted.
            await submit(sentinel);
            await gotoAction(page, 'base.optionsGeneral');
            await expect(
                page.locator('input[name="owa_config[base.excluded_ips]"]')
            ).toHaveValue(sentinel);
        } finally {
            // Restore the original value no matter what the assertion did.
            await submit(original);
        }

        // And confirm the revert took (leave the install exactly as we found it).
        await gotoAction(page, 'base.optionsGeneral');
        await expect(
            page.locator('input[name="owa_config[base.excluded_ips]"]')
        ).toHaveValue(original);
    });
});

test.describe('admin: site CRUD', () => {

    test('add a site, edit its profile, then delete it', async ({ page }) => {
        await adminLogin(page);

        // --- ADD ---------------------------------------------------------------
        await gotoAction(page, 'base.sitesProfile'); // add form (no siteId => add)
        await page.fill('input[name="owa_domain"]', FIXTURE.newSiteDomain);
        await page.fill('input[name="owa_name"]', FIXTURE.newSiteName);
        await page.fill('textarea[name="owa_description"]', 'created by admin-actions e2e');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="owa_submit_btn"][value="Save Profile"]').click(),
        ]);

        // sitesAdd redirects to base.sites; the new site must appear in the list.
        await gotoAction(page, 'base.sites');
        await expect(page.locator(`text=${FIXTURE.newSiteName}`).first()).toBeVisible();
        await expect(page.locator(`text=${FIXTURE.newSiteDomain}`).first()).toBeVisible();

        // --- EDIT --------------------------------------------------------------
        // site_id = md5(domain-as-typed) -- same scheme the seeder/fixtures use.
        const createdSiteId = require('crypto')
            .createHash('md5')
            .update(FIXTURE.newSiteDomain)
            .digest('hex');
        const newName = 'OWA E2E Renamed Site';
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${createdSiteId}&owa_edit=1`);
        // On the edit form the domain is fixed (hidden) and only name/description
        // are editable; base.sitesEdit persists name.
        await page.fill('input[name="owa_name"]', newName);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="owa_submit_btn"][value="Save Profile"]').click(),
        ]);

        await gotoAction(page, 'base.sites');
        await expect(page.locator(`text=${newName}`).first()).toBeVisible();

        // --- DELETE ------------------------------------------------------------
        // The Delete link carries the &owa_nonce=... minted by the list page.
        await gotoAction(page, 'base.sites');
        const deleteLink = page
            .locator(`a[href*="base.sitesDelete"][href*="${createdSiteId}"]`)
            .first();
        await expect(deleteLink).toBeVisible();
        await clickAndWait(page, deleteLink);

        // Gone from the list.
        await gotoAction(page, 'base.sites');
        expect(await page.locator(`text=${newName}`).count()).toBe(0);
        expect(await page.locator(`text=${FIXTURE.newSiteDomain}`).count()).toBe(0);
    });
});

test.describe('admin: user CRUD + site association', () => {

    test('add a user, edit their role, associate with the fixture site, then delete', async ({ page }) => {
        await adminLogin(page);

        // --- ADD ---------------------------------------------------------------
        await gotoAction(page, 'base.usersProfile'); // add form (no user_id => add)
        await page.fill('input[name="owa_user_id"]', FIXTURE.newUserId);
        await page.fill('input[name="owa_real_name"]', 'OWA E2E Created User');
        await page.selectOption('select[name="owa_role"]', 'analyst');
        await page.fill('input[name="owa_email_address"]', FIXTURE.newUserId);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="owa_save_button"]').click(),
        ]);

        // usersAdd redirects to base.users; the new user appears in the roster.
        await gotoAction(page, 'base.users');
        await expect(page.locator(`text=${FIXTURE.newUserId}`).first()).toBeVisible();
        // Roster shows the role we assigned. Scope to the roster table so the
        // match can't collide with a like-named row elsewhere on the page (e.g.
        // the admin nav sidebar).
        const rosterRow = page.locator('table.management tbody tr', { hasText: FIXTURE.newUserId });
        await expect(rosterRow).toContainText('analyst');

        // --- EDIT (change role admin) -----------------------------------------
        await gotoAction(page, 'base.usersProfile', `&owa_edit=1&owa_user_id=${encodeURIComponent(FIXTURE.newUserId)}`);
        await page.selectOption('select[name="owa_role"]', 'admin');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="owa_save_button"]').click(),
        ]);
        await gotoAction(page, 'base.users');
        await expect(
            page.locator('table.management tbody tr', { hasText: FIXTURE.newUserId })
        ).toContainText('admin');

        // --- ASSOCIATE WITH SITE ----------------------------------------------
        // The fixture site's edit page carries the "Allowed Users" multi-select
        // (owa_allowed_users[] of INTERNAL user ids), and base.sitesEditAllowedUsers
        // REPLACES the site's entire grant set with exactly what's submitted
        // (site.updateAssignedUserIds deletes all owa_site_user rows for the site
        // then re-inserts the posted ids). So we must submit the UNION of the
        // already-assigned users (chiefly the reporter fixture user, whose grant
        // the reporting specs depend on -- there is no reseed between specs in a
        // full run) PLUS our newly-created user. Submitting only the new user
        // would silently revoke the reporter and break every downstream report.
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${FIXTURE.siteId}&owa_edit=1`);
        const usersSelect = page.locator('select[name="owa_allowed_users[]"]');
        await expect(usersSelect).toBeVisible();
        // The <option> label is "<user_id> / <real_name> (<role>)" and its value
        // is the INTERNAL user id. Resolve our new user's value + the currently
        // selected values in the browser (selectOption's label match is exact).
        const { newValue, currentValues } = await page.evaluate((userId) => {
            const sel = document.querySelector('select[name="owa_allowed_users[]"]');
            const opt = [...sel.options].find((o) => o.textContent.includes(userId));
            return {
                newValue: opt ? opt.value : null,
                currentValues: [...sel.selectedOptions].map((o) => o.value),
            };
        }, FIXTURE.newUserId);
        expect(newValue).not.toBeNull();
        // Union: keep every pre-selected grant, add ours.
        const unionValues = [...new Set([...currentValues, newValue])];
        await usersSelect.selectOption(unionValues);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="owa_submit_btn"][value="Save Users"]').click(),
        ]);

        // Re-open the edit page: our user's option is now SELECTED (the grant
        // round-tripped through base.sitesEditAllowedUsers -> site_user relation).
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${FIXTURE.siteId}&owa_edit=1`);
        const selectedLabels = await page.evaluate(() => {
            const sel = document.querySelector('select[name="owa_allowed_users[]"]');
            return sel
                ? [...sel.selectedOptions].map((o) => o.textContent.trim())
                : [];
        });
        expect(selectedLabels.some((l) => l.includes(FIXTURE.newUserId))).toBe(true);

        // --- DELETE ------------------------------------------------------------
        await gotoAction(page, 'base.users');
        const deleteLink = page
            .locator(`table.management tbody tr:has-text("${FIXTURE.newUserId}") a[href*="base.usersDelete"]`)
            .first();
        await expect(deleteLink).toBeVisible();
        await clickAndWait(page, deleteLink);

        await gotoAction(page, 'base.users');
        expect(await page.locator(`text=${FIXTURE.newUserId}`).count()).toBe(0);
    });
});

test.describe('admin: module activation (snapshot + restore)', () => {

    test('deactivate then reactivate the hello module', async ({ page }) => {
        await adminLogin(page);

        // The hello module is always present and, by convention, starts active
        // (renders a "Deactivate" link). We toggle it off then back on so the
        // install ends in its original state regardless.
        const helloRow = () =>
            page.locator('#module_roster tr', { hasText: 'Hello' }).first();

        await gotoAction(page, 'base.optionsModules');
        // Determine the starting state from which toggle link renders.
        const startsActive =
            (await helloRow().locator('a[href*="base.moduleDeactivate"]').count()) > 0;

        try {
            if (startsActive) {
                // Deactivate -> the row must now offer "Activate".
                await clickAndWait(
                    page,
                    helloRow().locator('a[href*="base.moduleDeactivate"]').first()
                );
                await gotoAction(page, 'base.optionsModules');
                await expect(
                    helloRow().locator('a[href*="base.moduleActivate"]')
                ).toHaveCount(1);

                // Reactivate -> back to offering "Deactivate".
                await clickAndWait(
                    page,
                    helloRow().locator('a[href*="base.moduleActivate"]').first()
                );
                await gotoAction(page, 'base.optionsModules');
                await expect(
                    helloRow().locator('a[href*="base.moduleDeactivate"]')
                ).toHaveCount(1);
            } else {
                // Symmetric path if hello happened to start deactivated.
                await clickAndWait(
                    page,
                    helloRow().locator('a[href*="base.moduleActivate"]').first()
                );
                await gotoAction(page, 'base.optionsModules');
                await expect(
                    helloRow().locator('a[href*="base.moduleDeactivate"]')
                ).toHaveCount(1);

                await clickAndWait(
                    page,
                    helloRow().locator('a[href*="base.moduleDeactivate"]').first()
                );
                await gotoAction(page, 'base.optionsModules');
                await expect(
                    helloRow().locator('a[href*="base.moduleActivate"]')
                ).toHaveCount(1);
            }
        } catch (e) {
            // Best-effort restore to the starting state on failure.
            await gotoAction(page, 'base.optionsModules');
            const wantLink = startsActive
                ? 'base.moduleDeactivate'
                : 'base.moduleActivate';
            const restore = helloRow().locator(`a[href*="${wantLink}"]`).first();
            if ((await restore.count()) > 0) {
                await clickAndWait(page, restore);
            }
            throw e;
        }
    });
});

test.describe('admin: password change (emailed-passkey flow)', () => {

    test('a user can set a new password via the temp-passkey form and log in with it', async ({ page }) => {
        // OWA has no logged-in "change my password" screen: a password change is
        // only reachable by authenticating a one-time temp_passkey (normally
        // emailed by base.passwordResetForm). The seeder plants a KNOWN passkey
        // (FIXTURE.pwPasskey) on a dedicated user (FIXTURE.pwUserId) so we can
        // drive the REAL base.usersChangePassword form exactly as a user clicking
        // the emailed link would -- no email interception needed.
        const newPassword = 'e2e-PwChange-New-2!';

        // 1. Load the password-entry form the emailed link targets (carries owa_k).
        await page.goto(
            `?owa_do=base.usersPasswordEntry&owa_k=${FIXTURE.pwPasskey}`,
            { waitUntil: 'networkidle' }
        );
        // The form posts password/password2 + hidden owa_k + owa_action.
        const pw = page.locator('input[name="owa_password"]');
        await expect(pw).toBeVisible();
        await pw.fill(newPassword);
        await page.fill('input[name="owa_password2"]', newPassword);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="owa_submit_btn"]').click(),
        ]);

        // 2. usersChangePassword redirects to the login form on success. Prove the
        //    change actually took: the OLD password no longer authenticates...
        await loginAs(page, FIXTURE.pwUserId, FIXTURE.pwOldPassword);
        await expect(page.locator('input[name="owa_password"]')).toBeVisible();
        expect(await page.locator('text=Logout').count()).toBe(0);

        // 3. ...and the NEW password does.
        await loginAs(page, FIXTURE.pwUserId, newPassword);
        await expect(page.locator('text=Logout').first()).toBeVisible();
    });
});
