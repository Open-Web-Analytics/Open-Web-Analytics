<?php
/**
 * Shutdown probe for owa_settings' unsaved-settings save, run as a SUBPROCESS by
 * SettingsShutdownSaveTest.
 *
 * WHY A SUBPROCESS
 * The behaviour under test only happens during PHP's shutdown sequence, after
 * the last line of the script. It cannot be observed from inside a PHPUnit case,
 * because the runner's own process has not shut down yet.
 *
 * WHAT IT DOES
 * Changes one setting and exits WITHOUT calling save(), which is the case the
 * is_dirty flag exists to cover. The setting must still reach the database, and
 * the process must still exit 0.
 *
 * USAGE
 *   php settings_shutdown_probe.php write <module> <key> <value>
 *   php settings_shutdown_probe.php read  <module> <key>
 * write prints { "dirtied": true } before shutdown; anything printed after it
 * comes from the shutdown sequence and is a failure. read prints the stored
 * value from a process that never saw the writer's memory, which is the only
 * honest way to prove the write actually landed.
 *
 * Excluded from the release tarball with the rest of tests/.
 */

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');

new owa(['instance_role' => 'cli']);

$mode   = $argv[1] ?? 'write';
$module = $argv[2] ?? 'base';
$key    = $argv[3] ?? 'owa_settings_shutdown_probe';

if ($mode === 'read') {

    echo json_encode(['value' => owa_coreAPI::getSetting($module, $key)]) . "\n";
    return;
}

// persistSetting() marks the settings dirty WITHOUT writing them; the
// write is exactly what shutdown is supposed to do.
owa_coreAPI::persistSetting($module, $key, $argv[4] ?? 'unset');

echo json_encode(['dirtied' => true]) . "\n";

// Report whether the settings were still unsaved once shutdown began. This is
// the direct signal: persistSetting() above registered the save, shutdown
// functions run FIFO, so by the time this one runs the write must already have
// happened. A destructor-only implementation has not run at all yet.
register_shutdown_function(static function () {

    echo json_encode([
        'dirty_at_shutdown' => (bool) owa_coreAPI::configSingleton()->is_dirty,
    ]) . "\n";
});

// Close the database on the way out, AFTER the settings were dirtied.
//
// This is what makes the test discriminate. In the wild the race is decided by
// PHP's object-destruction order, which varies with what else the process is
// holding -- it bit the CLI test suite and not a bare script. Registering the
// close here pins that ordering down: shutdown functions run FIFO, and the
// settings object registered its save when persistSetting() dirtied it above,
// so a correct implementation writes while the handle is still open and a
// destructor-only implementation finds it gone.
register_shutdown_function(static function () {

    owa_coreAPI::dbSingleton()->close();
});

// Deliberately no save(). Everything that matters happens after this line.
