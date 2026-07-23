<?php
/**
 * PHPStan bootstrap.
 *
 * OWA defines its path/config constants at runtime in owa_env.php via a bootstrap
 * chain that PHPStan does not execute. Declaring them here (guarded) lets static
 * analysis resolve the many OWA_*_DIR references without running the framework.
 *
 * The values MIRROR owa_env.php's derivation, anchored at the repo root
 * (dirname of this tests/ dir), so that require()/require_once() targets resolve
 * to real files on disk. PHPStan 2.x flags requires whose path does not exist
 * (require.fileNotFound); pointing these at tests/ would produce false positives.
 */

if (!defined('OWA_PATH')) {
    define('OWA_PATH', dirname(__DIR__));           // repo root, no trailing slash
}
if (!defined('OWA_DIR')) {
    define('OWA_DIR', OWA_PATH . '/');
}

$owa_stub_dirs = [
    'OWA_DATA_DIR'         => OWA_DIR . 'owa-data/',
    'OWA_MODULES_DIR'      => OWA_DIR . 'modules/',
    'OWA_BASE_DIR'         => OWA_PATH,             // depricated
    'OWA_BASE_CLASSES_DIR' => OWA_DIR,              // depricated
    'OWA_BASE_MODULE_DIR'  => OWA_DIR . 'modules/base/',
    'OWA_BASE_CLASS_DIR'   => OWA_DIR . 'modules/base/classes/',
    'OWA_INCLUDE_DIR'      => OWA_DIR . 'includes/',
    'OWA_PLUGIN_DIR'       => OWA_DIR . 'plugins/',
    'OWA_CONF_DIR'         => OWA_DIR . 'conf/',
    'OWA_THEMES_DIR'       => OWA_DIR . 'themes/',
    'OWA_TEMPLATE_DIR'     => OWA_DIR . 'templates/',
    'OWA_CACHE_DIR'        => OWA_DIR . 'cache/',
    'OWA_VENDOR_DIR'       => OWA_DIR . 'vendor/',
];

foreach ($owa_stub_dirs as $owa_stub_const => $owa_stub_value) {
    if (!defined($owa_stub_const)) {
        define($owa_stub_const, $owa_stub_value);
    }
}

if (!defined('OWA_VERSION')) {
    define('OWA_VERSION', 'test');
}
