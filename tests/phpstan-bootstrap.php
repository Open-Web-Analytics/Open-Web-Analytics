<?php
/**
 * PHPStan bootstrap.
 *
 * OWA defines its path/config constants at runtime in owa_env.php via a bootstrap
 * chain that PHPStan does not execute. Declaring them here (guarded) lets static
 * analysis resolve the many OWA_*_DIR references without running the framework.
 */

$owa_stub_constants = [
    'OWA_DIR', 'OWA_PATH', 'OWA_BASE_DIR', 'OWA_DATA_DIR', 'OWA_MODULES_DIR',
    'OWA_INCLUDE_DIR', 'OWA_BASE_CLASSES_DIR', 'OWA_BASE_CLASS_DIR',
    'OWA_PLUGIN_DIR', 'OWA_THEMES_DIR', 'OWA_CACHE_DIR', 'OWA_TEMPLATE_DIR',
];

foreach ($owa_stub_constants as $owa_stub_const) {
    if (!defined($owa_stub_const)) {
        define($owa_stub_const, __DIR__ . '/');
    }
}

if (!defined('OWA_VERSION')) {
    define('OWA_VERSION', 'test');
}
