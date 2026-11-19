<?php
/**
 * Shared bootstrap for PHP tests that need the full OWA framework + DB
 * (the "beacon-contract" ingestion tests). Pure-helper tests like
 * OwaLibTest do NOT use this — they load owa_lib standalone.
 *
 * Boots OWA once in the same 'tracking' request type that log.php uses, so
 * \OWA\Core\CoreAPI::logEvent() runs the real ingestion pipeline synchronously
 * (queue_tracking_events defaults to false) down to the INSERT into the raw
 * event table (base.event_raw).
 *
 * These tests write to the configured OWA database (the dev/test schema in
 * owa-config.php). Each test uses a unique GUID and removes its own row in
 * tearDown, so residue is bounded to a single row even on failure.
 */

if (!defined('OWA_TEST_BOOTSTRAPPED')) {

    define('OWA_TEST_BOOTSTRAPPED', true);

    // Locate the OWA root (this file lives in <root>/tests).
    $owa_root = dirname(__DIR__) . '/';

    // A tracking beacon has no authenticated user; logEvent() drops named
    // users. Ensure the CLI/test context looks like an anonymous request.
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        // Non-robotic UA: under the default spam_detection (normal) a robot
        // agent scores the default threshold and is not stored.
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    }
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

    require_once($owa_root . 'owa.php');

    // Instantiate in the same role as log.php's beacon endpoint.
    $GLOBALS['owa_test_instance'] = new owa([
        'tracking_mode' => true,
        'request_type' => 'tracking',
    ]);

    owa_test_pause_scheduler();

    /*
     * PHPUnit must see PHP's warnings. Under OWA_DEBUG, which the dev config
     * defines, booting installs OWA's own PHP error handler, which writes a
     * warning to the log and returns -- so a warning that fails CI (where
     * there is no config and no debug) passed here unseen.
     */
    $handler = set_error_handler(null);
    if (!(is_array($handler) && $handler[0] instanceof \OWA\Module\Base\Classes\Error)) {
        set_error_handler($handler);
    }

    /*
     * Tracking bundles go to a directory of the suite's own. Creating a
     * Profile or saving its tag settings publishes its bundle (PLAN 2.30.7),
     * and a test doing either would otherwise leave files under the
     * install's public/tracker/ for Profiles it then deletes.
     */
    if (defined('PHPUNIT_COMPOSER_INSTALL')) {
        \OWA\Module\Base\Classes\TrackerBundle::$outDir = sys_get_temp_dir() . '/owa-test-bundles-' . getmypid() . '/';
        register_shutdown_function(static function () {
            exec('rm -rf ' . escapeshellarg(sys_get_temp_dir() . '/owa-test-bundles-' . getmypid()));
        });
    }
}

/**
 * Hold every scheduled job's lease for as long as this PHPUnit process runs.
 *
 * The suite writes to the configured database, and on a development install
 * that database also has a cron running `cmd=schedule-run` every minute.
 * Unheld, the two collide both ways: a routine cube build can pick up a test's
 * fixture cube and drop its working tables mid-test, and a build of the
 * install's real cube can fail on a column an update test has just rolled
 * back. With the
 * leases held, schedule-run skips each job for the tick ("already running")
 * and nothing is recorded as a failure.
 *
 * Only in a PHPUnit process: a child PHP a test starts loads this file too,
 * and must not wait on leases its parent holds. A job running when the suite
 * starts is waited for. The lease outlives a crashed run by at most LEASE
 * seconds, after which the scheduler takes the jobs back on its own.
 */
function owa_test_pause_scheduler(): void
{
    if (!defined('PHPUNIT_COMPOSER_INSTALL') || !owa_test_db_available()) {
        return;
    }

    $lease = 3 * 3600;
    $held  = [];

    foreach (array_keys(\OWA\Module\Base\Classes\JobStatus::jobs()) as $name) {
        $lock = new \OWA\Module\Base\Classes\JobLease($name);

        for ($waited = 0; !$lock->acquire($lease); $waited += 2) {
            if ($waited >= 120) {
                fwrite(STDERR, "bootstrap: scheduled job \"$name\" is still running after 120s; "
                    . "the suite runs alongside it.\n");
                continue 2;
            }

            sleep(2);
        }

        $held[] = $lock;
    }

    register_shutdown_function(static function () use ($held) {
        foreach ($held as $lock) {
            $lock->release();
        }
    });
}

/**
 * Returns true if the OWA database is reachable, so tests can skip cleanly
 * (rather than error) in environments without DB access. Uses a real
 * SELECT round-trip: mysqli_report is OFF and mysqli_init() leaves a truthy
 * handle even on a failed connect, so connection_status alone is unreliable.
 *
 * A FAILED probe is the expected, healthy path on a runner with no database --
 * it is how 143 tests know to skip. But mysqli_real_connect() reports failure
 * by raising a PHP warning, not by throwing, so the catch below never sees it
 * and the warning surfaces attributed to whichever test happened to probe
 * first. That one unavoidable warning is what kept failOnWarning off, which in
 * turn let real warnings accumulate unnoticed.
 *
 * So the probe swallows diagnostics for its own duration only. This suppresses
 * nothing else: the handler is installed immediately before the round-trip and
 * restored immediately after, including on the throw path.
 */
function owa_test_db_available(): bool
{
    set_error_handler(static function () { return true; });

    try {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $row = $db->get_row('SELECT 1 AS ok');
        return is_array($row) && isset($row['ok']) && $row['ok'] == 1;
    } catch (\Throwable $e) {
        return false;
    } finally {
        restore_error_handler();
    }
}

/**
 * One partition per day of a fixture cube, from $from to today.
 *
 * A new cube's partitions are daily from the 1st of this month, and
 * extendPartitionsBack() adds earlier months whole. So a test using days
 * counted back from today has them in a month-wide partition early in a month
 * and in day partitions later -- built together or apart, settled at a
 * different moment -- and passes or fails by the date. This makes the days it
 * uses day partitions on any date.
 *
 * @param string $table cube table
 * @param int    $from  yyyymmdd, the first day the test uses
 */
function owa_test_daily_partitions(string $table, int $from): void
{
    $db = \OWA\Core\CoreAPI::dbSingleton();

    $db->extendPartitionsBack($table, (string) $from);

    $to    = (int) date('Ymd', strtotime('+1 day'));
    $names = [];
    $start = null;
    $end   = null;

    foreach ($db->getPartitionSpans($table) as $span) {

        if ((int) $span['less_than'] > $from && (int) $span['start'] < $to) {

            $names[] = $span['name'];
            $start   = $start ?? (string) $span['start'];
            $end     = (string) $span['less_than'];
        }
    }

    $ranges = \OWA\Core\Db::makePartitionRangesForSpan($start, $end, 'daily');

    if (count($ranges) !== count($names)
        && !$db->reshapePartitions($table, $names, $ranges)) {

        throw new \RuntimeException('splitting ' . $table . ' into day partitions failed: '
            . $db->lastQueryError());
    }
}

/**
 * Partition a fixture table by range with a plain ALTER TABLE.
 *
 * The table's primary key has to carry the column already.
 *
 * @param string $table
 * @param array<string, string> $ranges partition name => exclusive upper bound
 * @param string $column
 */
function owa_test_partition_table(string $table, array $ranges, string $column = 'yyyymmdd'): void
{
    $db = \OWA\Core\CoreAPI::dbSingleton();

    if (!$db->query(sprintf('ALTER TABLE %s%s', $table, $db->makePartitionClause($column, $ranges)))) {
        throw new \RuntimeException('partitioning ' . $table . ' failed: ' . $db->lastQueryError());
    }
}

/**
 * Ranges for a tiered fixture: blocks of whole years over old history, the
 * granularity over the detail window and the lead.
 *
 * The tail blocks are widened exactly as Db::planPartitionCompaction() widens
 * them, so a rotate over the result has nothing to merge.
 *
 * @param string   $min_yyyymmdd  oldest data present
 * @param string   $through       upper boundary to reach (exclusive)
 * @param string   $granularity   granularity for the detail window
 * @param int      $detail_months how much recent history stays fine
 * @param int|null $limit         the partition ceiling; null for none
 * @return array<string, string>
 */
function owa_test_tiered_ranges($min_yyyymmdd, $through, $granularity, $detail_months, $limit = null): array
{
    $boundary = date('Ymd', strtotime(date('Ym') . '01 -' . (int) $detail_months . ' months'));
    $last_day = date('Ymd', strtotime($through . ' -1 day'));

    if ((string) $min_yyyymmdd >= $boundary) {
        return \OWA\Core\Db::makePartitionRanges($min_yyyymmdd, $last_day, $granularity);
    }

    $first_year = (int) substr($min_yyyymmdd, 0, 4);
    $last_year  = (int) substr($boundary, 0, 4);
    $fine       = \OWA\Core\Db::makePartitionRanges($boundary, $last_day, $granularity);
    $years      = max(1, $last_year - $first_year + ($boundary > $last_year . '0101' ? 1 : 0));
    $block      = 1;

    if ($limit !== null) {
        for (; $block < \OWA\Core\Db::PARTITION_MAX_YEARS_PER_BLOCK; $block++) {
            if (count($fine) + (int) ceil($years / $block) <= $limit) {
                break;
            }
        }
    }

    return array_merge(\OWA\Core\Db::yearBlocks($first_year, $boundary, $block), $fine);
}

/**
 * A module's settings-page fieldsets, whether or not the module is switched on.
 *
 * registerAdminPanels() runs only for an ACTIVE module, and is_active lives in
 * the database -- so on an install with no database (CI's unit job) the page is
 * not registered and a test asserting what it renders has nothing to look at.
 * Registering the module directly exercises the real registration code without
 * needing an activated install, which keeps those tests RUNNING in CI rather
 * than skipping there and only ever being checked on a developer's box.
 *
 * @param  string $do    the page's action name
 * @param  string $class the module class to fall back to
 * @return array ordered fieldset declarations
 */
function owa_test_page_fieldsets( string $do, string $class ): array
{
    $sets = \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( $do );

    if ( $sets ) {
        return $sets;
    }

    $module = new $class();
    $module->registerAdminPanels();

    return \OWA\Module\Base\Classes\SettingsForm::pageFieldSets(
        $do, [ $module->getAdminPanels() ] );
}
