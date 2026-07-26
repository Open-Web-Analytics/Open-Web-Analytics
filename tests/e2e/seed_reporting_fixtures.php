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
const E2E_SITE_NAME   = 'OWA E2E Reporting Fixture';
const E2E_USER_ID     = 'owa-e2e-reporter@example.test';
const E2E_USER_PASS   = 'e2e-Reporter-Pass-1!';   // local throwaway fixture creds
const E2E_USER_ROLE   = 'analyst';                // has view_reports + view_site_list
const E2E_USER_NAME   = 'OWA E2E Reporter';
const E2E_PAGEVIEWS   = 8;                         // number of synthetic pageviews

// A second fixture user with the ADMIN role, so the admin-actions e2e suite
// (tests/e2e/admin-actions.spec.js) can drive the write flows that need
// edit_users / edit_sites / edit_settings / edit_modules capabilities. The
// analyst user above cannot reach any of those. Throwaway LOCAL creds only.
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

// A new session fires base.new_session, whose notify handler builds a mailer.
// The default derived from-address ("owa@localhost") is rejected by PHPMailer
// (no dot in the domain) and would fatal the seed. Pin a valid, clearly
// non-deliverable from-address for this CLI process (mirrors the test bootstrap).
owa_coreAPI::setSetting('base', 'mailer-from', 'owa@owatest.example.com');
// Belt-and-suspenders: don't attempt real delivery during seeding.
owa_coreAPI::setSetting('base', 'notify_new_session', false);

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
        'site_id'        => md5(E2E_SITE_DOMAIN),
        'user_id'        => E2E_USER_ID,
        'password'       => E2E_USER_PASS,
        'role'           => E2E_USER_ROLE,
        'admin_user_id'  => E2E_ADMIN_ID,
        'admin_password' => E2E_ADMIN_PASS,
        'admin_role'     => E2E_ADMIN_ROLE,
        'pw_user_id'     => E2E_PWUSER_ID,
        'pw_password'    => E2E_PWUSER_PASS,
        'pw_passkey'     => E2E_PWUSER_KEY,
    ];
}

function seed(): array
{
    $out = fixtureInfo();

    // 1. Site (createNewSite is idempotent: site_id = md5(domain), guarded by wasPersisted).
    $sm = owa_coreAPI::supportClassFactory('base', 'siteManager');
    $sm->createNewSite(E2E_SITE_DOMAIN, E2E_SITE_NAME);

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
    $s->load(md5(E2E_SITE_DOMAIN), 'site_id');
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

    // 3. Pageview data so a report renders a chart + grid. Only seed if this
    //    site has no requests yet, so re-running doesn't pile up rows.
    $existing = countSiteRequests(md5(E2E_SITE_DOMAIN));
    $seeded = 0;
    if ($existing < E2E_PAGEVIEWS) {
        $seeded = seedPageviews(E2E_PAGEVIEWS - $existing);
    }

    $out['pageviews_seeded']  = $seeded;
    $out['pageviews_total']   = countSiteRequests(md5(E2E_SITE_DOMAIN));
    $out['status']            = 'seeded';
    return $out;
}

function teardown(): array
{
    $site_id = md5(E2E_SITE_DOMAIN);

    // Remove fact/session/request rows for the fixture site. Dimension rows are
    // content-hashed and shared, so (as in the ingestion tests) we leave them.
    // site_id is an md5 hex string (no escaping needed), but use the query
    // builder's parameterized where() rather than string interpolation anyway.
    $removed = [];
    foreach (['owa_request', 'owa_session', 'owa_action_fact'] as $table) {
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
        $cs->load(md5(E2E_NEW_SITE_DOMAIN), 'site_id');
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
function seedPageviews(int $n): int
{
    $site_id = md5(E2E_SITE_DOMAIN);
    $rc = owa_coreAPI::requestContainerSingleton();

    // Two stable visitor identities so repeat-visitor reports have a returning
    // visitor. Generated once; reused across each visitor's two sessions.
    $visitors = [numericGuid(), numericGuid()];

    // Four single-day visits spanning the 30-day window. Each visit is one
    // session (one day) of two pageviews; the two visits per visitor land on
    // different days so the trend line has multiple non-zero points. Every page
    // ('/', '/pricing', '/docs', '/about') appears in exactly two pageviews.
    $visits = [
        ['day_ago' => 23, 'visitor' => 0, 'new_visitor' => true,  'pages' => ['/', '/pricing']],
        ['day_ago' => 16, 'visitor' => 1, 'new_visitor' => true,  'pages' => ['/docs', '/about']],
        ['day_ago' => 9,  'visitor' => 0, 'new_visitor' => false, 'pages' => ['/', '/pricing']],
        ['day_ago' => 2,  'visitor' => 1, 'new_visitor' => false, 'pages' => ['/docs', '/about']],
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
                'page_url'         => $url,
                'page_title'       => 'E2E ' . ($page === '/' ? 'Home' : trim($page, '/')),
                'HTTP_USER_AGENT'  => $_SERVER['HTTP_USER_AGENT'],
                'HTTP_REFERER'     => ($isFirst && $visit['new_visitor']) ? 'https://www.google.com/' : $url,
                'ip_address'       => '203.0.113.' . (10 + $visit['visitor']),
                'guid'             => numericGuid(),
            ];

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
