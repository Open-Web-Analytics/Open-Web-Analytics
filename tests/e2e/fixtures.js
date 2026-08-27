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
    // A custom report belonging to SOMEBODY ELSE (E2E_OTHERS_REPORT_* in the
    // seeder). Its owner never signs in, which is the point: editing another
    // author's report is decided by a capability rather than by ownership, and
    // a test that builds a report and then opens it is always looking at its
    // own -- where ownership alone is enough and the capability is never
    // exercised. That blind spot hid a real bug.
    othersReportName: 'E2E Owned By Someone Else',
    othersReportOwner: 'owa-e2e-someone-else@example.test',

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
    // Traffic attribution DERIVED from the referring URLs in the $visits table
    // of seed_reporting_fixtures.php. The seeder sets only session_referer;
    // deriveMedium/deriveSource/extractSearchTerm produce everything below, so
    // these are the real pipeline's output, not values anyone wrote down.
    //
    // Chosen so each medium has a distinct count and nothing is ambiguous:
    // organic-search 2, referral 1, direct 1.
    traffic: {
        sources: ['google.com', 'bing.com', 'news.ycombinator.com'],
        searchTerms: ['open web analytics', 'owa analytics'],
        refererUrl: 'https://news.ycombinator.com/item?id=e2e',
        refererHost: 'news.ycombinator.com',
        mediums: { 'organic-search': 2, referral: 1, direct: 1 },
    },
    // The goal + funnel seeded by seed_reporting_fixtures.php. Its step paths
    // are ones the pageview fixture already visits, so every stage of the
    // funnel has real visitors -- including `destination`, which the report
    // appends as the funnel's last bar from the goal's own goal_url.
    goal: {
        number: 1,
        name: 'E2E Signup Funnel',
        steps: [
            { name: 'E2E Step Home', path: '/' },
            { name: 'E2E Step Pricing', path: '/pricing' },
        ],
        destination: '/docs',
    },
    /*
     * The DOM recordings seeded by seedDomstreams().
     *
     * `a` is stored as THREE rows -- one per flush of the tracker's event queue
     * -- which is what a real recording is. Each row carries the cumulative
     * elapsed seconds at the moment it was flushed, so the recording's duration
     * is the LARGEST of them (95) and not their sum (147) or the first written
     * (12). Those three numbers are all different on purpose: a fixture where
     * they agree cannot tell a grouped-and-aggregated list from an ungrouped
     * one.
     *
     * The two recordings are attached to visits with different mediums, which
     * is what makes the segment filter testable: filtering to `a.medium` must
     * leave one recording, not two.
     */
    domstreams: {
        recordings: 2,
        a: {
            medium: 'organic-search',
            page: '/pricing',
            duration: 95,
            durationLabel: '0:01:35',
            segments: 3,
            bytes: 600,
            sizeLabel: '600 B',
        },
        b: {
            medium: 'referral',
            page: '/',
            duration: 8,
            durationLabel: '0:00:08',
            segments: 1,
            bytes: 90,
            sizeLabel: '90 B',
        },
    },
    // Notifications seeded for the header bell. Dismissing is permanent and
    // per user, so the specs must have their own rather than consume the real
    // release announcements.
    notifications: {
        source: 'e2e_fixture',
        // Newest first, matching published_at descending.
        titles: [
            'E2E Notification Five',
            'E2E Notification Four',
            'E2E Notification Three',
            'E2E Notification Two',
            'E2E Notification One',
        ],
        // One per mutating spec: reading and dismissing persist, so specs must
        // not share a subject.
        toRead: 'E2E Notification Five',
        toDismiss: 'E2E Notification Four',
        toDismissAndReload: 'E2E Notification Three',
        untouched: 'E2E Notification One',
    },
    // The always-present optional module the module-activation test toggles.
    toggleModule: 'hello',
};

/**
 * Log in through the real OWA login form as the given user and land
 * authenticated. Leaves the page on the post-login redirect (base.sites).
 *
 * FIELD NAMES ARE UN-NAMESPACED. Admin form fields are emitted through
 * Template::getNs(), which returns the 'app_ns' setting -- empty since the
 * namespace split, because OWA owns its own admin query string and has nothing
 * to collide with. The wire namespace ('ns', still 'owa_') did not move: it
 * names cookies and tracker params. If app_ns is ever given a value again,
 * every selector in this suite goes stale at once, so the field is checked
 * before it is filled and says so rather than timing out forty times.
 *
 * The ENTRY URL below deliberately keeps the legacy '?owa_do=' spelling. The
 * server accepts both, and this is the one place the suite proves that old
 * bookmarks and saved links still resolve -- every navigation after it follows
 * a link OWA rendered, which is un-namespaced.
 */
async function loginAs(page, userId, password) {
    await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });

    if (await page.locator('input[name="user_id"]').count() === 0) {
        const names = await page.evaluate(() =>
            [...document.querySelectorAll('form input[name]')].map((i) => i.name)
        );
        throw new Error(
            'The login form has no un-namespaced user_id field. The admin form '
            + 'namespace (base.app_ns) has probably moved; every selector in this '
            + 'suite assumes it is empty. Fields present: ' + JSON.stringify(names)
        );
    }

    await page.fill('input[name="user_id"]', userId);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('input[name="submit_btn"]'),
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
 * These are rendered by makeLink(..., true) with the &nonce=... already
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
        `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}&owa_period=${period}`,
        { waitUntil: 'networkidle' }
    );
    // The reporting bundle builds charts/grids/selects from AJAX result sets
    // after load; wait for the load-bearing widgets rather than a blind sleep.
    await page.waitForSelector('tr.jqgrow', { timeout: 20_000 });
}

/**
 * Navigate to a dimension report page and wait for the jQuery-UI tabs widget
 * to build.
 *
 * Reports are addressed by id -- owa_do=base.report&owa_reportId=<id> -- not by
 * a per-report action. Most of them no longer HAVE one: they are configuration
 * under modules/Base/reports/, rendered by Core\ConfiguredReport. Unlike the dashboard, these pages render
 * the tabbed report layout (owa.report.createTabs -> #report-tabs.ui-tabs).
 * Defaults to Browser Types -- a plain dimension report needing no extra params.
 */
async function openReport(page, { reportId = 'browsers', period = 'last_thirty_days' } = {}) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=${reportId}&owa_siteId=${FIXTURE.siteId}&owa_period=${period}`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('#report-tabs.ui-tabs', { timeout: 20_000 });
}

/**
 * Navigate to a report that renders WITHOUT tabs.
 *
 * The control that gets drawn as tabs is the METRIC SET picker, and it only
 * appears on a report that offers more than one way to measure its dimension.
 * A report declaring its own `metrics` is measured one way, so #report-tabs
 * never gains the .ui-tabs class and openReport() above would time out waiting
 * for a widget that is never built. That family is content-based (Pages,
 * Products, Transactions...) where a session tab would be meaningless.
 *
 * Waits for the grid instead, which is the load-bearing widget on those pages.
 */
async function openReportNoTabs(page, { reportId, period = 'last_thirty_days' } = {}) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=${reportId}&owa_siteId=${FIXTURE.siteId}&owa_period=${period}`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('.ui-jqgrid', { timeout: 20_000 });
}

/**
 * Navigate to ANY configured report, without assuming what it draws.
 *
 * The two helpers above each wait for a widget that only some reports have --
 * the metric-set control, or a grid. Neither can sweep the whole set: a report
 * with no grid, or one measured a single way, would time out on a page that
 * rendered perfectly.
 *
 * So this waits for the one element every configured report emits: a widget
 * cell. If that never appears the renderer produced nothing, which is the
 * failure worth reporting.
 */
async function openConfiguredReport(page, { reportId, period = 'last_thirty_days', params = {} } = {}) {

    /*
     * Every parameter the definition DECLARES, supplied.
     *
     * A report that enumerates a constraint is refused outright when the value
     * behind it is missing -- rendering a detail report's heading over
     * site-wide data is worse than an error. So a sweep that opens every report
     * has to arrive with each one's parameters, or it is opening the error view
     * and timing out waiting for a widget that will never be drawn.
     *
     * A sentinel is enough: this spec is watching for console errors while the
     * widgets build, not for rows. Matching nothing renders empty widgets,
     * which is exactly what these reports did before the guard existed.
     */
    const declared = declaredReportParams(reportId);
    const all = Object.assign({}, declared, params);

    const query = Object.keys(all)
        .map((k) => `&owa_${encodeURIComponent(k)}=${encodeURIComponent(all[k])}`)
        .join('');

    await page.goto(
        `?owa_do=base.report&owa_reportId=${reportId}&owa_siteId=${FIXTURE.siteId}&owa_period=${period}` + query,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('.owa_reportGridItem', { timeout: 20_000 });
}

/** The value a swept report is given for a parameter it declares. */
const REPORT_PARAM_SENTINEL = 'e2e_sentinel';

/**
 * The parameters a report definition declares, each set to the sentinel.
 *
 * Read from the definition rather than listed, for the same reason the ids are:
 * a report that gains a parameter tomorrow is swept correctly without anyone
 * remembering this file exists.
 *
 * @returns {Object<string,string>}
 */
function declaredReportParams(reportId) {
    const fs = require('fs');
    const path = require('path');

    const modules = path.join(__dirname, '..', '..', 'modules');
    const out = {};

    for (const mod of fs.readdirSync(modules)) {
        const file = path.join(modules, mod, 'reports', `${reportId}.json`);

        if (!fs.existsSync(file)) {
            continue;
        }

        let def;
        try {
            def = JSON.parse(fs.readFileSync(file, 'utf8'));
        } catch (e) {
            return out;
        }

        for (const name of Object.keys((def && def.params) || {})) {
            out[name] = REPORT_PARAM_SENTINEL;
        }
    }

    return out;
}

/**
 * Every report that is configuration, read from disk.
 *
 * Enumerated rather than listed so a report added tomorrow is swept without
 * anyone remembering to add it here -- which is the failure mode a hand-written
 * list has.
 */
function configuredReportIds() {
    const fs = require('fs');
    const path = require('path');

    const modules = path.join(__dirname, '..', '..', 'modules');
    const ids = [];

    for (const mod of fs.readdirSync(modules)) {
        const dir = path.join(modules, mod, 'reports');
        if (!fs.existsSync(dir)) {
            continue;
        }
        for (const file of fs.readdirSync(dir)) {
            if (file.endsWith('.json')) {
                ids.push(file.slice(0, -'.json'.length));
            }
        }
    }

    return ids.sort();
}

module.exports = {
    FIXTURE,
    login,
    loginAs,
    adminLogin,
    logout,
    clickAndWait,
    openReportNoTabs,
    openDashboard,
    openReport,
    openConfiguredReport,
    configuredReportIds,
    declaredReportParams,
    REPORT_PARAM_SENTINEL,
};
