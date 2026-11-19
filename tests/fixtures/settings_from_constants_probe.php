<?php

/**
 * Boots OWA and prints, as one JSON line, each setting named on the command
 * line as module.key, with the constant that governs it ('' for none)
 * (tests/InstallTimezoneTest.php, tests/UrlSettingsTest.php).
 *
 *   php -d auto_prepend_file=role_constants_prepend.php settings_from_constants_probe.php base.timezone
 *
 * with the constants as a JSON object in OWA_PROBE_CONSTANTS. A constant the
 * install's own owa-config.php defines as well keeps the probe's value.
 */
$_SERVER['HTTP_USER_AGENT'] = 'OWA settings from constants probe';

// owa-config.php redefining a probe constant warns; the first value stands.
set_error_handler( static function () {

    return true;
}, E_WARNING );

require dirname( __DIR__, 2 ) . '/owa.php';

new owa( [ 'request_type' => 'tracking' ] );

restore_error_handler();

$settings = [];

foreach ( array_slice( $argv, 1 ) as $id ) {

    list( $module, $key ) = explode( '.', $id, 2 );

    $settings[ $id ] = [
        'value'    => \OWA\Core\CoreAPI::getSetting( $module, $key ),
        'constant' => \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( $module, $key ),
    ];
}

echo 'PROBE ' . json_encode( $settings ) . "\n";
