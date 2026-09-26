<?php
/**
 * Session lifecycle inspector for the self-host e2e runner.
 *
 * The tracker specs can prove a beacon left the browser, but nothing until now
 * asserted that a session actually LANDS -- that one page view yields one
 * session, that a second extends it rather than minting another, and that a lost
 * first beacon does not strand every later hit in that session.
 *
 * Commands:
 *
 *   session-state site=<id>   sessions, request_count, queue_depth, queued_types,
 *                             dangling  (fact rows whose session_id has no row in
 *                             owa_session -- the silent failure the queue never
 *                             reveals, because dom.click_logged and friends reach
 *                             only the dimension handlers, never sessionHandlers)
 *
 *   reset site=<id>           delete this site's rows so a spec starts clean
 *
 * Queries go through the Db fluent builder rather than raw SQL: getAllRows()
 * executes the builder's accumulated state, so handing it a SQL string fatals in
 * generateSelectQuerySql(). Dangling rows are therefore computed as a set
 * difference in PHP rather than with a LEFT JOIN.
 *
 * Like queue_e2e_helper.php this writes, so it HARD-REFUSES unless booted against
 * the throwaway scratch DB the self-host harness provisions. Reading the name
 * from the booted config rather than the environment means a stray
 * OWA_E2E_DB_NAME cannot point it at anything real.
 */

// owa boots expecting a request context.
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

const SCRATCH_DB_SENTINEL = 'owa_e2e_selfhost';

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

$connected_db = (string) owa_coreAPI::getSetting('base', 'db_name');
$allowed_db   = getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_SENTINEL;

if ($connected_db !== $allowed_db) {
    fwrite(STDERR, "[session_e2e_helper] REFUSING to run: connected DB '$connected_db' "
        . "is not the scratch sentinel '$allowed_db'. This helper only runs under "
        . "the self-host e2e runner.\n");
    exit(3);
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'session-state': out(sessionState(argSite($argv))); break;
    case 'reset':         out(resetSite(argSite($argv)));    break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: session-state site=<id> | reset site=<id>\n");
        exit(2);
}

function out(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

function argSite(array $argv): string
{
    foreach ($argv as $arg) {
        if (strpos($arg, 'site=') === 0) {
            return substr($arg, 5);
        }
    }
    fwrite(STDERR, "Missing required argument: site=<id>\n");
    exit(2);
}

function db()
{
    return owa_coreAPI::dbSingleton();
}

/*
 * ---- WHAT A SESSION IS ON v2 -----------------------------------------------
 *
 * NOT A ROW. This helper read owa_session, and the v1 event chain that fills it
 * came out of Module.php -- so every lookup answered nothing and each of these
 * three specs asserted against an empty table.
 *
 * A session is the set of owa_event_raw rows sharing a session_id, and its
 * existence is a `session_start` row: ingest materialises one from the
 * request-scoped is_new_session_start flag, in the same transaction as the page
 * view that carried it. So the questions this helper answers all survive the
 * move, and two of them get SHARPER:
 *
 *   session_count   session_start rows. A click on a page whose page view was
 *                   lost carries no flag, so it raises no marker and the count
 *                   is 0 -- which is what scenario 3 asserts, and it used to
 *                   mean "sessionHandlers never ran".
 *   dangling        raw rows whose session_id has no session_start row. The
 *                   same stranding, read off the same table.
 *
 * Nothing here computes a v1 dimension id. first_page_id, last_page_id,
 * referer_id, source_id and campaign_id were foreign keys into dimension tables
 * v2 does not join, and no spec read them.
 */

/** The raw table, asked for rather than spelled out. */
function rawTable(): string
{
    return owa_coreAPI::entityFactory('base.event_raw')->getTableName();
}

/** Escaped, because these come from the query string. */
function esc(string $v): string
{
    return db()->prepare($v);
}

/**
 * Rows of one site, as arrays, for an arbitrary predicate.
 *
 * @param string $site_id
 * @param string $columns
 * @param string $and      extra SQL, already escaped, or ''
 */
function rawRows(string $site_id, string $columns, string $and = ''): array
{
    $db = db();
    $db->connect();

    $rows = $db->get_results(
        "SELECT $columns FROM " . rawTable()
        . " WHERE site_id = '" . esc($site_id) . "'"
        . ( $and === '' ? '' : ' AND ' . $and )
        . ' ORDER BY ts'
    );

    return array_map(function ($r) { return (array) $r; }, (array) $rows);
}

function countRows(string $table, string $site_id): int
{
    $db = db();
    $db->selectFrom($table);
    $db->selectColumn('COUNT(*) AS c');
    $db->where('site_id', $site_id);
    $row = $db->getOneRow();

    return is_array($row) ? (int) $row['c'] : 0;
}

/** Raw rows of one event type. */
function countRawRows(string $site_id, ?string $event_type = null): int
{
    $rows = rawRows($site_id, 'id', $event_type === null
        ? '' : "event_type = '" . esc($event_type) . "'");

    return count($rows);
}

function sessionState(string $site_id): array
{
    /*
     * The sessions: one entry per session_start row, with the page views of that
     * session counted off raw.
     *
     * is_bounce is DERIVED here rather than stored. v1 kept a column, maintained
     * by the session handler as pages arrived; v2 has no session row to keep it
     * on, and a bounce is a session with exactly one page view -- which is the
     * definition the v1 column was maintaining anyway.
     */
    $starts = rawRows($site_id,
        'session_id, visitor_id, yyyymmdd, tagged_source, tagged_medium, '
        . 'tagged_campaign, tagged_ad, tagged_search_terms',
        "event_type = 'session_start'");

    $sessions    = [];
    $session_ids = [];

    foreach ($starts as $start) {

        $sid = (string) $start['session_id'];
        $session_ids[$sid] = true;

        $pageviews = countRawRows($site_id,
            "event_type = 'page_view' AND session_id = " . (int) $sid);

        /*
         * The campaign the session arrived on, under the name the spec reads.
         *
         * v1 stored a `latest_attributions` JSON blob on the session row, written
         * by the attribution model. v2 stores the tags as COLUMNS on every row of
         * the session -- ingest parses them out of page_location on the landing
         * beacon -- so the session's attribution is its start row's tags. Returned
         * as a map so a spec can assert on the values without knowing the shape of
         * a blob that no longer exists.
         */
        $attributions = array_filter([
            'source'       => $start['tagged_source'],
            'medium'       => $start['tagged_medium'],
            'campaign'     => $start['tagged_campaign'],
            'ad'           => $start['tagged_ad'],
            'search_terms' => $start['tagged_search_terms'],
        ], function ($v) { return $v !== null && $v !== ''; });

        $sessions[] = [
            'id'                  => $sid,
            'visitor_id'          => (string) $start['visitor_id'],
            'yyyymmdd'            => (int) $start['yyyymmdd'],
            'num_pageviews'       => $pageviews,
            'is_bounce'           => $pageviews === 1 ? 1 : 0,
            'latest_attributions' => $attributions ?: null,
        ];
    }

    // Queue depth is global: the scratch install runs one spec at a time, and a
    // spec that starts with a dirty queue cannot assert queue_depth == 0.
    $db2 = db();
    $db2->selectFrom('owa_queue_item');
    $db2->selectColumn('event_type, COUNT(*) AS c');
    $db2->groupBy('event_type');
    $qrows = $db2->getAllRows();

    $queued_types = [];
    foreach ((array) $qrows as $q) {
        $queued_types[$q['event_type']] = (int) $q['c'];
    }

    /*
     * Events belonging to a session that was never started.
     *
     * Grouped by event_type so the answer says WHAT was stranded, which is the
     * distinction scenario 4 pins: a click fired after a lost page view reaches
     * ingest and is stored, and nothing raises a session for it.
     */
    $dangling = [];

    foreach (rawRows($site_id, 'event_type, session_id') as $row) {

        if (isset($session_ids[(string) $row['session_id']])) {
            continue;
        }

        $type = (string) $row['event_type'];
        $dangling[$type] = ($dangling[$type] ?? 0) + 1;
    }

    return [
        'site_id'        => $site_id,
        'sessions'       => $sessions,
        'session_count'  => count($sessions),
        // v1 called a page view a request, and the spec still does.
        'request_count'  => countRawRows($site_id, 'page_view'),
        'event_count'    => countRawRows($site_id),
        'queue_depth'    => array_sum($queued_types),
        'queued_types'   => $queued_types,
        'dangling'       => $dangling,
        'dangling_total' => array_sum($dangling),
    ];
}

function resetSite(string $site_id): array
{
    $deleted = [];

    /*
     * owa_event_raw is the one that matters; the v1 tables are empty on this
     * branch and each costs one no-op DELETE, which keeps reset correct on a
     * branch where the chain is registered.
     */
    foreach ([rawTable(), 'owa_request', 'owa_session', 'owa_click', 'owa_domstream',
              'owa_commerce_transaction_fact'] as $t) {
        $db = db();
        $db->deleteFrom($t);
        $db->where('site_id', $site_id);
        $db->executeQuery();
        $deleted[] = $t;
    }

    $db = db();
    $db->deleteFrom('owa_queue_item');
    $db->executeQuery();

    return ['site_id' => $site_id, 'reset' => $deleted, 'queue_cleared' => true];
}
