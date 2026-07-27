<?php
/**
 * Server-side helper for the tracking-event-QUEUE end-to-end spec
 * (tests/e2e/event-queue-processing.spec.js).
 *
 * WHY THIS EXISTS
 * The queue flow can't be driven from the browser alone. Turning the incoming
 * tracking-event queue ON is a persisted OWA setting; and once on, a beacon at
 * log.php is written to the FILE queue (owa-data/logs/) and NOT ingested into the
 * fact tables until a separate CLI drain (`php cli.php cmd=processEventQueue`)
 * runs. So the spec needs a same-box helper to: flip the setting, inspect the
 * file-queue depth and the fact-row count, and run the drain -- none of which is
 * reachable over HTTP. The browser half fires the REAL built tracker beacon; this
 * half sets the queue up and verifies the deferred ingestion.
 *
 * WHY IT'S SAFE TO MUTATE STATE HERE
 * It persists a global setting and drains the shared file queue (owa-data/logs/),
 * so it must only ever touch the throwaway SELF-HOST scratch install, never the
 * live one. Every subcommand HARD-REFUSES unless OWA is booted against the
 * sentinel scratch DB (owa_e2e_selfhost) -- the same DB selfhost_harness.php
 * stands up. Run under playwright.selfhost.config.js only; the live-server
 * playwright.config.js does not (and must not) run this spec.
 *
 * SUBCOMMANDS (all print one JSON object)
 *   enable-queue         persist queue_incoming_tracking_events = true
 *   disable-queue        persist queue_incoming_tracking_events = false
 *   state site=<id>      { queue_depth, fact_rows } for the given site_id
 *
 * The DRAIN itself is not a subcommand here: the spec runs the real entrypoint
 * `php cli.php cmd=processEventQueue queues=incoming_tracking_events`, which boots
 * with the proper admin CLI auth and exercises owa_processEventQueueController --
 * the exact code the retry-cap fix touched. This helper only sets the queue up and
 * inspects state around that drain.
 *
 * This file (like all of tests/) is excluded from the release tarball.
 */

// A beacon fired by the seeder path with no UA is dropped as robotic; this helper
// only inspects/drains, but boot parity with the seeder keeps behavior identical.
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

// The scratch DB selfhost_harness.php provisions. MUST match SCRATCH_DB_DEFAULT
// there (and OWA_E2E_DB_NAME if overridden) -- this is the ONLY database this
// helper is willing to mutate.
const SCRATCH_DB_SENTINEL = 'owa_e2e_selfhost';

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');
new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

// Refuse to run against anything but the scratch DB. Reading it from the booted
// config (not the env) means a stray OWA_E2E_DB_NAME can't trick us into pointing
// at the live DB: we compare the ACTUAL connected database name to the sentinel.
$connected_db = (string) owa_coreAPI::getSetting('base', 'db_name');
$allowed_db = getenv('OWA_E2E_DB_NAME') ?: SCRATCH_DB_SENTINEL;
if ($connected_db !== $allowed_db) {
    fwrite(STDERR, "[queue_e2e_helper] REFUSING to run: connected DB '$connected_db' "
        . "is not the scratch sentinel '$allowed_db'. This helper only runs under "
        . "the self-host e2e runner.\n");
    exit(3);
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'enable-queue':  out(setQueue(true));  break;
    case 'disable-queue': out(setQueue(false)); break;
    case 'state':         out(state(argSite($argv))); break;
    default:
        fwrite(STDERR, "Unknown command '$cmd'. Use: enable-queue | disable-queue | state site=<id>\n");
        exit(2);
}

// -----------------------------------------------------------------------------

function out(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
}

/** Pull site=<id> out of argv (state needs the site whose facts we count). */
function argSite(array $argv): string
{
    foreach (array_slice($argv, 2) as $arg) {
        if (strpos($arg, 'site=') === 0) {
            return substr($arg, strlen('site='));
        }
    }
    fwrite(STDERR, "state requires a site=<site_id> argument.\n");
    exit(2);
}

/**
 * Persist queue_incoming_tracking_events. Persisting (not just setSetting) is the
 * point: the beacon that log.php ingests runs in a SEPARATE php -S process, so the
 * flag must live in the stored config, not just this process's memory.
 */
function setQueue(bool $on): array
{
    $c = owa_coreAPI::configSingleton();
    $c->persistSetting('base', 'queue_incoming_tracking_events', $on);
    $c->save();

    return [
        'status'                          => 'ok',
        'queue_incoming_tracking_events'  => $on,
    ];
}

/**
 * Snapshot the two things the spec asserts on:
 *   queue_depth -- serialized events sitting in the file queue, not yet ingested.
 *   fact_rows   -- owa_request rows already persisted for this site.
 * Before the drain: queue_depth > 0, fact_rows unchanged. After: queue drained,
 * fact_rows increased.
 */
function state(string $site_id): array
{
    return [
        'site_id'     => $site_id,
        'queue_depth' => fileQueueDepth(),
        'fact_rows'   => countSiteRequests($site_id),
    ];
}

/**
 * Count events waiting in the incoming file queue. The queue writes each event as
 * one line to owa-data/logs/events.txt, then rotates that file into logs/unprocessed/
 * for the drain to consume. Count lines across the live event file AND any rotated
 * unprocessed files, so this is accurate whether or not a rotation has happened yet.
 */
function fileQueueDepth(): int
{
    $dir = owa_coreAPI::getSetting('base', 'async_log_dir');
    if (!$dir || !is_dir($dir)) {
        return 0;
    }

    $files = [];
    if (is_file($dir . 'events.txt')) {
        $files[] = $dir . 'events.txt';
    }
    $unprocessed = $dir . 'unprocessed/';
    if (is_dir($unprocessed)) {
        foreach (new DirectoryIterator($unprocessed) as $item) {
            if ($item->isFile() && !$item->isDot()) {
                $files[] = $item->getPathname();
            }
        }
    }

    $lines = 0;
    foreach ($files as $file) {
        $contents = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($contents)) {
            $lines += count($contents);
        }
    }
    return $lines;
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
