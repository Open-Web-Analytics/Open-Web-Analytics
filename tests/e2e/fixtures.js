/**
 * Shared identifiers + login helper for the reporting-UI Playwright specs.
 *
 * These MUST match the constants in seed_reporting_fixtures.php -- that PHP
 * seeder is the source of truth for the data these tests characterize. Run
 *
 *   php tests/e2e/seed_reporting_fixtures.php seed
 *
 * before the browser tests so the fixture site, user, and pageviews exist.
 */

// Mirrors the E2E_* constants in seed_reporting_fixtures.php.
const FIXTURE = {
    siteId: 'fdb09e48d872a24bca13083c2c5f7579', // md5('https://owa-e2e.example.test')
    siteDomain: 'https://owa-e2e.example.test',
    userId: 'owa-e2e-reporter@example.test',
    password: 'e2e-Reporter-Pass-1!', // throwaway LOCAL fixture creds, never production
    // The four page titles seeded (each with 2 pageviews).
    pageTitles: ['E2E Home', 'E2E pricing', 'E2E docs', 'E2E about'],
    expectedGridRows: 4,
    // The admin-role fixture user (E2E_ADMIN_* in the seeder). The reporter
    // above is an analyst and cannot reach any edit_* admin screen; the
    // admin-actions suite logs in as this user instead.
    adminUserId: 'owa-e2e-admin@example.test',
    adminPassword: 'e2e-Admin-Pass-1!', // throwaway LOCAL fixture creds, never production
    // The password-CHANGE fixture user (E2E_PWUSER_* in the seeder). OWA has no
    // logged-in "change my password" form, only the emailed-passkey reset flow,
    // so the seeder plants a KNOWN temp_passkey the test submits to the real
    // base.usersChangePassword form. Isolated to its own user so rotating its
    // creds can't disturb the admin/reporter logins.
    pwUserId: 'owa-e2e-pwchange@example.test',
    pwOldPassword: 'e2e-PwChange-Old-1!',
    pwPasskey: '735512bd84ae1f2635e3e89fb7ecc001',
    // Identifiers the CRUD tests CREATE then delete (mirrors E2E_NEW_* in the
    // seeder, which teardown mops up if a test aborts mid-flow).
    newSiteDomain: 'https://owa-e2e-created.example.test',
    newSiteName: 'OWA E2E Created Site',
    newUserId: 'owa-e2e-created@example.test',
    // The always-present optional module the module-activation test toggles.
    toggleModule: 'hello',
};

/**
 * Log in through the real OWA login form as the given user and land
 * authenticated. Leaves the page on the post-login redirect (base.sites).
 */
async function loginAs(page, userId, password) {
    await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });
    await page.fill('input[name="owa_user_id"]', userId);
    await page.fill('input[name="owa_password"]', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('input[name="owa_submit_btn"]'),
    ]);
}

/** Log in as the analyst reporter fixture user. */
async function login(page) {
    await loginAs(page, FIXTURE.userId, FIXTURE.password);
}

/** Log in as the admin fixture user (needed for every edit_* admin screen). */
async function adminLogin(page) {
    await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
}

/**
 * Log out through the real logout action and land back on the login form.
 * base.logout clears the auth cookie/session and redirects to base.loginForm.
 */
async function logout(page) {
    await page.goto('?owa_do=base.logout', { waitUntil: 'networkidle' });
}

/**
 * Follow a nonce-guarded admin action link (Delete / Activate / Deactivate).
 * These are rendered by makeLink(..., true) with the &owa_nonce=... already
 * baked into the href, so the test just has to click the right anchor -- the
 * server verifies the nonce that the page itself minted. `page` must already be
 * on the list page that renders the link. Returns after the redirect settles.
 */
async function clickAndWait(page, locator) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        locator.click(),
    ]);
}

/** Navigate to the fixture site's report dashboard and let the JS build widgets. */
async function openDashboard(page, { period = 'last_thirty_days' } = {}) {
    await page.goto(
        `?owa_do=base.reportDashboard&owa_siteId=${FIXTURE.siteId}&owa_period=${period}`,
        { waitUntil: 'networkidle' }
    );
    // The reporting bundle builds charts/grids/selects from AJAX result sets
    // after load; wait for the load-bearing widgets rather than a blind sleep.
    await page.waitForSelector('tr.jqgrow', { timeout: 20_000 });
}

/**
 * Navigate to a dimension report page (owa_do=base.report<Name>) and wait for
 * the jQuery-UI tabs widget to build. Unlike the dashboard, these pages render
 * the tabbed report layout (owa.report.createTabs -> #report-tabs.ui-tabs).
 * Defaults to Browser Types -- a plain dimension report needing no extra params.
 */
async function openReport(page, { doName = 'base.reportBrowsers', period = 'last_thirty_days' } = {}) {
    await page.goto(
        `?owa_do=${doName}&owa_siteId=${FIXTURE.siteId}&owa_period=${period}`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('#report-tabs.ui-tabs', { timeout: 20_000 });
}

module.exports = {
    FIXTURE,
    login,
    loginAs,
    adminLogin,
    logout,
    clickAndWait,
    openDashboard,
    openReport,
};
