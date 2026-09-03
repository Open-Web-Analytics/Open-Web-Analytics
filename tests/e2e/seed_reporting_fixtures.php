<?php
/**
 * Deterministic fixture seeder for the reporting-UI end-to-end (Playwright)
 * tests.
 *
 * Creates, idempotently:
 *   - a dedicated tracked site (domain https://owa-e2e.example.test),
 *   - a dedicated reporting test user with a KNOWN password (so the headless
 *     browser can log in via the normal login form), assigned the analyst role
 *     so it can view reports,
 *   - a small, fixed set of pageview events for that site so a report page
 *     actually renders a chart + result-set grid.
 *
 * Usage (from repo root):
 *   php tests/e2e/seed_reporting_fixtures.php seed       # create fixtures, prints JSON
 *   php tests/e2e/seed_reporting_fixtures.php teardown    # remove fixtures
 *   php tests/e2e/seed_reporting_fixtures.php info        # print fixture identifiers as JSON
 *
 * All identifiers are constant (below), so the browser test config can rely on
 * them without parsing anything. Credentials here are for a throwaway LOCAL
 * fixture user on the test schema -- never a production account.
 *
 * The pageview events are fired through the SAME ingestion pipeline the tracker
 * beacon uses (owa_coreAPI::logEvent), mirroring tests/IngestionTestCase.php, so
 * the seeded data is realistic and lands in the real fact/dimension tables.
 */

// ---- Fixture identifiers (stable contract with the Playwright config) --------
const E2E_SITE_DOMAIN = 'https://owa-e2e.example.test';
/*
 * Pinned, not derived. site_id used to be md5( domain ), so the seeder and the
 * specs could each compute it independently and agree. Identifiers are minted
 * now, so the fixture states the one it wants and passes it at creation --
 * fixtureInfo() then hands the same value to the specs.
 */
const E2E_SITE_ID = 'OWA-e2e-reporting-fixture';
const E2E_SITE_NAME   = 'OWA E2E Reporting Fixture';
const E2E_USER_ID     = 'owa-e2e-reporter@example.test';
const E2E_USER_PASS   = 'e2e-Reporter-Pass-1!';   // local throwaway fixture creds
const E2E_USER_ROLE   = 'analyst';                // has view_reports + view_site_list
const E2E_USER_NAME   = 'OWA E2E Reporter';
const E2E_PAGEVIEWS   = 11;                        // number of synthetic pageviews

/*
 * The referring URLs the four seeded visits arrive from.
 *
 * session_referer is the ONE input the attribution chain derives from --
 * deriveMedium, deriveSource and extractSearchTerm all read it, and the
 * referer/search-term dimension ids are minted off those. Two search engines
 * and one ordinary referral give organic-search 2, referral 1, direct 1.
 *
 * Held here rather than only inside $visits so teardown can delete exactly the
 * referer rows this fixture created.
 */
const E2E_REFERERS = [
    'https://www.google.com/search?q=open+web+analytics',
    'https://news.ycombinator.com/item?id=e2e',
    'https://www.bing.com/search?q=owa+analytics',
];

// E-commerce fixture. enableEcommerceReporting is a PER-SITE setting, so the
// seeder turns it on for the fixture site -- the global base setting of the same
// name has been false since it was introduced and is not what any report reads.
// Two transactions with three line items between them: enough for the commerce
// reports to have rows, and for productName to appear more than once so a
// grouped dimension is not trivially one row per item.
const E2E_ECOMMERCE   = true;
const E2E_TXNS = [
    ['order_id' => 'e2e-order-1001', 'day_ago' => 9, 'revenue' => 42.60, 'tax' => 3.40, 'shipping' => 8.95, 'items' => [
        ['sku' => 'E2E-SKU-1', 'name' => 'E2E Widget',  'category' => 'Widgets', 'price' => 10.20, 'qty' => 2],
        ['sku' => 'E2E-SKU-2', 'name' => 'E2E Gadget',  'category' => 'Gadgets', 'price' => 5.50,  'qty' => 1],
    ]],
    ['order_id' => 'e2e-order-1002', 'day_ago' => 2, 'revenue' => 20.40, 'tax' => 1.60, 'shipping' => 4.00, 'items' => [
        ['sku' => 'E2E-SKU-1', 'name' => 'E2E Widget',  'category' => 'Widgets', 'price' => 10.20, 'qty' => 2],
    ]],
];

// Goal + funnel fixture. Goals are a PER-SITE setting, and until this existed no
// install on the box had a single funnel step -- so nothing exercised
// ReportGoalFunnel's step loop, and a rename that broke its final bar shipped
// unnoticed.
//
// The paths are ones the pageview fixture already visits, so every stage has
// real visitors: '/' and '/pricing' appear in two pageviews each, and '/docs'
// -- the goal's own destination, which the report appends as the last bar -- in
// two more. A funnel whose stages are all zero would render and prove nothing.
// Notifications the bell can show and the specs can dismiss.
//
// Seeded rather than leaning on the real GitHub releases, because dismissing is
// PERMANENT and per user: specs that dismissed real notifications would pass
// once and then find nothing left to dismiss. These are removed at teardown,
// dismissals and all.
const E2E_NOTIFICATION_SOURCE = 'e2e_fixture';
//
// One per MUTATING spec, plus spares. Reading and dismissing both persist, so
// two specs sharing a row means whichever runs second finds it already acted
// on -- and the failure looks like a bug in the feature rather than in the
// fixtures.
const E2E_NOTIFICATIONS = [
    ['source_key' => 'e2e-n1', 'title' => 'E2E Notification One',   'body' => 'first',  'url' => 'https://example.test/n1'],
    ['source_key' => 'e2e-n2', 'title' => 'E2E Notification Two',   'body' => 'second', 'url' => 'https://example.test/n2'],
    ['source_key' => 'e2e-n3', 'title' => 'E2E Notification Three', 'body' => 'third',  'url' => 'https://example.test/n3'],
    ['source_key' => 'e2e-n4', 'title' => 'E2E Notification Four',  'body' => 'fourth', 'url' => 'https://example.test/n4'],
    ['source_key' => 'e2e-n5', 'title' => 'E2E Notification Five',  'body' => 'fifth',  'url' => 'https://example.test/n5'],
];

const E2E_GOAL_NUMBER = 1;
const E2E_GOAL_NAME   = 'E2E Signup Funnel';
const E2E_GOAL_GROUP  = '1';
const E2E_GOAL_URL    = '/docs';

// The funnel VISUALIZATION.
//
// A funnel used to be configuration -- rows hanging off a goal -- and the goal
// funnel report read them. It is a visualization now: a custom report of type
// `visualization`, whose definition holds its own steps. So it is seeded the
// way a custom report is, not the way a goal is, and nothing about it belongs
// to the goal event above.
//
// The third step is the one the old report APPENDED from the goal's own
// goal_url. It is an ordinary step here, which is the whole difference: the
// path being analysed is stated where it is looked at, so there is no longer a
// stage the report builds rather than reads.
const E2E_FUNNEL_VIZ_NAME  = 'E2E Signup Funnel Visualization';
const E2E_FUNNEL_VIZ_STEPS = [
    ['name' => 'E2E Step Home',    'path' => '/'],
    ['name' => 'E2E Step Pricing', 'path' => '/pricing'],
    ['name' => 'E2E Step Docs',    'path' => '/docs'],
];

// A second fixture user with the ADMIN role, so the admin-actions e2e suite
// (tests/e2e/admin-actions.spec.js) can drive the write flows that need
// edit_users / edit_sites / edit_settings / edit_modules capabilities. The
// analyst user above cannot reach any of those. Throwaway LOCAL creds only.
/*
 * A custom report belonging to SOMEBODY ELSE.
 *
 * Its owner is a user id that never signs in, which is the whole point: editing
 * somebody else's report is governed by a capability, and a test that builds a
 * report and then opens it is always looking at its own, where ownership alone
 * is enough. That blind spot hid a real bug -- the capability was asked before
 * the request had authenticated, so it was always false, and an admin opening
 * another author's report got no edit control.
 */
const E2E_OTHERS_REPORT_OWNER = 'owa-e2e-someone-else@example.test';
const E2E_OTHERS_REPORT_NAME  = 'E2E Owned By Someone Else';

/*
 * A custom report whose trend is BROKEN OUT by a dimension.
 *
 * No shipped report has one: Content's trend became a card, and a card cannot
 * be broken out. The feature is still reachable -- any author can build one --
 * so it is covered here rather than by giving a shipped report a shape nobody
 * asked it to have.
 */
const E2E_BREAKDOWN_REPORT_NAME = 'E2E Broken Out Trend';

const E2E_ADMIN_ID    = 'owa-e2e-admin@example.test';
const E2E_ADMIN_PASS  = 'e2e-Admin-Pass-1!';       // local throwaway fixture creds
const E2E_ADMIN_ROLE  = 'admin';                   // full edit_* capabilities
const E2E_ADMIN_NAME  = 'OWA E2E Admin';

// A third fixture user dedicated to the password-CHANGE flow. OWA has no
// logged-in "change my password" form and no admin "set arbitrary password"
// screen: a password change is only reachable by authenticating a one-time
// temp_passkey (normally emailed via base.passwordResetForm). A headless
// browser can't read that email, so the seeder plants a KNOWN passkey on this
// user directly -- exactly the value usersResetPassword.php would have written
// -- letting the admin-actions suite drive the REAL base.usersPasswordEntry ->
// base.usersChangePassword form the same way a user clicking the emailed link
// would. Isolated to its own user so the change (which rotates passkey +
// password) can't disturb the admin/reporter logins. The seeder resets this
// user's passkey + starting password on every run, so the test is re-runnable.
const E2E_PWUSER_ID   = 'owa-e2e-pwchange@example.test';
const E2E_PWUSER_PASS = 'e2e-PwChange-Old-1!';     // known STARTING password
const E2E_PWUSER_KEY  = '735512bd84ae1f2635e3e89fb7ecc001'; // md5 temp_passkey the test submits
const E2E_PWUSER_ROLE = 'analyst';
const E2E_PWUSER_NAME = 'OWA E2E PwChange';

// Identifiers the admin-actions CRUD tests CREATE at runtime (add site / add
// user) and then delete. They are NOT seeded here; they are declared so the
// teardown can mop them up if a CRUD test dies between the add and its delete,
// keeping the live install clean. Kept in lockstep with tests/e2e/fixtures.js.
const E2E_NEW_SITE_DOMAIN = 'https://owa-e2e-created.example.test';
const E2E_NEW_USER_ID     = 'owa-e2e-created@example.test';

// ---- Boot OWA in logger role (same as log.php / the ingestion tests) ---------
$owa_root = dirname(__DIR__, 2) . '/';

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    // Non-robotic UA or logEvent() drops the beacon when log_robots is false.
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

// If the live install has new-session announcements enabled (announce_visitors +
// notice_email), seeding a fixture pageview fires base.new_session and its notify
// handler sends mail. We deliberately DON'T suppress that: announcements are off
// by default, so if they're on it's an operator's choice and the seed should
// behave like any other tracked hit -- including on a localhost install with the
// shipped 'owa@localhost' mailer-from. owa_mailer now guards an invalid From and
// never lets a mail failure fatal the request (modules/base/classes/mailer.php),
// so no override is needed here; the seed exercises that real path.

$cmd = $argv[1] ?? 'info';

switch ($cmd) {
    case 'seed':     echo json_encode(seed(), JSON_PRETTY_PRINT) . "\n";     break;
    case 'teardown': echo json_encode(teardown(), JSON_PRETTY_PRINT) . "\n"; break;
    case 'info':     echo json_encode(fixtureInfo(), JSON_PRETTY_PRINT) . "\n"; break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: seed | teardown | info\n");
        exit(2);
}

// -----------------------------------------------------------------------------

function fixtureInfo(): array
{
    return [
        'site_domain'    => E2E_SITE_DOMAIN,
        'site_id'        => E2E_SITE_ID,
        'user_id'        => E2E_USER_ID,
        'password'       => E2E_USER_PASS,
        'role'           => E2E_USER_ROLE,
        'admin_user_id'  => E2E_ADMIN_ID,
        'admin_password' => E2E_ADMIN_PASS,
        'admin_role'     => E2E_ADMIN_ROLE,
        'pw_user_id'     => E2E_PWUSER_ID,
        'pw_password'    => E2E_PWUSER_PASS,
        'pw_passkey'     => E2E_PWUSER_KEY,
        'ecommerce'      => E2E_ECOMMERCE,
        'order_ids'      => array_column(E2E_TXNS, 'order_id'),
        'product_names'  => ['E2E Widget', 'E2E Gadget'],
    ];
}

function seed(): array
{
    $out = fixtureInfo();

    // 1. Site. createNewSite() is still idempotent -- it now recognises the
    //    domain by lookup rather than by deriving its identifier -- and the
    //    identifier is pinned so the specs can reference it without a query.
    $sm = owa_coreAPI::supportClassFactory('base', 'siteManager');
    $sm->createNewSite(E2E_SITE_DOMAIN, E2E_SITE_NAME, '', '', E2E_SITE_ID);

    // 2. User with a known password (idempotent: skip if already present).
    $u = owa_coreAPI::entityFactory('base.user');
    $u->load(E2E_USER_ID, 'user_id');
    if (!$u->get('id')) {
        $u = owa_coreAPI::entityFactory('base.user');
        $u->createNewUser(E2E_USER_ID, E2E_USER_ROLE, E2E_USER_PASS, E2E_USER_ID, E2E_USER_NAME);
        // reload so we have the persisted internal id for the site grant below.
        $u = owa_coreAPI::entityFactory('base.user');
        $u->load(E2E_USER_ID, 'user_id');
    }

    // 2a. Admin user with a known password (idempotent). No site grant needed:
    //     the admin role's edit_* capabilities are not in
    //     capabilitiesThatRequireSiteAccess, so the admin-actions suite can log
    //     in and reach every options/users/sites/modules screen without one.
    $a = owa_coreAPI::entityFactory('base.user');
    $a->load(E2E_ADMIN_ID, 'user_id');
    if (!$a->get('id')) {
        $a = owa_coreAPI::entityFactory('base.user');
        $a->createNewUser(E2E_ADMIN_ID, E2E_ADMIN_ROLE, E2E_ADMIN_PASS, E2E_ADMIN_ID, E2E_ADMIN_NAME);
    }

    // 2b. Grant the user access to the fixture site. The analyst role's
    //     view_reports capability is in capabilitiesThatRequireSiteAccess, so
    //     without a base.site_user relation the login succeeds but every report
    //     page is denied. The relation is keyed by INTERNAL ids (not the md5
    //     site_id / user_id string), mirroring siteAddAllowedUserRestController.
    $s = owa_coreAPI::entityFactory('base.site');
    $s->load(E2E_SITE_ID, 'site_id');
    if ($u->get('id') && $s->get('id') && !siteUserRelationExists($u->get('id'), $s->get('id'))) {
        $rel = owa_coreAPI::entityFactory('base.site_user');
        $rel->set('user_id', $u->get('id'));
        $rel->set('site_id', $s->get('id'));
        $rel->save();
    }

    // 2c. Password-change fixture user. Created if absent, then ALWAYS reset to
    //     the known starting password + known temp_passkey so the password test
    //     is re-runnable (a prior run's successful change rotates both). The
    //     passkey is what the browser submits as owa_k to the real change form.
    $pw = owa_coreAPI::entityFactory('base.user');
    $pw->load(E2E_PWUSER_ID, 'user_id');
    if (!$pw->get('id')) {
        $pw = owa_coreAPI::entityFactory('base.user');
        $pw->createNewUser(E2E_PWUSER_ID, E2E_PWUSER_ROLE, E2E_PWUSER_PASS, E2E_PWUSER_ID, E2E_PWUSER_NAME);
        $pw = owa_coreAPI::entityFactory('base.user');
        $pw->load(E2E_PWUSER_ID, 'user_id');
    }
    if ($pw->get('id')) {
        $pw->set('password', owa_lib::encryptPassword(E2E_PWUSER_PASS));
        $pw->set('temp_passkey', E2E_PWUSER_KEY);
        $pw->update();
    }

    /*
     * 3. Pageview data so a report renders a chart + grid. Only top up to
     *    E2E_PAGEVIEWS, so re-running doesn't pile up rows.
     *
     *    NOTE THE TRAP: this tops up, it never REWRITES. Once the site has its
     *    full complement, seeding is a no-op and every existing row survives
     *    verbatim -- including rows whose shape came from a since-fixed
     *    derivation. That is not hypothetical: after is_repeat_visitor's NULL
     *    bug was fixed, re-seeding left the two pre-fix NULL session rows in
     *    place, and the fixture looked like it was still producing them. It was
     *    not producing anything at all.
     *
     *    So: after changing anything that derives a stored property, run
     *    `teardown` before `seed`. A seed alone proves nothing about the new
     *    code. 'pageviews_seeded' => 0 in the output is the tell.
     *
     *    A PARTIAL top-up is worse than either: seedPageviews() always walks
     *    $visits from the start and stops at $n, so topping up 2 of 8 replays
     *    visit 0 with a fresh session id and leaves the fixture with visits the
     *    specs don't expect. Hence the flag below -- an incomplete count means
     *    a previous run died midway, and the fixture should be torn down, not
     *    patched up.
     */
    $existing = countSiteRequests(E2E_SITE_ID);
    $seeded = 0;
    if ($existing < E2E_PAGEVIEWS) {
        $seeded = seedPageviews(E2E_PAGEVIEWS - $existing);
    }

    $out['pageviews_seeded']  = $seeded;
    $out['pageviews_total']   = countSiteRequests(E2E_SITE_ID);

    // Loud enough to notice when a "seed" changed nothing, or resumed a partial
    // one. Both mean the rows on disk are not what the current code writes.
    if ($existing > 0) {
        $out['pageviews_note'] = $existing >= E2E_PAGEVIEWS
            ? 'kept ' . $existing . ' existing pageview(s); nothing rewritten -- run teardown first '
              . 'if you changed a derived property'
            : 'resumed a PARTIAL fixture (' . $existing . ' existing); tear down and re-seed for a '
              . 'deterministic set';
    }
    // 4. E-commerce. The setting is per-site; enabling it is what makes the
    //    e-commerce tab appear on the session-based reports and lets the
    //    commerce reports return rows.
    owa_coreAPI::persistSiteSetting(E2E_SITE_ID, 'enableEcommerceReporting', true);
    $out['ecommerce_enabled']   = (bool) owa_coreAPI::getSiteSetting(E2E_SITE_ID, 'enableEcommerceReporting');
    $out['transactions_seeded'] = seedTransactions();

    // 5. Notifications for the header bell.
    $out['notifications_seeded'] = seedNotifications();

    // 6. A goal with a funnel, so the funnel report has stages to draw and the
    //    goal metric set has a group to appear as.
    $out['goal_seeded'] = seedGoal();

    // 7. DOM recordings, so the domstreams report has recordings to list --
    //    including one stored as several chunks, which is the case its
    //    aggregates exist for.
    $out['domstreams_seeded'] = seedDomstreams();

    // 8. A custom report owned by a user who never signs in, so a test can open
    //    somebody ELSE'S report. See E2E_OTHERS_REPORT_OWNER.
    $out['others_report_seeded'] = seedOthersReport();

    // 9. A custom report with a broken-out trend, for the companion-grid specs.
    $out['breakdown_report_seeded'] = seedBreakdownReport();

    // 10. The funnel visualization. Separate from the goal above and naming
    //     nothing about it -- a funnel is an analysis of a path now, not part
    //     of a goal's configuration.
    $out['funnel_visualization_seeded'] = seedFunnelVisualization();

    $out['status']            = 'seeded';
    return $out;
}

/**
 * One stored custom report, owned by a user nobody logs in as.
 *
 * Written through CustomReports::save() rather than as a raw row, so it is
 * validated the way a real one is and cannot drift from what the builder
 * produces.
 *
 * Idempotent by NAME: re-seeding reuses the existing row rather than piling up
 * a copy per run.
 */
function seedOthersReport(): array
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_custom_report');
    $db->selectColumn('*');

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['name'] ?? '') === E2E_OTHERS_REPORT_NAME) {

            return ['id' => $row['id'], 'owner' => $row['user_id'], 'reused' => true];
        }
    }

    $result = \OWA\Module\Base\Classes\CustomReports::save([
        'name'       => E2E_OTHERS_REPORT_NAME,
        'definition' => [
            'title'   => E2E_OTHERS_REPORT_NAME,
            'widgets' => [[
                'type'      => 'grid',
                'id'        => 'pages',
                'container' => 'pages',
                'title'     => 'Pages',
                'query'     => [
                    'metrics'    => 'pageViews',
                    'dimensions' => 'pagePath',
                    'sort'       => 'pageViews-',
                ],
            ]],
        ],
    ], E2E_OTHERS_REPORT_OWNER);

    return ['id' => $result['id'] ?? '', 'owner' => E2E_OTHERS_REPORT_OWNER,
            'error' => $result['error'] ?? ''];
}

/**
 * One stored custom report whose trend is broken out by page path.
 *
 * Owned by the admin fixture user, so the specs that drive it can also edit it
 * if they need to. Idempotent by name, like the one above.
 */
function seedBreakdownReport(): array
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_custom_report');
    $db->selectColumn('*');

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['name'] ?? '') === E2E_BREAKDOWN_REPORT_NAME) {

            return ['id' => $row['id'], 'reused' => true];
        }
    }

    $result = \OWA\Module\Base\Classes\CustomReports::save([
        'name'       => E2E_BREAKDOWN_REPORT_NAME,
        'definition' => [
            'title'   => E2E_BREAKDOWN_REPORT_NAME,
            'widgets' => [[
                'type'        => 'trend',
                'id'          => 'trend',
                'container'   => 'trend-chart',
                'chartMetric' => 'pageViews',
                'query'       => [
                    // date first is the axis, pagePath second is the breakdown:
                    // a line per page over the filled total, and the grid of
                    // those pages underneath.
                    'metrics'    => 'visits,pageViews',
                    'dimensions' => 'date,pagePath',
                    'sort'       => 'date',
                ],
            ]],
        ],
    ], E2E_ADMIN_ID);

    return ['id' => $result['id'] ?? '', 'error' => $result['error'] ?? ''];
}

/** Remove the broken-out-trend fixture, by name. */
function unseedBreakdownReport(): int
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_custom_report');
    $db->selectColumn('*');

    $removed = 0;

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['name'] ?? '') === E2E_BREAKDOWN_REPORT_NAME) {

            \OWA\Module\Base\Classes\CustomReports::delete($row['id']);
            $removed++;
        }
    }

    return $removed;
}

/** Remove the somebody-else's-report fixture, by name. */
function unseedOthersReport(): int
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_custom_report');
    $db->selectColumn('*');

    $removed = 0;

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['name'] ?? '') === E2E_OTHERS_REPORT_NAME) {

            \OWA\Module\Base\Classes\CustomReports::delete($row['id']);
            $removed++;
        }
    }

    return $removed;
}

/**
 * DOM recordings for the domstreams report.
 *
 * WHY THE FIRST ONE IS THREE ROWS
 *
 * Because that is what a real recording is. The tracker flushes its event queue
 * on a timer, so one recording is stored as however many rows it took, all
 * sharing a domstream_guid, and each carrying the CUMULATIVE elapsed seconds at
 * the moment it was flushed. A fixture of one row per recording would report
 * the same numbers whether the report grouped and aggregated or not.
 *
 * The three chunks carry 12, 40 and 95 seconds and are written out of order, so
 * "the last one" and "the largest" are different answers and neither is "the
 * first row". Together they hold 600 bytes of events.
 *
 * WHY EACH IS ON A DIFFERENT VISIT
 *
 * The segment filter selects VISITS, so the two recordings have to belong to
 * visits that differ in something the filter can name. They are attached to
 * sessions with different mediums, and both mediums are reported back in the
 * fixture info -- a spec that hardcoded "organic-search" would be asserting
 * against the referer list rather than against what was seeded.
 *
 * @return array what was seeded, for the fixture info
 */
function seedDomstreams(): array
{
    $site_id = E2E_SITE_ID;
    $db      = owa_coreAPI::dbSingleton();
    $db->connect();

    /*
     * The visits the two recordings are attached to, chosen BY MEDIUM rather
     * than by whatever the optimiser returns first. The medium is what the
     * fixture then promises the specs, so it has to be the thing selected on --
     * picking a session and reading its medium back would make the fixture
     * describe the query plan.
     *
     * Both mediums are derived by the attribution chain from the referring URLs
     * in E2E_REFERERS, so they are the real pipeline's output.
     */
    $recordings = [
        ['medium' => 'organic-search', 'page' => '/pricing', 'chunks' => [12 => 100, 95 => 350, 40 => 150]],
        ['medium' => 'referral',       'page' => '/',        'chunks' => [8  => 90]],
    ];

    $seeded = 0;
    $out    = [];

    foreach ($recordings as $i => $recording) {

        $found = $db->get_results(
            "SELECT id, visitor_id, medium, yyyymmdd FROM owa_session"
            . " WHERE site_id = '" . $db->prepare($site_id) . "'"
            . " AND medium = '" . $db->prepare($recording['medium']) . "' LIMIT 1"
        );

        if (!is_array($found) || !$found) {
            $out[] = ['medium' => $recording['medium'], 'skipped' => 'no visit with this medium'];
            continue;
        }

        $session = (array) $found[0];
        $guid    = numericGuid();

        // Idempotent the way the rest of the seeder is: a recording already
        // present for this page and visit is left alone rather than doubled.
        $existing = $db->get_results(
            "SELECT domstream_guid FROM owa_domstream"
            . " WHERE site_id = '" . $db->prepare($site_id) . "'"
            . " AND session_id = " . (int) $session['id']
            . " AND page_url = '" . $db->prepare(E2E_SITE_DOMAIN . $recording['page']) . "' LIMIT 1"
        );

        if (is_array($existing) && $existing) {
            $out[] = [
                'medium'   => $session['medium'],
                'page'     => $recording['page'],
                'duration' => max(array_keys($recording['chunks'])),
                'segments' => count($recording['chunks']),
            ];
            continue;
        }

        // Midday on the visit's own day, matching seedPageviews() so the
        // recording lands inside the same reporting window as everything else.
        $ts = mktime(12, 0, 0,
            (int) substr((string) $session['yyyymmdd'], 4, 2),
            (int) substr((string) $session['yyyymmdd'], 6, 2),
            (int) substr((string) $session['yyyymmdd'], 0, 4));

        $offset = 0;

        foreach ($recording['chunks'] as $duration => $bytes) {

            $ds = owa_coreAPI::entityFactory('base.domstream');

            $ds->set('id', numericGuid());
            $ds->set('site_id', $site_id);
            $ds->set('domstream_guid', $guid);
            $ds->set('session_id', $session['id']);
            $ds->set('visitor_id', $session['visitor_id']);
            $ds->set('page_url', E2E_SITE_DOMAIN . $recording['page']);
            $ds->set('page_width', 1280);
            $ds->set('page_height', 800);
            $ds->set('duration', $duration);
            $ds->set('events', str_repeat('e', $bytes));
            $ds->set('timestamp', $ts + $offset);
            $ds->set('yyyymmdd', (int) $session['yyyymmdd']);
            $ds->set('year', (int) substr((string) $session['yyyymmdd'], 0, 4));
            $ds->set('month', (int) substr((string) $session['yyyymmdd'], 4, 2));
            $ds->set('day', (int) substr((string) $session['yyyymmdd'], 6, 2));
            $ds->create();

            $offset += 30;
            $seeded++;
        }

        $out[] = [
            'medium'   => $session['medium'],
            'page'     => $recording['page'],
            'duration' => max(array_keys($recording['chunks'])),
            'segments' => count($recording['chunks']),
            'bytes'    => array_sum($recording['chunks']),
        ];
    }

    return ['seeded' => $seeded, 'recordings' => $out];
}

/**
 * One active url_destination goal with a two-step funnel.
 *
 * Shaped the way GoalManager reads it: keyed by goal number, `goal_status`
 * active so it reaches activeGoals, and `goal_group` set so the group becomes a
 * metric set. Steps carry `path` -- the key every consumer reads since the
 * rename -- and `is_required` as a real boolean, because the funnel report
 * tests it with ===.
 */
/**
 * Global notifications with a fixture source of their own.
 *
 * Written straight through NotificationManager so the seeder exercises the same
 * path the fetch job does -- including the watermark, which is why they carry
 * ascending timestamps rather than all sharing one.
 */
function seedNotifications(): array
{
    $items = [];
    $i     = 0;

    foreach (E2E_NOTIFICATIONS as $n) {
        $items[] = $n + ['published_at' => time() - (86400 * (count(E2E_NOTIFICATIONS) - $i))];
        $i++;
    }

    // The watermark refuses anything older than what a source already holds, so
    // a re-seed after a partial teardown would silently store nothing. Clear
    // first and the seeder is idempotent.
    unseedNotifications();

    /*
     * One at a time, oldest first.
     *
     * record() caps a source's FIRST write at INITIAL_LIMIT and then refuses
     * anything older than what it already holds -- correct for a feed, and it
     * would silently give the specs 3 of these 5. Adding them one by one, each
     * newer than the last, means every one clears the watermark and the seeder
     * still goes through exactly the path the fetch job uses.
     */
    $created = 0;

    foreach ($items as $item) {
        $created += \OWA\Module\Base\Classes\NotificationManager::record(
            [$item], E2E_NOTIFICATION_SOURCE, '',
            \OWA\Module\Base\Classes\NotificationManager::TYPE_RELEASE);
    }

    return ['created' => $created, 'source' => E2E_NOTIFICATION_SOURCE];
}

/** Remove the fixture notifications and every per-user state row pointing at them. */
function unseedNotifications(): int
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_notification');
    $db->selectColumn('*');

    $removed = 0;

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['source'] ?? '') !== E2E_NOTIFICATION_SOURCE) {
            continue;
        }

        owa_coreAPI::entityFactory('base.notification')->delete($row['id']);

        $d = owa_coreAPI::dbSingleton();
        $d->deleteFrom('owa_notification_state');
        $d->where('notification_id', $row['id']);
        $d->executeQuery();

        $removed++;
    }

    return $removed;
}

/**
 * The funnel VISUALIZATION, and the id the specs address it by.
 *
 * Written through the entity rather than CustomReports::save(), because save()
 * validates a REPORT: it requires widgets whose metrics and dimensions resolve
 * through the registry, and a funnel has neither. A visualization's definition
 * is its steps, and the controller that computes it is chosen by
 * visualization_type -- so those two columns, and not the widget list, are what
 * has to be right here.
 *
 * The step paths are ones the pageview fixture already walks, in order, so
 * every stage counts somebody. A funnel whose stages are all zero would render
 * and prove nothing about the counting.
 *
 * @return array  the seeded row's id and its steps, for the e2e fixture file
 */
function seedFunnelVisualization(): array
{
    $steps = [];

    foreach (E2E_FUNNEL_VIZ_STEPS as $i => $step) {
        $steps[] = $step + ['step_number' => $i + 1];
    }

    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_custom_report');
    $db->selectColumn('*');

    $id = '';

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['name'] ?? '') === E2E_FUNNEL_VIZ_NAME) {

            $id = (string) $row['id'];
            break;
        }
    }

    $report = owa_coreAPI::entityFactory('base.custom_report');

    if ($id !== '') {
        $report->load($id);
    }

    $report->set('name', E2E_FUNNEL_VIZ_NAME);
    $report->set('report_type', \OWA\Module\Base\Entity\CustomReport::TYPE_VISUALIZATION);
    $report->set('visualization_type', 'funnel');
    // ENCODED: the column is a blob holding JSON, and handing it an array
    // stores the string "Array" -- which renders as a funnel with no steps.
    $report->set('definition', json_encode(['steps' => $steps]));
    $report->set('last_updated_timestamp', owa_coreAPI::getRequestTimestamp());

    if ($report->wasPersisted()) {

        $report->update();

    } else {

        $id = $report->generateId('visualization:e2e:' . E2E_SITE_ID);

        $report->set('id', $id);
        /*
         * Owned by the ANALYST, not the admin.
         *
         * Ownership decides what the roster LISTS -- an admin sees everyone's,
         * everyone else sees their own -- and the reporting specs sign in as
         * the analyst. Seeded under the admin it existed, opened fine by its
         * URL, and was invisible on the roster the specs look it up on, which
         * reads as "the funnel is missing" rather than "you cannot see it".
         *
         * It also makes the fixture the ordinary case: somebody's own
         * visualization, not an administrator's.
         */
        $report->set('user_id', E2E_USER_ID);
        $report->set('creation_timestamp', owa_coreAPI::getRequestTimestamp());
        $report->create();
    }

    /*
     * Read BACK, not returned from what was written.
     *
     * The definition survives a JSON round trip through a blob column, and the
     * specs address the row by an id this function minted. Reporting what the
     * database now holds is the only way the seed output can say the steps are
     * really there -- the "Array" bug above wrote successfully and stored
     * nothing readable.
     */
    $stored = \OWA\Module\Base\Classes\CustomReports::load($id);
    $back   = isset($stored['definition']['steps']) ? (array) $stored['definition']['steps'] : [];

    return [
        'id'    => $id,
        'name'  => E2E_FUNNEL_VIZ_NAME,
        'steps' => count($back),
        'paths' => array_values(array_map(static fn($s) => $s['path'] ?? null, $back)),
    ];
}

/** Remove the funnel visualization fixture, by name. */
function unseedFunnelVisualization(): int
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_custom_report');
    $db->selectColumn('*');

    $removed = 0;

    foreach ((array) $db->getAllRows() as $row) {

        if (($row['name'] ?? '') === E2E_FUNNEL_VIZ_NAME) {

            \OWA\Module\Base\Classes\CustomReports::delete($row['id']);
            $removed++;
        }
    }

    return $removed;
}

function seedGoal(): array
{
    /*
     * Through GoalManager, not persistSiteSetting.
     *
     * Goals are rows in owa_goal_event now. persistSiteSetting still WRITES a
     * settings blob perfectly happily -- nothing reads it any more, so seeding
     * that way succeeded and produced a site with no goals, which is how
     * thirteen reporting specs came to fail at once.
     *
     * No steps: a funnel is not part of a goal any more. See
     * seedFunnelVisualization(), which seeds the path separately and does not
     * name this goal at all.
     */
    $gm = owa_coreAPI::supportClassFactory('base', 'goalManager', E2E_SITE_ID);

    $gm->saveGoal(E2E_GOAL_NUMBER, [
        'goal_name'   => E2E_GOAL_NAME,
        'goal_status' => 'active',
        'goal_group'  => E2E_GOAL_GROUP,
        'goal_type'   => 'url_destination',
        'details'     => [
            'match_type' => 'exact',
            'goal_url'   => E2E_GOAL_URL,
        ],
    ]);

    // The write happens on destruct, as the blob write used to.
    unset($gm);

    // Read back, so the seed output reports what the database holds rather than
    // what was handed to it.
    $stored = \OWA\Module\Base\Classes\GoalManager::loadGoalEventsAsGoals(E2E_SITE_ID);
    $goal   = $stored[E2E_GOAL_NUMBER] ?? [];

    return [
        'goal_number' => E2E_GOAL_NUMBER,
        'goal_name'   => $goal['goal_name'] ?? '',
        'goal_status' => $goal['goal_status'] ?? '',
        'goal_url'    => $goal['details']['goal_url'] ?? '',
    ];
}

function teardown(): array
{
    $site_id = E2E_SITE_ID;

    // Remove fact/session/request rows for the fixture site. Dimension rows are
    // content-hashed and shared, so (as in the ingestion tests) we leave them.
    // site_id is an md5 hex string (no escaping needed), but use the query
    // builder's parameterized where() rather than string interpolation anyway.
    $removed = [];
    foreach (['owa_request', 'owa_session', 'owa_action_fact', 'owa_domstream',
              'owa_commerce_transaction_fact', 'owa_commerce_line_item_fact'] as $table) {
        try {
            $db = owa_coreAPI::dbSingleton();
            $db->deleteFrom($table);
            $db->where('site_id', $site_id);
            $db->executeQuery();
            $removed[$table] = 'cleared';
        } catch (\Throwable $e) {
            $removed[$table] = 'skip: ' . $e->getMessage();
        }
    }

    /*
     * The referer rows this fixture mints, which the loop above does not reach.
     *
     * Dimension rows are content-hashed and shared, so as a rule they are left
     * alone. These are the exception: a referer row carries a page_title, the
     * seeded referer URLs are unique to this fixture, and RefererHandlers
     * REUSES an existing row rather than rewriting it. So a title written by an
     * earlier run -- a real one, if that box had crawling on -- survives into
     * the next and the fixture stops being deterministic. Deleting only the
     * fixture's own URLs cannot touch anyone else's data.
     */
    foreach (E2E_REFERERS as $url) {
        try {
            $db = owa_coreAPI::dbSingleton();
            $db->deleteFrom('owa_referer');
            $db->where('id', \OWA\Core\Lib::setStringGuid($url));
            $db->executeQuery();
        } catch (\Throwable $e) {}
    }
    $removed['owa_referer'] = 'fixture rows cleared';

    $removed['owa_notification'] = unseedNotifications() . ' fixture notification(s) removed';
    $removed['owa_custom_report'] = ( unseedOthersReport() + unseedBreakdownReport()
        + unseedFunnelVisualization() ) . ' fixture report(s) removed';

    /*
     * The fixture goal event, and the conditions hanging off it.
     *
     * Goals are ROWS now, in owa_goal_event -- the table loop above does not
     * reach them because it clears fact tables, and a goal event left behind
     * keeps converting against real traffic and keeps its group showing as a
     * metric-set tab on every tabbed report.
     *
     * Only this one is removed, by its derived id: an install may have goal
     * events of its own, and clearing the table wholesale would take them too.
     *
     * Its conditions go first. They are separate rows keyed by goal_event_id
     * and nothing cascades, so deleting only the goal event leaves conditions
     * pointing at an id that no longer resolves -- and the next run's goal
     * event, minted at the same derived id, would inherit them.
     */
    try {
        $goalEventId = \OWA\Module\Base\Classes\GoalManager::goalEventIdFor(
            E2E_SITE_ID, E2E_GOAL_NUMBER);

        $goalEvent = owa_coreAPI::entityFactory('base.goal_event');
        $goalEvent->load($goalEventId);

        if ($goalEvent->wasPersisted()) {

            $db = owa_coreAPI::dbSingleton();
            $db->deleteFrom(
                owa_coreAPI::entityFactory('base.goal_event_condition')->getTableName());
            $db->where('goal_event_id', $goalEventId);
            $db->executeQuery();

            $goalEvent->delete($goalEventId);
            $removed['goal'] = 'fixture goal event removed';

        } else {
            $removed['goal'] = 'none';
        }
    } catch (\Throwable $e) {
        $removed['goal'] = 'skip: ' . $e->getMessage();
    }

    // Remove the site_user grant (keyed by internal ids), then the user & site.
    try {
        $u = owa_coreAPI::entityFactory('base.user');
        $u->load(E2E_USER_ID, 'user_id');
        $s = owa_coreAPI::entityFactory('base.site');
        $s->load($site_id, 'site_id');
        if ($u->get('id') && $s->get('id')) {
            $db = owa_coreAPI::dbSingleton();
            $db->deleteFrom('owa_site_user');
            $db->where('user_id', $u->get('id'));
            $db->where('site_id', $s->get('id'));
            $db->executeQuery();
            $removed['owa_site_user'] = 'cleared';
        }
    } catch (\Throwable $e) { $removed['owa_site_user'] = 'skip: ' . $e->getMessage(); }

    // Remove the fixture users (analyst + admin) and site.
    try { owa_coreAPI::entityFactory('base.user')->delete(E2E_USER_ID, 'user_id'); } catch (\Throwable $e) {}
    try { owa_coreAPI::entityFactory('base.user')->delete(E2E_ADMIN_ID, 'user_id'); } catch (\Throwable $e) {}
    try { owa_coreAPI::entityFactory('base.user')->delete(E2E_PWUSER_ID, 'user_id'); } catch (\Throwable $e) {}
    // CRUD-test leftovers (only present if an add-then-delete test aborted midway).
    try { owa_coreAPI::entityFactory('base.user')->delete(E2E_NEW_USER_ID, 'user_id'); } catch (\Throwable $e) {}
    try {
        $cs = owa_coreAPI::entityFactory('base.site');
        // Created through the admin UI, so its identifier is minted and cannot
        // be predicted here. The domain is what this cleanup actually knows.
        $cs->load(E2E_NEW_SITE_DOMAIN, 'domain');
        if ($cs->get('id')) { $cs->delete($cs->get('id'), 'id'); }
    } catch (\Throwable $e) {}
    try {
        $s = owa_coreAPI::entityFactory('base.site');
        $s->load($site_id, 'site_id');
        if ($s->get('id')) { $s->delete($s->get('id'), 'id'); }
    } catch (\Throwable $e) {}

    return ['status' => 'torn down', 'tables' => $removed];
}

/**
 * Fire up to $n synthetic pageviews for the fixture site through the real
 * ingestion pipeline, spread across a few pages, two visitors, and -- crucially
 * -- SEVERAL DAYS, so the report has real shape: multiple rows in the pages
 * grid AND a non-flat timeseries (the sparkline KPI boxes and the Flot area /
 * trend charts need >1 day of data or they collapse to a single point).
 *
 * TIME-TRAVEL: OWA stamps every event with the request timestamp, not a value
 * the caller sets on the event. The 'timestamp' property has a registered
 * filter (owa_trackingEventHelpers::timestampDefault) that IGNORES whatever is
 * on the event and returns owa_coreAPI::getRequestTimestamp() -- i.e. the
 * requestContainer singleton's timestamp (set once to time()). Every fact-table
 * time dimension (year/month/day/yyyymmdd/hour) is then DERIVED from that at log
 * time (owa_trackingEventHelpers::deriveYyyymmdd et al.). So to backdate an
 * event we must move the singleton's clock before logEvent(); setting a
 * 'timestamp' prop alone is silently overwritten.
 *
 * The plan below fires exactly E2E_PAGEVIEWS (8) pageviews: 4 pages x 2 views
 * each (keeps the pages-grid contract -- 4 rows, count "2" per page), arranged
 * as 4 single-day visits by 2 returning visitors across 4 days spanning the
 * last_thirty_days window. $n caps how many of the plan actually fire (used by
 * the idempotent partial-reseed path); on a fresh DB $n == 8 fires the lot.
 */
/**
 * Seed commerce transactions + line items for the fixture site.
 *
 * Idempotent by order_id: re-running the seeder does not duplicate rows, which
 * matters because the commerce report assertions use exact revenue totals.
 *
 * Rows are written directly rather than pushed through the tracker: the
 * e-commerce beacon needs a live session to attach to, and these facts only
 * have to be reportable, not realistic.
 */
function seedTransactions(): int
{
    $site_id = E2E_SITE_ID;
    $seeded  = 0;
    foreach (E2E_TXNS as $txn) {
        $existing = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $existing->load($txn['order_id'], 'order_id');
        if ($existing->wasPersisted()) {
            continue;
        }
        // Midday on its day, matching seedPageviews() so both land inside the
        // same reporting window.
        $ts = time() - ($txn['day_ago'] * 86400);
        $ts = $ts - ($ts % 86400) + 43200;
        // Attach to the session seeded for the same day. Commerce facts written
        // by the real handler inherit session_id/visitor_id from the parent
        // event, and the e-commerce OVERVIEW report reads its totals off the
        // SESSION rather than off these tables -- so facts with session_id 0
        // report as zero revenue no matter how much is in the fact rows.
        $session = sessionForDay($site_id, (int) date('Ymd', $ts));

        $t = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $t->set('id', numericGuid());
        $t->set('site_id', $site_id);
        $t->set('session_id', $session['id'] ?? 0);
        $t->set('visitor_id', $session['visitor_id'] ?? 0);
        $t->set('order_id', $txn['order_id']);
        $t->set('order_source', 'e2e-fixture');
        $t->set('gateway', 'e2e');
        // Currency is stored in CENTS -- the columns are BIGINT and the real
        // handler runs every amount through prepareCurrencyValue() ($v * 100).
        // Writing dollars here would report $0.43 for a $42.60 order.
        $t->set('total_revenue', owa_lib::prepareCurrencyValue($txn['revenue']));
        $t->set('tax_revenue', owa_lib::prepareCurrencyValue($txn['tax']));
        $t->set('shipping_revenue', owa_lib::prepareCurrencyValue($txn['shipping']));
        $t->set('timestamp', $ts);
        $t->set('yyyymmdd', (int) date('Ymd', $ts));
        $t->set('year', (int) date('Y', $ts));
        $t->set('month', (int) date('n', $ts));
        $t->set('day', (int) date('j', $ts));
        $t->save();
        $seeded++;
        foreach ($txn['items'] as $item) {
            $li = owa_coreAPI::entityFactory('base.commerce_line_item_fact');
            $li->set('id', numericGuid());
            $li->set('site_id', $site_id);
            $li->set('session_id', $session['id'] ?? 0);
            $li->set('visitor_id', $session['visitor_id'] ?? 0);
            $li->set('order_id', $txn['order_id']);
            $li->set('sku', $item['sku']);
            $li->set('product_name', $item['name']);
            $li->set('category', $item['category']);
            $li->set('unit_price', owa_lib::prepareCurrencyValue($item['price']));
            $li->set('quantity', $item['qty']);
            $li->set('item_revenue', owa_lib::prepareCurrencyValue($item['price'] * $item['qty']));
            $li->set('timestamp', $ts);
            $li->set('yyyymmdd', (int) date('Ymd', $ts));
            $li->set('year', (int) date('Y', $ts));
            $li->set('month', (int) date('n', $ts));
            $li->set('day', (int) date('j', $ts));
            $li->save();
        }

        if (!empty($session['id'])) {
            summariseCommerceOntoSession($session['id']);
        }
    }
    return $seeded;
}

/**
 * The session row seeded for a given day, or null.
 *
 * seedPageviews() creates one session per visit day, and the transaction days
 * are chosen to line up with two of them.
 */
function sessionForDay(string $site_id, int $yyyymmdd): ?array
{
    $db = owa_coreAPI::dbSingleton();
    $db->connect();
    $rows = $db->get_results(
        "SELECT id, visitor_id FROM owa_session WHERE site_id = '" . $db->prepare($site_id) . "'"
        . " AND yyyymmdd = " . (int) $yyyymmdd . " LIMIT 1"
    );
    return is_array($rows) && $rows ? (array) $rows[0] : null;
}

/**
 * Roll the session's commerce columns up from the fact tables.
 *
 * Deliberately uses the SAME owa_coreAPI::summarize() calls as
 * SessionCommerceSummaryHandlers rather than computing the numbers here, so the
 * fixture cannot drift from what the application would have written had these
 * facts arrived through the tracker.
 */
function summariseCommerceOntoSession($session_pk): void
{
    $s = owa_coreAPI::entityFactory('base.session');
    $s->getByPk('id', $session_pk);
    if (!$s->get('id')) {
        return;
    }

    $txn = owa_coreAPI::summarize([
        'entity'      => 'base.commerce_transaction_fact',
        'columns'     => ['id' => 'count', 'total_revenue' => 'sum',
                          'tax_revenue' => 'sum', 'shipping_revenue' => 'sum'],
        'constraints' => ['session_id' => $session_pk],
    ]);
    $s->set('commerce_trans_count', $txn['id_count']);
    $s->set('commerce_trans_revenue', $txn['total_revenue_sum']);
    $s->set('commerce_tax_revenue', $txn['tax_revenue_sum']);
    $s->set('commerce_shipping_revenue', $txn['shipping_revenue_sum']);

    $items = owa_coreAPI::summarize([
        'entity'      => 'base.commerce_line_item_fact',
        'columns'     => ['sku' => 'count_distinct', 'item_revenue' => 'sum',
                          'quantity' => 'sum'],
        'constraints' => ['session_id' => $session_pk],
    ]);
    $s->set('commerce_items_count', $items['sku_dcount']);
    $s->set('commerce_items_revenue', $items['item_revenue_sum']);
    $s->set('commerce_items_quantity', $items['quantity_sum']);

    $s->update();
}

function seedPageviews(int $n): int
{
    $site_id = E2E_SITE_ID;
    $rc = owa_coreAPI::requestContainerSingleton();

    // Two stable visitor identities so repeat-visitor reports have a returning
    // visitor. Generated once; reused across each visitor's two sessions.
    $visitors = [numericGuid(), numericGuid()];

    // Four single-day visits spanning the 30-day window. Each visit is one
    // session (one day) of two pageviews; the two visits per visitor land on
    // different days so the trend line has multiple non-zero points. Every page
    // ('/', '/pricing', '/docs', '/about') appears in exactly two pageviews.
    /*
     * Attribution, so the Traffic reports have rows to draw.
     *
     * Without it every session was medium='direct' and Traffic's pie and three
     * grids all returned nothing.
     *
     * Only the referring URL is seeded. `session_referer` is the single input
     * the whole chain derives from: deriveMedium() reads it for the medium,
     * deriveSource() for the source, extractSearchTerm() for the terms, and
     * generateDimensionId() mints referer_id and referring_search_term_id off
     * those. Setting medium or source here instead would state the answers and
     * skip the code that produces them.
     *
     * NOT the same as the HTTP_REFERER below, which nothing derives from.
     *
     * Sent on EVERY request of the visit, as the tracker does: the session
     * handler's new-session branch does not copy medium onto the session, so
     * attribution reaches it through the update branch a later request takes.
     * A single-request visit would stay medium='direct'.
     *
     * Spread so each medium has a distinct count: organic-search 2,
     * referral 1, direct 1.
     */
    $visits = [
        ['day_ago' => 23, 'visitor' => 0, 'new_visitor' => true,  'pages' => ['/', '/pricing'],
         'referer' => E2E_REFERERS[0]],
        ['day_ago' => 16, 'visitor' => 1, 'new_visitor' => true,  'pages' => ['/docs', '/about'],
         'referer' => E2E_REFERERS[1]],
        ['day_ago' => 9,  'visitor' => 0, 'new_visitor' => false, 'pages' => ['/', '/pricing']],
        /*
         * One visit that goes THROUGH the funnel, in order.
         *
         * The goal funnel counts people who reached each step after the one
         * before it, so a fixture where nobody walks '/' -> '/pricing' ->
         * '/docs' in that order has a funnel that can only ever end in zero --
         * and a spec written against it can only assert that the report renders,
         * never that it counts. Visitor 0 already does the first two steps; this
         * visit carries them to the goal.
         */
        ['day_ago' => 4,  'visitor' => 0, 'new_visitor' => false, 'pages' => ['/', '/pricing', '/docs']],

        ['day_ago' => 2,  'visitor' => 1, 'new_visitor' => false, 'pages' => ['/docs', '/about'],
         'referer' => E2E_REFERERS[2]],
    ];

    $count = 0;

    foreach ($visits as $visit) {
        $session_id = numericGuid();
        $visitor_id = $visitors[$visit['visitor']];
        // Anchor the visit near midday on its day so it can't slip across a day
        // boundary once we add the per-pageview minute offsets below.
        $day_base = time() - ($visit['day_ago'] * 86400);
        $day_base = $day_base - ($day_base % 86400) + 43200; // 12:00 UTC that day

        foreach (array_values($visit['pages']) as $i => $page) {
            if ($count >= $n) {
                break 2;
            }
            $url     = E2E_SITE_DOMAIN . $page;
            $isFirst = ($i === 0);

            // Backdate the request clock; timestampDefault() reads this and the
            // derived time dimensions follow. Pageviews within a visit are a few
            // minutes apart so their order (and the session duration) is sane.
            $rc->timestamp = $day_base + ($i * 120);

            $props = [
                'site_id'          => $site_id,
                'session_id'       => $session_id,
                'visitor_id'       => $visitor_id,
                'is_new_session'   => $isFirst,
                'is_new_visitor'   => $isFirst && $visit['new_visitor'],
                /*
                 * is_repeat_visitor is deliberately NOT set here.
                 *
                 * The seeder fires real events through logEvent, so the value
                 * is DERIVED from is_new_visitor like any tracked hit. Passing
                 * it explicitly made it worse, not better -- the tracker
                 * property pipeline processes a supplied value differently
                 * from an absent one, and the rows came out NULL where leaving
                 * it alone gives 0. A fixture that sets it would also stop
                 * exercising the derivation this suite exists to cover.
                 */
                'page_url'         => $url,
                'page_title'       => 'E2E ' . ($page === '/' ? 'Home' : trim($page, '/')),
                'HTTP_USER_AGENT'  => $_SERVER['HTTP_USER_AGENT'],
                'HTTP_REFERER'     => ($isFirst && $visit['new_visitor']) ? 'https://www.google.com/' : $url,
                'ip_address'       => '203.0.113.' . (10 + $visit['visitor']),
                'guid'             => numericGuid(),
            ];

            if (!empty($visit['referer'])) {
                $props['session_referer'] = $visit['referer'];
            }

            $event = owa_coreAPI::supportClassFactory('base', 'event');
            $event->setEventType('base.page_request');
            $event->setProperties($props);

            if (owa_coreAPI::logEvent('base.page_request', $event) !== false) {
                $count++;
            }
        }
    }

    // Restore the request clock so anything later in this process sees "now".
    $rc->timestamp = time();
    return $count;
}




/** Numeric GUID in the tracker's format (BIGINT-safe): <time><6rand><3rand>. */
function numericGuid(): string
{
    return ((string) time())
        . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
        . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

function countSiteRequests(string $site_id): int
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_request');
    $db->selectColumn('COUNT(*) AS c');
    $db->where('site_id', $site_id);
    $row = $db->getOneRow();
    return is_array($row) ? (int) $row['c'] : 0;
}

/** Whether a base.site_user grant already links these INTERNAL ids. */
function siteUserRelationExists($user_internal_id, $site_internal_id): bool
{
    $db = owa_coreAPI::dbSingleton();
    $db->selectFrom('owa_site_user');
    $db->selectColumn('COUNT(*) AS c');
    $db->where('user_id', $user_internal_id);
    $db->where('site_id', $site_internal_id);
    $row = $db->getOneRow();
    return is_array($row) && (int) $row['c'] > 0;
}
