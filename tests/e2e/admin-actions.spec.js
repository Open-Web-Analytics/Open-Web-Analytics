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
        await expect(page.locator('input[name="password"]')).toBeVisible();
        expect(await page.locator('text=Logout').count()).toBe(0);
    });

    test('logout ends the session', async ({ page }) => {
        await adminLogin(page);
        await expect(page.locator('text=Logout').first()).toBeVisible();

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

        // sitesAdd redirects to base.sites; the new site must appear in the list.
        await gotoAction(page, 'base.sites');
        await expect(page.locator(`text=${FIXTURE.newSiteName}`).first()).toBeVisible();
        await expect(page.locator(`text=${FIXTURE.newSiteDomain}`).first()).toBeVisible();

        // --- EDIT --------------------------------------------------------------
        // Read the identifier off the list rather than deriving it. site_id used
        // to be md5(domain-as-typed), so the spec could compute what the admin UI
        // would store; identifiers are minted now, so the only thing that knows
        // this site's id is the page that just listed it.
        const createdRow = page.locator('div.owa_reportSectionContent', {
            hasText: FIXTURE.newSiteDomain,
        });
        const editHref = await createdRow
            .locator('a[href*="base.sitesProfile"]')
            .first()
            .getAttribute('href');
        const editParams = new URL(editHref, page.url()).searchParams;
        // The link is built by makeLink(), which prefixes params with the app
        // namespace when one is configured -- so accept either spelling.
        const createdSiteId = editParams.get('owa_siteId') || editParams.get('siteId');
        expect(createdSiteId, 'the sites list must expose the new site id').toBeTruthy();
        const newName = 'OWA E2E Renamed Site';
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${createdSiteId}&owa_edit=1`);
        // On the edit form the domain is fixed (hidden) and only name/description
        // are editable; base.sitesEdit persists name.
        await page.fill('input[name="name"]', newName);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="submit_btn"][value="Save Profile"]').click(),
        ]);

        await gotoAction(page, 'base.sites');
        await expect(page.locator(`text=${newName}`).first()).toBeVisible();

        // --- DELETE ------------------------------------------------------------
        // The Delete link carries the &nonce=... minted by the list page (the
        // admin URL namespace is empty; the prefixed spelling is still accepted).
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
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${FIXTURE.siteId}&owa_edit=1`);

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
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${FIXTURE.siteId}&owa_edit=1`);
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
        await gotoAction(page, 'base.sitesProfile', `&owa_siteId=${FIXTURE.siteId}&owa_edit=1`);
        await expect(userRow()).toContainText('always has access');
        await expect(userRow().locator('input[type="checkbox"]')).toBeDisabled();
        expect(await userRow().locator('input[name="allowed_users[]"]').count()).toBe(0);

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
        expect(await page.locator('text=Logout').count()).toBe(0);

        // 3. ...and the NEW password does.
        await loginAs(page, FIXTURE.pwUserId, newPassword);
        await expect(page.locator('text=Logout').first()).toBeVisible();
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

    test('the bare request and an explicit base.sites render the same screen', async ({ page }) => {
        await login(page);

        await page.goto('?owa_do=base.sites', { waitUntil: 'networkidle' });
        const explicitTitle = await page.title();

        await page.goto('?', { waitUntil: 'networkidle' });
        const defaultTitle = await page.title();

        // start_page defaults to base.sites (Settings.php), so the two must agree.
        // Comparing rendered titles rather than asserting a literal keeps this
        // honest if the default is ever repointed at a different report.
        expect(defaultTitle, 'the default action should land on the start_page report')
            .toBe(explicitTitle);
    });

    /**
     * The start_page report itself -- reached by default, so a break here takes
     * out the admin UI's front door and every post-login redirect with it.
     */
    test('the start_page report lists the fixture site', async ({ page }) => {
        await login(page);
        await page.goto('?', { waitUntil: 'networkidle' });

        const body = await page.content();

        // The roster has to actually render its rows, not just a chrome shell.
        expect(body, 'the sites report should list the fixture site')
            .toContain(FIXTURE.siteDomain);

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
