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
 * SESSIONS ARE DRIVEN THROUGH logEvent NOW, and the change was forced rather than
 * chosen. They used to be base.session rows written straight to the table, with
 * source_id and medium set on them -- which was defensible while this exercised
 * only the reporting engine. v2 has no session table and no source dimension:
 * a session is a session_start row in owa_event_raw, and source and medium are
 * columns the cube pass derives. So there was nowhere to write the distribution
 * to, and every facet here measured an empty table.
 *
 * Each session is one tagged landing page view, and the cube is built at the end.
 * The tags are what state the distribution -- MediumStep is
 * COALESCE(tagged_medium, classify(referer_host)), so a tag wins over the
 * classification, which is also the only way to express google.com under two
 * different mediums. provision() then VERIFIES the cube reproduced what
 * DISTRIBUTION declares and fails loudly if not, so a classification change
 * cannot quietly turn a wrong number into the expected one.
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

    $rc = owa_coreAPI::requestContainerSingleton();
    $ns = (string) owa_coreAPI::getSetting('base', 'ns');

    foreach (DISTRIBUTION as $source => $mediums) {

        foreach ($mediums as $medium => $count) {

            for ($i = 0; $i < $count; $i++) {

                $n++;

                /*
                 * ONE TAGGED LANDING PAGE VIEW PER SESSION, through logEvent.
                 *
                 * This wrote base.session rows with source_id and medium columns,
                 * and v2 has neither: the session is a session_start row in
                 * owa_event_raw, and source and medium are CUBE columns the pass
                 * derives. So the whole distribution existed only in a table
                 * nothing reads, and all nine facet specs measured an empty one.
                 *
                 * TAGGED RATHER THAN CLASSIFIED FROM THE REFERRER. MediumStep is
                 * COALESCE(tagged_medium, classify(referer_host)) -- the tag wins
                 * -- so tagging states the distribution exactly while still going
                 * through the real path: ingest parses the tags out of
                 * page_location, and the pass coalesces. Classifying instead would
                 * tie this fixture to the search-engine list, which is the one
                 * part of that step designed to change, and it could not express
                 * google.com/cpc at all -- the same source under two mediums is
                 * what makes the distribution asymmetric.
                 *
                 * The referrer is set to match, so a row reads as it would in life
                 * and nothing here depends on which of the two the pass prefers.
                 */
                $session_id = fixtureGuid();
                $visitor_id = fixtureGuid();

                $landing = FIXTURE_DOMAIN . '/facets/' . $n
                    . '?' . $ns . 'source=' . rawurlencode($source)
                    . '&' . $ns . 'medium=' . rawurlencode($medium);

                $rc->setTimestamp($now - $n);

                $event = owa_coreAPI::supportClassFactory('base', 'event');
                $event->setEventType('base.page_request');
                $event->setProperties([
                    'site_id'                => $site_id,
                    'session_id'             => $session_id,
                    'visitor_id'             => $visitor_id,
                    'guid'                   => fixtureGuid(),
                    'page_url'               => $landing,
                    'page_location'          => $landing,
                    'page_title'             => 'Facets ' . $n,
                    'HTTP_REFERER'           => 'https://' . $source . '/',
                    'HTTP_USER_AGENT'        => $_SERVER['HTTP_USER_AGENT'],
                    'ip_address'             => '203.0.113.44',
                    'is_new_session_start'   => true,
                    'is_new_visitor_created' => true,
                    'num_prior_sessions'     => 0,
                    'sts'                    => $now - $n,
                    'fsts'                   => $now - $n,
                ]);

                owa_coreAPI::logEvent('base.page_request', $event);
            }

            $bySource[$source] = ($bySource[$source] ?? 0) + $count;
            $byMedium[$medium] = ($byMedium[$medium] ?? 0) + $count;
            $pairs[]           = ['source' => $source, 'medium' => $medium, 'visits' => $count];
        }
    }

    $rc->setTimestamp($now);

    arsort($bySource);
    arsort($byMedium);

    /*
     * THE CUBE, and then a check that it says what this fixture promised.
     *
     * Every facet assertion reads the cube, so a fixture that seeds raw and stops
     * hands the specs an empty table with no explanation. Building it here is the
     * same step seed_reporting_fixtures.php ends with, for the same reason.
     *
     * The verification is not the fixture describing the query plan -- the thing
     * this file's header refuses. It is the opposite: the distribution is DECLARED
     * in DISTRIBUTION, and this fails loudly if the pipeline did not reproduce it,
     * rather than letting the specs report a wrong number as the expected one.
     */
    $cube = buildFixtureCube($site_id);

    if (! empty($cube['status'])) {
        fwrite(STDERR, "[reporting_facets_helper] cube not built: {$cube['status']}\n");
        exit(4);
    }

    $produced = cubeDistribution($site_id);

    if ($produced !== ['by_source' => $bySource, 'by_medium' => $byMedium]) {
        fwrite(STDERR, "[reporting_facets_helper] the cube does not match DISTRIBUTION.\n"
            . '  declared: ' . json_encode(['by_source' => $bySource, 'by_medium' => $byMedium]) . "\n"
            . '  produced: ' . json_encode($produced) . "\n");
        exit(5);
    }

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

/*
 * sourceDim() stood here and is removed with the session rows it existed for.
 *
 * It minted a base.source_dim row so a session's source_id had something to point
 * at. v2 stores no dimension key: the source is a column ON the cube row, derived
 * by SourceStep from the tag or the referrer, so there is nothing to join and
 * nothing to pre-create.
 */

/** Numeric GUID in the tracker's format (BIGINT-safe). */
function fixtureGuid(): string
{
    return ((string) time())
        . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
        . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

/** Build the fixture Property's cube over whatever raw rows exist. */
function buildFixtureCube(string $site_id): array
{
    $site = owa_coreAPI::entityFactory('base.site');
    $site->load($site_id, 'site_id');

    $property_id = (string) $site->get('property_id');

    if (! $property_id) {
        return ['status' => 'no property on the fixture site'];
    }

    $db  = db();
    $raw = owa_coreAPI::entityFactory('base.event_raw')->getTableName();

    $span = $db->get_row(sprintf(
        "SELECT MIN(yyyymmdd) AS lo, MAX(yyyymmdd) AS hi FROM %s WHERE site_id = '%s'",
        $raw, $db->prepare($site_id)));

    if (empty($span['lo'])) {
        return ['status' => 'no raw rows to build from'];
    }

    if (! $db->tableExists(\OWA\Module\Base\Classes\Cube\Cubes::tableFor($property_id))
        && ! \OWA\Module\Base\Classes\Cube\Cubes::create($property_id)) {
        return ['status' => 'could not create the cube'];
    }

    $builder = new \OWA\Module\Base\Classes\Cube\Builder($property_id);
    $rows    = 0;

    foreach ($builder->partitions((int) $span['lo'], (int) $span['hi']) as $partition) {

        $result = $builder->rebuild($partition);

        if (empty($result['ok'])) {
            return ['status' => 'partition ' . $partition['name'] . ' failed to build'];
        }

        $rows += (int) $result['rows'];
    }

    return ['property' => $property_id, 'rows_built' => $rows];
}

/**
 * Sessions per source and per medium, AS THE CUBE HAS THEM.
 *
 * Counted off session_start rows so one session counts once, which is what the
 * specs assert -- counting every event would multiply by pages per session.
 */
function cubeDistribution(string $site_id): array
{
    $site = owa_coreAPI::entityFactory('base.site');
    $site->load($site_id, 'site_id');

    $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor((string) $site->get('property_id'));
    $db    = db();

    $out = [];

    foreach (['by_source' => 'source', 'by_medium' => 'medium'] as $key => $column) {

        $rows = $db->get_results(sprintf(
            "SELECT %1\$s AS v, COUNT(DISTINCT session_id) AS c FROM %2\$s"
            . " WHERE site_id = '%3\$s' AND event_type = 'session_start'"
            . ' GROUP BY %1\$s ORDER BY c DESC',
            $column, $table, $db->prepare($site_id)));

        $counts = [];

        foreach ((array) $rows as $r) {
            $r = (array) $r;
            $counts[(string) $r['v']] = (int) $r['c'];
        }

        $out[$key] = $counts;
    }

    return $out;
}

function cleanup(): array
{
    $site_id = md5(FIXTURE_DOMAIN);

    /*
     * The cube first, because dropping it needs the Property the site row carries
     * and the site row goes below.
     */
    $site = owa_coreAPI::entityFactory('base.site');
    $site->load($site_id, 'site_id');

    if ($site->get('property_id')) {
        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor((string) $site->get('property_id'));
        try { db()->query('DROP TABLE IF EXISTS ' . $table); } catch (\Throwable $e) {}
    }

    foreach ([owa_coreAPI::entityFactory('base.event_raw')->getTableName(),
              'owa_session', 'owa_request'] as $table) {
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
