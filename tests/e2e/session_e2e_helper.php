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

/**
 * Fact tables carrying a session_id. A dangling row in ANY of these is a session
 * the server was never told to create -- and only page_request_logged surfaces
 * that as a queue item, so the rest fail silently.
 */
function factTables(): array
{
    return ['owa_request', 'owa_click', 'owa_domstream', 'owa_commerce_transaction_fact'];
}

/** Every value of $column in $table for this site. */
function columnValues(string $table, string $column, string $site_id): array
{
    $db = db();
    $db->selectFrom($table);
    $db->selectColumn($column);
    $db->where('site_id', $site_id);
    $rows = $db->getAllRows();

    $out = [];
    foreach ((array) $rows as $r) {
        if (isset($r[$column]) && $r[$column] !== '') {
            $out[] = (string) $r[$column];
        }
    }

    return $out;
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

function sessionState(string $site_id): array
{
    $db = db();

    $db->selectFrom('owa_session');
    $db->selectColumn('id, num_pageviews, is_bounce, first_page_id, last_page_id, referer_id, '
        . 'medium, campaign_id, source_id, latest_attributions, cv1_name, cv1_value');
    $db->where('site_id', $site_id);
    $rows = $db->getAllRows();

    $sessions   = [];
    $session_ids = [];

    foreach ((array) $rows as $r) {
        $session_ids[(string) $r['id']] = true;
        $r['latest_attributions'] = $r['latest_attributions']
            ? json_decode($r['latest_attributions'], true)
            : null;
        $sessions[] = $r;
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

    $dangling = [];
    foreach (factTables() as $t) {
        $n = 0;
        foreach (columnValues($t, 'session_id', $site_id) as $sid) {
            if (!isset($session_ids[$sid])) {
                $n++;
            }
        }
        $dangling[$t] = $n;
    }

    return [
        'site_id'        => $site_id,
        'sessions'       => $sessions,
        'session_count'  => count($sessions),
        'request_count'  => countRows('owa_request', $site_id),
        'queue_depth'    => array_sum($queued_types),
        'queued_types'   => $queued_types,
        'dangling'       => $dangling,
        'dangling_total' => array_sum($dangling),
    ];
}

function resetSite(string $site_id): array
{
    $deleted = [];

    foreach (array_merge(factTables(), ['owa_session']) as $t) {
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
