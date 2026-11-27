<?php
/**
 * Boot probe for the OWA_USE_STATIC_CONFIG_ONLY switch, run as a SUBPROCESS by
 * StaticConfigBootTest. The switch applies only to a collector; on a role
 * with a database the stored settings are read.
 *
 * WHY A SUBPROCESS
 * The switch is a process-global `define` (OWA_USE_STATIC_CONFIG_ONLY) that
 * Settings::__construct reads once, before Core\Caller decides whether to
 * load the stored user settings from the database. It cannot be toggled
 * mid-process, and the PHPUnit runner has already booted OWA once as a singleton
 * against the live config. So the only honest way to observe a FRESH boot under
 * a given define is to spawn a clean PHP process. This probe is that process: it
 * optionally defines the switch, boots OWA in the same 'tracking' request type log.php
 * uses, and reports whether the DB connection was opened during boot.
 *
 * SIGNAL
 * Db::isConnectionEstablished() is the reliable "did boot touch the DB?"
 * marker: the driver connects lazily on the first query(), and the only
 * boot-time query is the settings read -- the install rows of owa_setting. No
 * read => no connection => neither the session setup nor the settings SELECT
 * fires.
 *
 * USAGE
 *   php static_config_boot_probe.php                  -> default boot (loads DB config)
 *   php static_config_boot_probe.php static           -> OWA_USE_STATIC_CONFIG_ONLY=true
 *   php static_config_boot_probe.php static collector -> and OWA_SERVER_ROLE collector,
 *                                                        with an SQS queue it never sends to
 * Prints one JSON object: { "connected": bool, "static": bool,
 * "active_modules": [...], "base": { key: value }, "constants": [ key, ... ] },
 * where constants are the Base keys a config-file constant governs and base is
 * Base's other settings after boot. Governed values (credentials among them)
 * are not printed.
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

$static    = (($argv[1] ?? '') === 'static');
$collector = (($argv[2] ?? '') === 'collector');

if ($static) {
    // Set BEFORE owa.php is required: owa_settings::__construct ->
    // applyConfigConstants() reads this define, and that runs before the caller
    // reaches the DB-config-load gate. This is the whole point of the switch --
    // a caller override would arrive too late.
    define('OWA_USE_STATIC_CONFIG_ONLY', true);
}

if ($collector) {
    define('OWA_SERVER_ROLE', 'collector');
    define('OWA_SQS_QUEUE_URL', 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-static-probe');
    define('OWA_SQS_KEY', 'AKIAPROBEEXAMPLE');
    define('OWA_SQS_SECRET', 'probe-secret');
}

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');

new owa(['tracking_mode' => true, 'request_type' => 'tracking']);

$db = \OWA\Core\CoreAPI::dbSingleton();

$c         = \OWA\Core\CoreAPI::configSingleton();
$constants = array_keys($c->configConstants()['base'] ?? []);

echo json_encode([
    'connected'      => (bool) $db->isConnectionEstablished(),
    'static'         => $static,
    'active_modules' => \OWA\Core\CoreAPI::getActiveModules(),
    'base'           => array_diff_key((array) $c->fetch('base'), array_flip($constants)),
    'constants'      => $constants,
], JSON_PRESERVE_ZERO_FRACTION) . "\n";
