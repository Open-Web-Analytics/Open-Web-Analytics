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
    'OWA_BASE_MODULE_DIR'  => OWA_DIR . 'modules/Base/',
    'OWA_BASE_CLASS_DIR'   => OWA_DIR . 'modules/Base/Classes/',
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

/**
 * Make static analysis alias-aware for the Phase-6 namespace migration.
 *
 * The compat bridge (owa_compat_aliases.php) resolves legacy `owa_*` names to
 * their new namespaced classes LAZILY, via a runtime class_alias inside an
 * autoloader. PHPStan cannot see through a runtime class_alias: while a rename
 * is mid-flight, an UNMIGRATED file that still references a MIGRATED class by
 * its legacy name (e.g. installManager.php's `owa_user::ADMIN_USER_ROLE`) draws
 * a spurious "unknown class" error even though it resolves fine at runtime
 * through the bridge.
 *
 * Declaring the same aliases EAGERLY here — Composer's classmap loads the new
 * class, then class_alias registers the legacy name — lets PHPStan's reflection
 * resolve the legacy name to the real (migrated) class. This keeps the
 * whole-tree net catching GENUINE breakage (a typo'd class ref, a dropped
 * alias) instead of drowning it in migration shadow that a baseline would have
 * to swallow. The map is the single source of truth in owa_compat_aliases.php;
 * we consume it rather than duplicate it.
 */
$owa_vendor_autoload = OWA_DIR . 'vendor/autoload.php';
if (is_file($owa_vendor_autoload)) {
    require_once $owa_vendor_autoload;
    require_once OWA_DIR . 'owa_compat_aliases.php';

    if (function_exists('owa_compat_class_map')) {
        foreach (owa_compat_class_map() as $owa_legacy => $owa_new) {
            if (class_exists($owa_new) && !class_exists($owa_legacy, false)) {
                class_alias($owa_new, $owa_legacy);
            }
        }
    }
}
