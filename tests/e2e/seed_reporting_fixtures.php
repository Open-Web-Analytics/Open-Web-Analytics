<?php
/**
 * Deterministic fixture seeder for the reporting-UI end-to-end (Playwright)
 * tests -- Phase 3.0 safety net.
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
        'site_domain' => E2E_SITE_DOMAIN,
        'site_id'     => md5(E2E_SITE_DOMAIN),
        'user_id'     => E2E_USER_ID,
        'password'    => E2E_USER_PASS,
        'role'        => E2E_USER_ROLE,
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

    // Remove the fixture user and site.
    try { owa_coreAPI::entityFactory('base.user')->delete(E2E_USER_ID, 'user_id'); } catch (\Throwable $e) {}
    try {
        $s = owa_coreAPI::entityFactory('base.site');
        $s->load($site_id, 'site_id');
        if ($s->get('id')) { $s->delete($s->get('id'), 'id'); }
    } catch (\Throwable $e) {}

    return ['status' => 'torn down', 'tables' => $removed];
}

/**
 * Fire $n synthetic pageviews for the fixture site through the real ingestion
 * pipeline, spread across a few pages and two sessions so the report has some
 * shape (multiple rows in the pages grid, a non-flat trend line).
 */
function seedPageviews(int $n): int
{
    $site_id = md5(E2E_SITE_DOMAIN);
    $pages = ['/', '/pricing', '/docs', '/about'];
    $count = 0;

    // Two sessions so "visits" > 1. The FIRST pageview of each session carries
    // is_new_session=true so owa_sessionHandlers::logSession() inserts an
    // owa_session row (subsequent pageviews update it); this mirrors what the
    // tracker sets from the session cookie. Without it, requests land but no
    // session/visit rows exist and visit-based reports render empty.
    $perSession = (int) ceil($n / 2);
    for ($s = 0; $s < 2; $s++) {
        $session_id = numericGuid();
        $visitor_id = numericGuid();
        for ($i = 0; $i < $perSession && $count < $n; $i++) {
            $page = $pages[$count % count($pages)];
            $url  = E2E_SITE_DOMAIN . $page;
            $isFirst = ($i === 0);

            $props = [
                'site_id'          => $site_id,
                'session_id'       => $session_id,
                'visitor_id'       => $visitor_id,
                'is_new_session'   => $isFirst,
                'is_new_visitor'   => $isFirst && $s === 0,
                'page_url'         => $url,
                'page_title'       => 'E2E ' . ($page === '/' ? 'Home' : trim($page, '/')),
                'HTTP_USER_AGENT'  => $_SERVER['HTTP_USER_AGENT'],
                'HTTP_REFERER'     => ($isFirst && $s === 0) ? 'https://www.google.com/' : $url,
                'ip_address'       => '203.0.113.' . (10 + $s),
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
