<?php
/**
 * Shared bootstrap for PHP tests that need the full OWA framework + DB
 * (the "beacon-contract" ingestion tests). Pure-helper tests like
 * OwaLibTest do NOT use this — they load owa_lib standalone.
 *
 * Boots OWA once in the same 'logger' role that log.php uses, so
 * owa_coreAPI::logEvent() runs the real ingestion pipeline synchronously
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
}

/**
 * Returns true if the OWA database is reachable, so tests can skip cleanly
 * (rather than error) in environments without DB access. Uses a real
 * SELECT round-trip: mysqli_report is OFF and mysqli_init() leaves a truthy
 * handle even on a failed connect, so connection_status alone is unreliable.
 */
function owa_test_db_available(): bool
{
    try {
        $db = owa_coreAPI::dbSingleton();
        $row = $db->get_row('SELECT 1 AS ok');
        return is_array($row) && isset($row['ok']) && $row['ok'] == 1;
    } catch (\Throwable $e) {
        return false;
    }
}
