<?php
/**
 * Shared bootstrap for PHP tests that need the full OWA framework + DB
 * (the "beacon-contract" ingestion tests). Pure-helper tests like
 * OwaLibTest do NOT use this — they load owa_lib standalone.
 *
 * Boots OWA once in the same 'logger' role that log.php uses, so
 * \OWA\Core\CoreAPI::logEvent() runs the real ingestion pipeline synchronously
 * (queue_events defaults to false) down to the fact-table INSERT.
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
        // Non-robotic UA — logEvent() aborts robotic requests when
        // log_robots is false (the default), which would skip the INSERT.
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    }
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

    require_once($owa_root . 'owa.php');

    // Instantiate in the same role as log.php's beacon endpoint.
    $GLOBALS['owa_test_instance'] = new owa([
        'tracking_mode' => true,
        'instance_role' => 'logger',
    ]);

    owa_test_pause_scheduler();

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
 * that database also has a cron running `cmd=schedule-run` every minute. The
 * two collided both ways: a routine cube build picked up a test's fixture
 * cube and dropped its working tables mid-test, and a build of the install's
 * real cube failed on a column an update test had just rolled back. With the
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
        $do, array( $module->getAdminPanels() ) );
}
