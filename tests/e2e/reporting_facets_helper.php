<?php
/**
 * Reporting-facet fixture: known data whose EXACT numbers a spec can assert.
 *
 * WHY THIS EXISTS
 * The reporting e2e asserted that the dashboard rendered -- grids present,
 * dropdown sprites loaded, columns aligned -- and its only numeric assertion was
 * `expect(gridText).toMatch(/\b2\b/)`, which passes if a "2" appears anywhere. So
 * every number on every report could have been wrong and CI would have stayed
 * green. It did: Varnish was stripping owa_source before PHP saw it, the Source
 * Detail report ran with no constraint at all, and every source showed the same
 * unfiltered total. A human found that, not the suite.
 *
 * THE DISTRIBUTION IS DELIBERATELY ASYMMETRIC
 * Every facet below answers with a DIFFERENT number, and no number is repeated:
 *
 *     source        medium     sessions
 *     google.com    organic       5
 *     google.com    cpc           3
 *     bing.com      organic       2
 *     facebook.com  referral      1      -> 11 total
 *
 *   by source:  google 8, bing 2, facebook 1
 *   by medium:  organic 7, cpc 3, referral 1
 *
 * That matters. With equal counts, a query that ignored its constraint, or
 * grouped by the wrong column, or returned the aggregate for every row, could
 * still produce the expected figure and pass. Here every wrong answer is a
 * number the fixture does not contain.
 *
 * The expected values are RETURNED rather than hardcoded in the spec, so the
 * fixture and its assertions cannot drift apart.
 *
 * Sessions are written directly rather than driven through the tracker: this
 * exercises the REPORTING engine, and tracker-beacon.spec.js already covers
 * ingestion. yyyymmdd is set explicitly -- it is NOT NULL, it is the partition
 * key, and a fixture that omits it is testing something the product never does.
 */

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.44';

const SCRATCH_DB_SENTINEL = 'owa_e2e_selfhost';
const FIXTURE_TAG    = 'e2e-facets';
const FIXTURE_DOMAIN = 'https://owa-e2e-facets.example.test';

/** source => [medium => session count]. Asymmetric on purpose; see the header. */
const DISTRIBUTION = [
    'google.com'   => ['organic' => 5, 'cpc' => 3],
    'bing.com'     => ['organic' => 2],
    'facebook.com' => ['referral' => 1],
];

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

$connected_db = (string) owa_coreAPI::getSetting('base', 'db_name');
$allowed_db   = getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_SENTINEL;

if ($connected_db !== $allowed_db) {
    fwrite(STDERR, "[reporting_facets_helper] REFUSING: connected DB '$connected_db' is not "
        . "the scratch sentinel '$allowed_db'.\n");
    exit(3);
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'provision': out(provision()); break;
    case 'cleanup':   out(cleanup());   break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: provision | cleanup\n");
        exit(2);
}

function out(array $r): void
{
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

function db()
{
    return owa_coreAPI::dbSingleton();
}

function provision(): array
{
    cleanup();

    $site_id = md5(FIXTURE_DOMAIN);

    $s = owa_coreAPI::entityFactory('base.site');
    $s->set('id', $s->generateId($site_id));
    $s->set('site_id', $site_id);
    $s->set('domain', FIXTURE_DOMAIN);
    $s->set('name', 'OWA reporting facets fixture');
    $s->set('description', FIXTURE_TAG);
    $s->create();

    $user_id = FIXTURE_TAG . '-analyst@owatest.example.com';
    $u = owa_coreAPI::entityFactory('base.user');
    $u->createNewUser($user_id, 'admin', 'pw-' . FIXTURE_TAG, $user_id, 'OWA facets analyst');
    $u->load($u->generateId($user_id), 'user_id');

    $yyyymmdd = (int) date('Ymd');
    $now      = time();
    $n        = 0;

    $bySource = [];
    $byMedium = [];
    $pairs    = [];

    foreach (DISTRIBUTION as $source => $mediums) {

        $source_pk = sourceDim($source);

        foreach ($mediums as $medium => $count) {

            for ($i = 0; $i < $count; $i++) {

                $n++;
                $sess = owa_coreAPI::entityFactory('base.session');
                $sess->set('id', $sess->generateId(FIXTURE_TAG . "-$source-$medium-$i"));
                $sess->set('site_id', $site_id);
                $sess->set('visitor_id', $sess->generateId(FIXTURE_TAG . "-visitor-$n"));
                $sess->set('source_id', $source_pk);
                $sess->set('medium', $medium);
                $sess->set('timestamp', $now - $n);
                $sess->set('last_req', $now - $n);
                $sess->set('yyyymmdd', $yyyymmdd);
                $sess->set('num_pageviews', 1);
                $sess->create();
            }

            $bySource[$source] = ($bySource[$source] ?? 0) + $count;
            $byMedium[$medium] = ($byMedium[$medium] ?? 0) + $count;
            $pairs[]           = ['source' => $source, 'medium' => $medium, 'visits' => $count];
        }
    }

    arsort($bySource);
    arsort($byMedium);

    return [
        'site_id'  => $site_id,
        'user_id'  => $user_id,
        'api_key'  => $u->get('api_key'),
        // The signing secret. Requests to the API are HMAC-signed over their own
        // URL, so a spec needs this as well as the key -- see rest_e2e_helper.
        'auth_key' => defined('OWA_AUTH_KEY') ? OWA_AUTH_KEY : '',
        'yyyymmdd' => $yyyymmdd,
        'expected' => [
            'total_visits'      => $n,
            'by_source'         => $bySource,
            'by_medium'         => $byMedium,
            'by_source_medium'  => $pairs,
            // Sorted desc by visits -- the order a `sort=visits-` must produce.
            'sources_desc'      => array_keys($bySource),
            'mediums_desc'      => array_keys($byMedium),
            'absent_source'     => 'nosuchsource.example',
        ],
    ];
}

/** The dimension row a session's source_id points at. */
function sourceDim(string $domain)
{
    $d = owa_coreAPI::entityFactory('base.source_dim');
    $pk = $d->generateId($domain);
    $d->set('id', $pk);
    $d->set('source_domain', $domain);
    $d->create();

    return $pk;
}

function cleanup(): array
{
    $site_id = md5(FIXTURE_DOMAIN);

    foreach (['owa_session', 'owa_request'] as $table) {
        $db = db();
        $db->deleteFrom($table);
        $db->where('site_id', $site_id);
        $db->executeQuery();
    }

    $db = db();
    $db->deleteFrom('owa_site');
    $db->where('site_id', $site_id);
    $db->executeQuery();

    $u = owa_coreAPI::entityFactory('base.user');
    $u->delete(FIXTURE_TAG . '-analyst@owatest.example.com', 'user_id');

    return ['status' => 'cleaned'];
}
