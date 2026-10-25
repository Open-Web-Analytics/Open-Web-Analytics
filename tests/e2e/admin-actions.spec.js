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

/**
 * Click something destructive and confirm it.
 *
 * Anything carrying data-owa-confirm opens a modal instead of acting, so a
 * plain click-and-wait-for-navigation hangs: the navigation only happens after
 * Proceed. Used for both shapes -- a link (users) and a submit button (Profile,
 * Property).
 */
async function confirmAndWait(page, locator) {
    await locator.click();

    const dialog = page.locator('#owa_confirmDialog');
    await expect(dialog).toBeVisible();

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('.owa_confirmProceed').click(),
    ]);
}

test.describe('admin: authentication (login / logout)', () => {

    test('the admin fixture user can authenticate and reach the admin UI', async ({ page }) => {
        await adminLogin(page);
        // Post-login lands on base.reportingHome, which redirects to the last
        // Profile's dashboard. base.sites is gone -- the roster of Profiles is
        // the site control's fan-out now, and its "add new" is the affordance
        // an admin (edit_sites) gets and an analyst never does.
        /*
         * The account menu, not the word "Logout".
         *
         * Logout lives inside that menu now and is hidden until it is opened,
         * so a visibility check on the text fails for a perfectly good session.
         * The toggle is a better signal anyway: it is rendered only for an
         * authenticated request.
         */
        await expect(page.locator('#owa_userMenuToggle')).toBeVisible();
        await expect(page.locator('#owa_siteControl')).toBeVisible();
        await expect(
            page.locator('#owa_siteControlPanel a.owa_siteControlAdd').first()
        ).toHaveCount(1);
    });

    test('bad credentials do not authenticate', async ({ page }) => {
        await loginAs(page, FIXTURE.adminUserId, 'totally-wrong-password');
        // Login failure re-renders the login form (message 2002); it must NOT
        // land authenticated -- no Logout control, and the password field is
        // still on the page.
        await expect(page.locator('input[name="password"]')).toBeVisible();
        expect(await page.locator('#owa_userMenuToggle').count()).toBe(0);
    });

    test('logout ends the session', async ({ page }) => {
        await adminLogin(page);
        await expect(page.locator('#owa_userMenuToggle')).toBeVisible();

        await logout(page);

        // After logout, hitting an admin screen must bounce to the login form
        // rather than render the users list (the session is gone).
        await gotoAction(page, 'base.users');
        await expect(page.locator('input[name="password"]')).toBeVisible();
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
        const field = page.locator('input[name="config[base.excluded_ips]"]');
        await expect(field).toBeVisible();
        const original = await field.inputValue();

        // A unique, obviously-a-test sentinel value we can assert on and revert.
        const sentinel = '203.0.113.222';

        const submit = async (value) => {
            await gotoAction(page, 'base.optionsGeneral');
            const f = page.locator('input[name="config[base.excluded_ips]"]');
            await f.fill(value);
            // The form's submit BUTTON carries name=owa_action value=base.optionsUpdate
            // (the nonce is already baked in by createNonceFormField).
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.locator('button[name="action"][value="base.optionsUpdate"]').click(),
            ]);
        };

        try {
            // Mutate -> reload the form -> the sentinel must have persisted.
            await submit(sentinel);
            await gotoAction(page, 'base.optionsGeneral');
            await expect(
                page.locator('input[name="config[base.excluded_ips]"]')
            ).toHaveValue(sentinel);
        } finally {
            // Restore the original value no matter what the assertion did.
            await submit(original);
        }

        // And confirm the revert took (leave the install exactly as we found it).
        await gotoAction(page, 'base.optionsGeneral');
        await expect(
            page.locator('input[name="config[base.excluded_ips]"]')
        ).toHaveValue(original);
    });
});

test.describe('admin: site CRUD', () => {

    test('add a site, edit its profile, then delete it', async ({ page }) => {
        await adminLogin(page);

        // --- ADD ---------------------------------------------------------------
        await gotoAction(page, 'base.sitesProfile'); // add form (no siteId => add)
        await page.fill('input[name="domain"]', FIXTURE.newSiteDomain);
        await page.fill('input[name="name"]', FIXTURE.newSiteName);
        await page.fill('textarea[name="description"]', 'created by admin-actions e2e');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="submit_btn"][value="Save Profile"]').click(),
        ]);

        // sitesAdd redirects to the new Profile's Tracking Tag. The site
        // control's fan-out lists Properties -- base.sites is gone -- so that is
        // where the new Property has to show up, and its Profile is on the
        // Property's Observation Profiles roster.
        await page.goto('?', { waitUntil: 'networkidle' });

        // The typed name and domain land on the PROPERTY. The Profile is named
        // for its position under it ('Observation Profile 1'), because the human
        // name describes the website and a Profile is only one way of watching
        // it. That split is the point of the hierarchy, so each half is asserted
        // where it belongs rather than anywhere on the page -- an unscoped match
        // finds the Property and reads as though it had found the Profile.
        const propertyRow = page.locator(
            '.owa_siteControlProperties li.owa_siteControlItem',
            { hasText: FIXTURE.newSiteName },
        );
        await expect(propertyRow).toHaveCount(1);

        // Normalised, so the fixture's scheme is not part of what is stored:
        // normaliseDomain() strips it precisely so http://x and https://x are
        // one website rather than two Properties.
        const newSiteHost = FIXTURE.newSiteDomain.replace(/^[a-z]+:\/\//, '');
        await expect(propertyRow.locator('.owa_siteControlDomain'))
            .toContainText(newSiteHost);

        // The links are built by makeLink(), which prefixes params with the app
        // namespace when one is configured -- so accept either spelling.
        const paramOf = (href, name) => {
            const params = new URL(href, page.url()).searchParams;
            return params.get(`owa_${name}`) || params.get(name);
        };

        const propertyId = paramOf(
            await propertyRow.locator('a.owa_siteControlEdit').getAttribute('href'), 'propertyId');
        expect(propertyId, 'the site control must expose the new Property id').toBeTruthy();

        const openRoster = () => gotoAction(page, 'base.propertyProfiles', `&owa_propertyId=${propertyId}`);
        const profileRows = () => page.locator('table.owa_propertyProfiles tbody tr');

        await openRoster();
        await expect(profileRows(), 'the new Property should have exactly one Profile').toHaveCount(1);

        // --- EDIT --------------------------------------------------------------
        // Read the identifier off the roster rather than deriving it. site_id
        // used to be md5(domain-as-typed), so the spec could compute what the
        // admin UI would store; identifiers are minted now, so the only thing
        // that knows this site's id is the page that just rendered it.
        const createdSiteId = paramOf(
            await profileRows().locator('a', { hasText: 'Details' }).getAttribute('href'), 'siteId');
        expect(createdSiteId, 'the roster must expose the new site id').toBeTruthy();
        await expect(profileRows()).toContainText(createdSiteId);

        const newName = 'OWA E2E Renamed Profile';
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${createdSiteId}&owa_edit=1`);
        // On the edit form the domain is fixed (hidden) and only name/description
        // are editable; base.sitesEdit persists name.
        await page.fill('input[name="name"]', newName);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="submit_btn"][value="Save Profile"]').click(),
        ]);
        // The save returns to this Profile's details, not the add form.
        await expect(page.locator('.panel_headline')).toHaveText('Profile Details');
        await expect(page.locator('input[name="name"]')).toHaveValue(newName);

        await openRoster();
        await expect(profileRows(), 'renaming a Profile must not rename its Property')
            .toContainText(newName);
        await page.goto('?', { waitUntil: 'networkidle' });
        await expect(propertyRow, 'the Property keeps its own name').toHaveCount(1);

        // --- DELETE ------------------------------------------------------------
        // Deleting a Profile lives on Profile Details now. It was on the
        // base.sites roster, and removing that screen left base.sitesDelete
        // registered but linked from nowhere -- so there was no way to delete a
        // Profile at all.
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${createdSiteId}&owa_edit=1`);

        const deleteButton = page.locator('input[value="Delete Profile"]');
        await expect(deleteButton).toBeVisible();

        // A real modal now, not window.confirm(). The old alert could not say
        // what was kept as opposed to destroyed -- and Playwright DISMISSES
        // native dialogs by default, so a test driving the delete passed while
        // deleting nothing.
        await deleteButton.click();

        const confirmDialog = page.locator('#owa_confirmDialog');
        await expect(confirmDialog).toBeVisible();

        // It has to say what actually happens: this is archived, not destroyed.
        await expect(confirmDialog).toContainText('restore');

        // Cancel is the default answer to a destructive question, so it must
        // really cancel.
        await page.locator('.owa_confirmCancel').click();
        await expect(confirmDialog).toBeHidden();
        await expect(page.locator('input[value="Delete Profile"]')).toBeVisible();

        await deleteButton.click();
        await expect(page.locator('#owa_confirmDialog')).toBeVisible();

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('.owa_confirmProceed').click(),
        ]);

        // Back on the roster it was deleted from, which no longer lists it.
        expect(paramOf(page.url(), 'do')).toBe('base.propertyProfiles');
        await expect(profileRows()).toHaveCount(0);

        await page.goto('?', { waitUntil: 'networkidle' });

        // Its Property SURVIVES, empty and still reachable.
        //
        // The cascade runs downward only: deleting a Property takes its Profiles,
        // but deleting a Profile never takes its Property. An empty Property is a
        // legitimate state -- it is how you start a website's Profiles over --
        // and that only works if you can still get to it.
        //
        // It used to be filtered out of the fan-out for having no Profiles, which
        // made it unreachable: present in the database, invisible everywhere, with
        // no screen that could bring it back.
        await expect(propertyRow, 'the empty Property must stay reachable')
            .toHaveCount(1);

        // And it really is empty -- the Profile went, the Property did not.
        await openRoster();
        await expect(profileRows()).toHaveCount(0);
        await expect(page.locator('.owa_emptyState')).toBeVisible();
    });

    test('a new Property goes on to adding its first Profile', async ({ page }) => {
        await adminLogin(page);

        await gotoAction(page, 'base.propertyProfile'); // no propertyId => add
        await page.fill('input[name="name"]', FIXTURE.newPropertyName);
        await page.fill('input[name="domain"]', FIXTURE.newPropertyDomain);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[type="submit"][value="Save Property"]').click(),
        ]);

        // Landed on the add-a-Profile form with the new Property chosen.
        const url = new URL(page.url());
        expect(url.searchParams.get('owa_do') || url.searchParams.get('do')).toBe('base.sitesProfile');

        const picker = page.locator('#owa_profilePropertyId');
        const chosen = picker.locator('option:checked');
        await expect(chosen).toContainText(FIXTURE.newPropertyName);
        const propertyId = await picker.inputValue();
        expect(propertyId, 'the picker is still on "a new Property"').not.toBe('');

        // A web Property's Profile starts on its domain, and needs no new name.
        await expect(page.locator('#owa_streamType')).toHaveValue('web');
        await expect(page.locator('input[name="domain"]')).toHaveValue(FIXTURE.newPropertyDomain);
        await expect(page.locator('#owa_newPropertyFields')).toBeHidden();

        // The site control is on the new Property, though it has no Profile.
        await expect(page.locator('#owa_siteControlSummary .owa_siteControlProperty'))
            .toContainText(FIXTURE.newPropertyName);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="submit_btn"][value="Save Profile"]').click(),
        ]);

        // Landed on the new Profile's Tracking Tag, where its snippet is.
        const tagUrl = new URL(page.url());
        expect(tagUrl.searchParams.get('owa_do') || tagUrl.searchParams.get('do'), page.url())
            .toBe('base.sitesInvocation');
        const newSiteId = tagUrl.searchParams.get('owa_siteId') || tagUrl.searchParams.get('siteId');
        expect(newSiteId).toBeTruthy();
        await expect(page.locator('body')).toContainText(newSiteId);
        await expect(page.locator('#owa_siteControlSummary .owa_siteControlProperty'))
            .toContainText(FIXTURE.newPropertyName);

        // The Profile was created under the new Property, not a Property of its own.
        const propertyRow = page.locator(
            '.owa_siteControlProperties li.owa_siteControlItem',
            { hasText: FIXTURE.newPropertyName },
        );
        await expect(propertyRow).toHaveCount(1);
        await gotoAction(page, 'base.propertyProfiles', `&owa_propertyId=${propertyId}`);
        await expect(page.locator('table.owa_propertyProfiles tbody tr')).toHaveCount(1);
        await expect(page.locator('table.owa_propertyProfiles tbody tr')).toContainText(newSiteId);

        // Remove it; teardown deletes the archived rows.
        await gotoAction(page, 'base.propertyProfile', `&owa_propertyId=${propertyId}`);
        await confirmAndWait(page, page.locator('input[value="Delete Property"]'));
        await page.goto('?', { waitUntil: 'networkidle' });
        await expect(propertyRow).toHaveCount(0);
    });
});

test.describe('admin: user CRUD + site association', () => {

    test('add a user, edit their role, associate with the fixture site, then delete', async ({ page }) => {
        await adminLogin(page);

        // --- ADD ---------------------------------------------------------------
        await gotoAction(page, 'base.usersProfile'); // add form (no user_id => add)
        await page.fill('input[name="user_id"]', FIXTURE.newUserId);
        await page.fill('input[name="real_name"]', 'OWA E2E Created User');
        await page.selectOption('select[name="role"]', 'analyst');
        await page.fill('input[name="email_address"]', FIXTURE.newUserId);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="save_button"]').click(),
        ]);

        // usersAdd redirects to base.users; the new user appears in the roster.
        await gotoAction(page, 'base.users');
        await expect(page.locator(`text=${FIXTURE.newUserId}`).first()).toBeVisible();
        // Roster shows the role we assigned. Scope to the roster table so the
        // match can't collide with a like-named row elsewhere on the page (e.g.
        // the admin nav sidebar).
        const rosterRow = page.locator('table.management tbody tr', { hasText: FIXTURE.newUserId });
        await expect(rosterRow).toContainText('analyst');

        // --- ASSOCIATE WITH SITE ----------------------------------------------
        // Done while the user is still an analyst, deliberately. A grant only
        // means anything for a non-admin: isSiteAccessible() returns true for
        // role admin before it consults owa_site_user at all, so granting an
        // admin writes a row that changes nothing. The old multi-select offered
        // that choice anyway and this test took it -- the assertion round-tripped
        // through the database and looked meaningful while testing nothing.
        //
        // The form submits a DELTA: the ids it rendered travel with the ids that
        // were checked, so ticking this user grants only this user. It used to
        // submit the UNION of every existing grant plus the new one, because the
        // controller replaced the whole set and posting just one user would
        // silently revoke the reporter fixture and break every downstream report.
        // The grant form is its own screen now -- Property Access Management --
        // rather than a third form on the Profile page.
        await gotoAction(page, 'base.propertyAccess', `&owa_siteId=${FIXTURE.siteId}`);

        const userRow = () => page.locator('table.owa-allowed-users tr.owa-user-row', {
            hasText: FIXTURE.newUserId,
        });
        await expect(userRow()).toBeVisible();

        const userCheckbox = userRow().locator('input[name="allowed_users[]"]');
        await expect(userCheckbox).toBeVisible();

        // Record the other grants so we can prove the delta left them untouched.
        const grantsBefore = await page.evaluate(() =>
            [...document.querySelectorAll('input[name="allowed_users[]"]:checked')]
                .map((el) => el.value)
        );

        await userCheckbox.check();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="submit_btn"][value="Save Users"]').click(),
        ]);

        // Re-open: our user is ticked (the grant round-tripped into an
        // owa_site_user relation), and every grant that existed beforehand
        // survives -- the property that a user the form did not target is never
        // affected, which the replace-everything version could not offer.
        await gotoAction(page, 'base.propertyAccess', `&owa_siteId=${FIXTURE.siteId}`);
        await expect(userRow().locator('input[name="allowed_users[]"]')).toBeChecked();

        const grantsAfter = await page.evaluate(() =>
            [...document.querySelectorAll('input[name="allowed_users[]"]:checked')]
                .map((el) => el.value)
        );
        for (const value of grantsBefore) {
            expect(grantsAfter).toContain(value);
        }

        // --- EDIT (change role admin) -----------------------------------------
        await gotoAction(page, 'base.usersProfile', `&owa_edit=1&owa_user_id=${encodeURIComponent(FIXTURE.newUserId)}`);
        await page.selectOption('select[name="role"]', 'admin');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="save_button"]').click(),
        ]);

        await gotoAction(page, 'base.users');
        await expect(
            page.locator('table.management tbody tr', { hasText: FIXTURE.newUserId })
        ).toContainText('admin');

        // Now that they are an admin, the site form stops offering a choice it
        // would not honour: the row renders a disabled, ticked box and says so,
        // with no submittable field to send.
        await gotoAction(page, 'base.propertyAccess', `&owa_siteId=${FIXTURE.siteId}`);
        await expect(userRow()).toContainText('always has access');
        await expect(userRow().locator('input[type="checkbox"]')).toBeDisabled();
        expect(await userRow().locator('input[name="allowed_users[]"]').count()).toBe(0);

        // --- DELETE ------------------------------------------------------------
        await gotoAction(page, 'base.users');
        const deleteLink = page
            .locator(`table.management tbody tr:has-text("${FIXTURE.newUserId}") a[href*="base.usersDelete"]`)
            .first();
        await expect(deleteLink).toBeVisible();
        // Deleting a user is confirmed too -- and unlike a Profile it is not
        // archived, so the modal is the only thing standing in front of it.
        await confirmAndWait(page, deleteLink);

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
        const pw = page.locator('input[name="password"]');
        await expect(pw).toBeVisible();
        await pw.fill(newPassword);
        await page.fill('input[name="password2"]', newPassword);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="submit_btn"]').click(),
        ]);

        // 2. usersChangePassword redirects to the login form on success. Prove the
        //    change actually took: the OLD password no longer authenticates...
        await loginAs(page, FIXTURE.pwUserId, FIXTURE.pwOldPassword);
        await expect(page.locator('input[name="password"]')).toBeVisible();
        expect(await page.locator('#owa_userMenuToggle').count()).toBe(0);

        // 3. ...and the NEW password does.
        await loginAs(page, FIXTURE.pwUserId, newPassword);
        await expect(page.locator('#owa_userMenuToggle')).toBeVisible();
    });
});

/**
 * The admin endpoint's default action.
 *
 * index.php supplies a route when the request does not name one:
 *
 *     $do = CoreAPI::getRequestParam('do');
 *     if ( ! $do ) { $params['do'] = $owa->getSetting('base', 'start_page'); }
 *
 * Arriving at index.php with no params is the normal way into the admin UI, so
 * this fallback has to keep working. It is also the reason the REST endpoint's
 * missing-route handling is deliberately NOT symmetric -- asserted at the bottom
 * so the asymmetry is pinned rather than incidental.
 */
/**
 * THE FAN-OUT'S ROWS ARE CLICKABLE ACROSS THEIR WHOLE WIDTH.
 *
 * The row is a flex box: the name on the left, a secondary link on the right.
 * So a row's own link was as wide as its text and everything else was bare
 * <li>, which carries no handler and no href. A click on the padding, on the
 * gap, or right of the name did nothing, while cursor:pointer on the row said
 * the whole width was live.
 *
 * The control stops at the Property now -- a report is of a Property -- so the
 * Properties column carries the stretched link.
 */
test.describe('admin: the site control fan-out', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.goalEvents&owa_siteId=${FIXTURE.siteId}`,
            { waitUntil: 'networkidle' });
        await page.locator('#owa_siteControlSummary').click();
        await expect(page.locator('#owa_siteControlPanel')).toBeVisible();
    });

    /** Where a point lands: the row's link, the bare row, or nothing. */
    async function hitAt(page, columnClass, fraction) {
        return page.evaluate(([col, f]) => {
            const li = document.querySelector('.' + col + ' .owa_siteControlItem.is-selected')
                    || document.querySelector('.' + col + ' .owa_siteControlItem');

            if (!li) { return 'no row'; }

            li.scrollIntoView({ block: 'center' });

            const r = li.getBoundingClientRect();
            const x = f === 0 ? r.left + 3 : (f === 1 ? r.right - 6 : r.left + r.width * f);

            let n = document.elementFromPoint(x, r.top + r.height / 2);

            while (n) {
                if (n.classList && n.classList.contains('owa_siteControlSelect')) { return 'link'; }
                if (n.classList && n.classList.contains('owa_siteControlItem')) { return 'row only'; }
                n = n.parentElement;
            }

            return 'nothing';
        }, [columnClass, fraction]);
    }

    test('a Property row activates its link from edge to edge', async ({ page }) => {
        for (const [where, fraction] of [['left edge', 0], ['middle', 0.5], ['right edge', 1]]) {

            expect(await hitAt(page, 'owa_siteControlProperties', fraction),
                `the ${where} of a Property row does not reach its link`).toBe('link');
        }
    });

    /**
     * And the secondary link still wins inside that area.
     *
     * "edit" sits within the region the row's own link covers, so it needs to
     * sit above it -- a secondary action you cannot click is a worse bug than
     * the one being fixed. It is revealed on hover, so the row is hovered first.
     */
    test('the edit link stays reachable inside the stretched row', async ({ page }) => {
        const row = page.locator('.owa_siteControlProperties .owa_siteControlItem').first();

        await expect(row).toBeVisible();
        await row.hover();

        const edit = row.locator('a.owa_siteControlEdit').first();
        await expect(edit).toBeVisible();

        const onTop = await edit.evaluate((a) => {
            const r = a.getBoundingClientRect();
            let n = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);

            while (n) {
                if (n === a) { return true; }
                if (n.classList && n.classList.contains('owa_siteControlSelect')) { return false; }
                n = n.parentElement;
            }

            return false;
        });

        expect(onTop, "the row's own link is covering a secondary action").toBe(true);
    });

    /**
     * AND CLICKING A PROPERTY OPENS ITS REPORTS.
     *
     * The hit tests above prove the pointer reaches the link. They do not prove
     * the link goes anywhere: a link with no `do` lands on the default action,
     * which drops what it was given and picks the first site the reader may see.
     */
    test('clicking a Property opens its dashboard', async ({ page }) => {
        const row = page.locator('.owa_siteControlProperties .owa_siteControlItem').last();

        await expect(row).toBeVisible();

        const href = await row.locator('a.owa_siteControlSelect').getAttribute('href');
        const wanted = href.match(/propertyId=([^&]+)/)[1];
        const name = (await row.locator('.owa_siteControlItemName').textContent()).trim();

        expect(wanted, 'the Property link carries no propertyId').toBeTruthy();
        expect(href, 'the Property link names no action').toContain('do=');

        // Clicked at the ROW's edge, not on the words -- so this covers the
        // stretch and the destination together, which is how someone uses it.
        const box = await row.boundingBox();

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.mouse.click(box.x + box.width - 8, box.y + box.height / 2),
        ]);

        expect(page.url()).toContain(wanted);
        expect(page.url()).not.toMatch(/siteId=/);
        await expect(page.locator('#owa_siteControlSummary .owa_siteControlProperty')).toContainText(name);
    });

    /**
     * Rows scroll UNDER the column heading, not over it.
     *
     * The column is shrunk and scrolled so this holds whatever the number of
     * Properties, then the point at the heading's centre is asked what is on
     * top there.
     */
    test('a scrolled column keeps its heading on top', async ({ page }) => {
        const onTop = await page.evaluate(() => {
            const column = document.querySelector('.owa_siteControlProperties');
            const head = column.querySelector('.owa_siteControlColumnHead');

            column.style.maxHeight = '90px';
            column.scrollTop = 40;

            const r = head.getBoundingClientRect();
            const n = document.elementFromPoint(r.left + 12, r.top + r.height / 2);

            return { scrolled: column.scrollTop > 0, head: !!(n && n.closest('.owa_siteControlColumnHead')) };
        });

        expect(onTop.scrolled, 'the column did not scroll, so this proves nothing').toBe(true);
        expect(onTop.head, 'a row is painted over the column heading').toBe(true);
    });

    /** The closed tile reads Property first: the Organization is a quiet label above it. */
    test('the tile names its Property larger than its Organization', async ({ page }) => {
        const size = (sel) => page.locator(sel).evaluate((el) => parseFloat(getComputedStyle(el).fontSize));

        expect(await size('#owa_siteControlSummary .owa_siteControlProperty'))
            .toBeGreaterThan(await size('#owa_siteControlSummary .owa_siteControlOrg') + 3);
    });

    test('the control offers no Profiles', async ({ page }) => {
        await expect(page.locator('.owa_siteControlProfiles')).toHaveCount(0);
    });
});

test.describe('admin: start_page default action', () => {

    test('a bare request renders the start_page report, not an error', async ({ page }) => {
        await login(page);

        // No owa_do at all -- the fallback has to supply base.sites.
        const res = await page.goto('?', { waitUntil: 'networkidle' });

        expect(res.status(), 'a bare admin request should render').toBe(200);

        const body = await page.content();
        expect(body.length, 'a bare request must not render an empty page').toBeGreaterThan(0);
        expect(body).not.toMatch(/Fatal error|Uncaught Exception|Invalid action/i);
    });

    test('the bare request and an explicit start_page render the same screen', async ({ page }) => {
        await login(page);

        // start_page is base.reportingHome now, not the base.sites roster --
        // that screen is gone and you land on the last Property you looked at.
        // Read the setting rather than hardcoding it, so this stays honest if
        // the default is repointed again.
        await page.goto('?owa_do=base.reportingHome', { waitUntil: 'networkidle' });
        const explicitTitle = await page.title();
        const explicitUrl = page.url();

        await page.goto('?', { waitUntil: 'networkidle' });
        const defaultTitle = await page.title();

        expect(defaultTitle, 'the default action should land on the start_page report')
            .toBe(explicitTitle);

        // Both resolve the redirect to the same report, not just the same shell.
        expect(page.url()).toBe(explicitUrl);
    });

    /**
     * The start_page report itself -- reached by default, so a break here takes
     * out the admin UI's front door and every post-login redirect with it.
     */
    test('the start_page lands on a report scoped to a Property', async ({ page }) => {
        await login(page);
        await page.goto('?', { waitUntil: 'networkidle' });

        const body = await page.content();

        // It used to be the base.sites roster and the assertion was that the
        // fixture site appeared as a ROW. The front door is a report now, so
        // what has to be true is that it arrived scoped to a Property: no scope
        // means makeNavigationMenu() returns false and there is no left nav at
        // all, which is the failure this guards.
        expect(page.url(), 'the front door must resolve to a Property')
            .toMatch(/propertyId=/);

        expect(body, 'the report chrome should carry the site control')
            .toContain('owa_siteControl');

        // And it must be the report, not a bounce back to the login form.
        expect(body).not.toMatch(/input[^>]+name="password"/);
    });

    test('the REST endpoint does NOT default -- it reports a bad request', async ({ page }) => {
        // Same omission, opposite contract: a REST client that names no route has
        // made a malformed request, and picking one for it would hide the mistake.
        const res = await page.request.get('api/index.php');

        expect(res.status(), 'REST must not fall back to a default route').toBe(400);

        const body = JSON.parse(await res.text());
        expect(body.httpResponse.status_code).toBe(400);
    });
});

test.describe('admin: settings screens', () => {

    // The column and the Property chooser say where you are; no breadcrumb.
    test('have no breadcrumb', async ({ page }) => {
        await adminLogin(page);

        for (const action of ['base.sitesInvocation', 'base.propertyProfile', 'base.optionsGeneral']) {
            await gotoAction(page, action, `&owa_siteId=${FIXTURE.siteId}`);
            await expect(page.locator('#owa_navColumn')).toBeVisible();
            await expect(page.locator('.owa_hierarchyCrumb')).toHaveCount(0);
        }
    });
});
