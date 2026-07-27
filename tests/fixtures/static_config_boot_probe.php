<?php
/**
 * Boot probe for the OWA_USE_STATIC_CONFIG_ONLY switch, run as a SUBPROCESS by
 * StaticConfigBootTest.
 *
 * WHY A SUBPROCESS
 * The switch is a process-global `define` (OWA_USE_STATIC_CONFIG_ONLY) that
 * owa_settings::__construct reads once, before owa_caller decides whether to
 * load user settings from the owa_configuration DB table. It cannot be toggled
 * mid-process, and the PHPUnit runner has already booted OWA once as a singleton
 * against the live config. So the only honest way to observe a FRESH boot under
 * a given define is to spawn a clean PHP process. This probe is that process: it
 * optionally defines the switch, boots OWA in the same 'logger' role log.php
 * uses, and reports whether the DB connection was opened during boot.
 *
 * SIGNAL
 * owa_db::isConnectionEstablished() is the reliable "did boot touch the DB?"
 * marker: the mysql driver connects lazily on the first query(), and the only
 * boot-time query is the owa_configuration read at owa_caller.php:~99. No read
 * => no connection => the two boot queries (the SET SESSION sql_mode='' + SELECT
 * * FROM owa_configuration) never fire.
 *
 * USAGE
 *   php static_config_boot_probe.php            -> default boot (loads DB config)
 *   php static_config_boot_probe.php static     -> OWA_USE_STATIC_CONFIG_ONLY=true
 * Prints one JSON object: { "connected": bool, "static": bool }.
 *
 * Excluded from the release tarball with the rest of tests/.
 */

// Match the anonymous-beacon request context the real logger boots under so the
// boot path is identical to log.php's (see tests/bootstrap_owa.php).
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

$static = (($argv[1] ?? '') === 'static');

if ($static) {
    // Set BEFORE owa.php is required: owa_settings::__construct ->
    // applyConfigConstants() reads this define, and that runs before the caller
    // reaches the DB-config-load gate. This is the whole point of the switch --
    // a caller override would arrive too late.
    define('OWA_USE_STATIC_CONFIG_ONLY', true);
}

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');

new owa(['tracking_mode' => true, 'instance_role' => 'logger']);

$db = owa_coreAPI::dbSingleton();

echo json_encode([
    'connected' => (bool) $db->isConnectionEstablished(),
    'static'    => $static,
]) . "\n";
