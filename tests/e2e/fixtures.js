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
};

/**
 * Log in through the real OWA login form and land authenticated.
 * Leaves the page on the post-login redirect (base.sites).
 */
async function login(page) {
    await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });
    await page.fill('input[name="owa_user_id"]', FIXTURE.userId);
    await page.fill('input[name="owa_password"]', FIXTURE.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('input[name="owa_submit_btn"]'),
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

module.exports = { FIXTURE, login, openDashboard };
