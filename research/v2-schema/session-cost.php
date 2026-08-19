<?php
/**
 * How costly is a session-scoped metric on a single event table, and does an
 * incrementally-maintained rollup fix it?
 *
 * events-per-session (1.x `pagesPerVisit`) is the sharpest case: it cannot be
 * answered without touching every event in the reporting range, so it is the
 * metric most exposed by dropping the precomputed session table. Measured
 * three ways:
 *
 *   1. query time, one month          -- the reporting shape most reports use
 *   2. query time, the whole corpus   -- proxy for one month on a busy install
 *   3. from a rollup table            -- one row per session, built once
 *
 * ...plus what the rollup costs to build, both in full and incrementally, since
 * a scheduler job would only ever touch sessions that are still open.
 *
 * Environment: V2_DB_HOST, V2_DB_USER, V2_DB_PASS, V2_DST_DB.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

function env(string $k): string {
    $v = getenv($k);
    if ($v === false || $v === '') { fwrite(STDERR, "Missing env var $k\n"); exit(1); }
    return $v;
}

$c = mysqli_connect(env('V2_DB_HOST'), env('V2_DB_USER'), env('V2_DB_PASS'));
if (!$c) { fwrite(STDERR, "connect failed\n"); exit(1); }
$c->query("SET SESSION sql_mode = ''");
$db = env('V2_DST_DB');

function q(mysqli $c, string $sql) {
    $r = $c->query($sql);
    if ($r === false) { fwrite(STDERR, "SQL failed: " . $c->error . "\n" . substr($sql, 0, 300) . "\n"); exit(1); }
    return $r;
}

/** Median of $n runs, in ms, plus the row count. */
function timed(mysqli $c, string $sql, int $n = 3): array {
    $t = [];
    $rows = 0;
    for ($i = 0; $i < $n; $i++) {
        $t0 = microtime(true);
        $r = q($c, $sql);
        if ($r instanceof mysqli_result) { $rows = $r->num_rows; $r->free(); }
        $t[] = (microtime(true) - $t0) * 1000;
    }
    sort($t);
    return ['ms' => round($t[intdiv($n, 2)], 1), 'rows' => $rows];
}

$scale = (array) q($c, "SELECT COUNT(*) e, COUNT(DISTINCT session_id) s FROM `$db`.owa_event")->fetch_assoc();
printf("corpus: %s events across %s sessions\n\n", number_format((int)$scale['e']), number_format((int)$scale['s']));

// --- 1 & 2: query time -----------------------------------------------------
// events per session, averaged. The GROUP BY is the whole cost: one group per
// session, over every event in range.
$monthly = "SELECT AVG(n) FROM (
                SELECT session_id, COUNT(*) n
                FROM `$db`.owa_event
                WHERE yyyymmdd BETWEEN 20110301 AND 20110331 AND session_id IS NOT NULL
                GROUP BY session_id) s";

$corpus = "SELECT AVG(n) FROM (
                SELECT session_id, COUNT(*) n
                FROM `$db`.owa_event
                WHERE session_id IS NOT NULL
                GROUP BY session_id) s";

$r = timed($c, $monthly);
printf("query time, one month (%s events in range):        %8.1f ms\n",
    number_format((int)((array) q($c, "SELECT COUNT(*) n FROM `$db`.owa_event WHERE yyyymmdd BETWEEN 20110301 AND 20110331")->fetch_assoc())['n']),
    $r['ms']);

$r = timed($c, $corpus);
printf("query time, whole corpus (busy-month proxy):       %8.1f ms\n\n", $r['ms']);

// --- 3: the rollup ---------------------------------------------------------
// One row per session. Deliberately small: the counts and bounds a session
// report needs, and nothing else. No goal columns, no commerce accumulators,
// no prior-session block -- those are what made owa_session 121 columns wide.
q($c, "DROP TABLE IF EXISTS `$db`.owa_session_rollup");
q($c, "CREATE TABLE `$db`.owa_session_rollup (
    session_id BIGINT UNSIGNED NOT NULL,
    site_id VARCHAR(64) NOT NULL DEFAULT '',
    yyyymmdd INT UNSIGNED NOT NULL,
    visitor_id BIGINT UNSIGNED,
    events INT UNSIGNED NOT NULL,
    pageviews INT UNSIGNED NOT NULL,
    first_ts BIGINT UNSIGNED,
    last_ts BIGINT UNSIGNED,
    PRIMARY KEY (session_id),
    KEY site_date (site_id, yyyymmdd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$build = "INSERT INTO `$db`.owa_session_rollup
          SELECT session_id,
                 MIN(site_id), MIN(yyyymmdd), MIN(visitor_id),
                 COUNT(*), SUM(event_type = 'pageview'),
                 MIN(ts), MAX(ts)
          FROM `$db`.owa_event
          WHERE session_id IS NOT NULL
          GROUP BY session_id";

$t0 = microtime(true);
q($c, $build);
$full = (microtime(true) - $t0) * 1000;
printf("rollup: full rebuild of every session:             %8.1f ms  (%s rows)\n",
    $full, number_format($c->affected_rows));

$r = timed($c, "SELECT AVG(events) FROM `$db`.owa_session_rollup");
printf("rollup: events/session, whole corpus:              %8.1f ms\n", $r['ms']);

$r = timed($c, "SELECT AVG(events) FROM `$db`.owa_session_rollup WHERE yyyymmdd BETWEEN 20110301 AND 20110331");
printf("rollup: events/session, one month:                 %8.1f ms\n\n", $r['ms']);

// --- incremental maintenance ----------------------------------------------
// A scheduler job never rebuilds everything: a session with no activity for the
// timeout is closed and can never change again. Only recently-active sessions
// are recomputed. Measured over the busiest single day as a stand-in for "one
// scheduler tick's worth of open sessions".
$day = (array) q($c, "SELECT yyyymmdd d, COUNT(*) n FROM `$db`.owa_event
                      GROUP BY yyyymmdd ORDER BY n DESC LIMIT 1")->fetch_assoc();

$incremental = "REPLACE INTO `$db`.owa_session_rollup
                SELECT session_id,
                       MIN(site_id), MIN(yyyymmdd), MIN(visitor_id),
                       COUNT(*), SUM(event_type = 'pageview'),
                       MIN(ts), MAX(ts)
                FROM `$db`.owa_event
                WHERE session_id IS NOT NULL AND yyyymmdd = {$day['d']}
                GROUP BY session_id";

$t0 = microtime(true);
q($c, $incremental);
$inc = (microtime(true) - $t0) * 1000;
printf("rollup: incremental, busiest day (%s events):    %8.1f ms  (%s sessions touched)\n",
    number_format((int)$day['n']), $inc, number_format($c->affected_rows));

printf("\nrollup table size: %s\n",
    (array) q($c, "SELECT CONCAT(ROUND((data_length+index_length)/1048576,1),' MB') s
                   FROM information_schema.tables
                   WHERE table_schema='$db' AND table_name='owa_session_rollup'")->fetch_assoc()['s']);
