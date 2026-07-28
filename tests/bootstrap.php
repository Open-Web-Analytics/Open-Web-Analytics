<?php
/**
 * PHPUnit bootstrap.
 *
 * Runs once before any test class, ahead of every `new owa(...)` in the
 * test-case base classes. Loads Composer's autoloader, then handles the
 * NO-DATABASE environment (CI, or any checkout without an owa-config.php).
 *
 * WHY THIS EXISTS
 * ---------------
 * Booting OWA constructs the base.configuration entity (owa_settings::__construct
 * -> owa_coreAPI::entityFactory('base.configuration')). entityFactory() only sets
 * up the storage engine -- the driver file plugins/db/owa_db_<type>.php that
 * define()s OWA_DTD_BIGINT / OWA_DTD_BLOB etc. -- when OWA_DB_TYPE is defined
 * (owa_coreAPI.php ~505). OWA_DB_TYPE normally comes from owa-config.php, which is
 * an install-generated file that is never committed. With no config file the
 * driver is never loaded, so the configuration entity's constructor references an
 * undefined OWA_DTD_BIGINT and fatals on PHP 8 -- before any test body (and thus
 * before the per-test dbAvailable()/owa_test_db_available() skip guards) can run.
 *
 * FIX: when no owa-config.php is present, pre-define OWA_DB_TYPE so the storage
 * engine loads and the DTD constants exist. No database connection is attempted
 * during boot in this state -- owa_caller.php gates the only boot-time DB load on
 * isConfigFilePresent() -- so the DB-backed tests still skip cleanly via their
 * existing guards, while the framework boots far enough to run the pure-unit ones.
 *
 * This is deliberately scoped to the config-absent case so a normal local run
 * (owa-config.php present) is untouched: the config file's own unguarded
 * define('OWA_DB_TYPE', ...) stays the single source of truth and there is no
 * redefine.
 *
 * NOTE: this makes the DB-dependent suites SKIP on CI rather than run. Standing up
 * a real installed schema in CI (mysql service + headless cmd=install) so they
 * actually execute is tracked as its own install-testing phase.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('OWA_DB_TYPE') && !file_exists(__DIR__ . '/../owa-config.php')) {
    // No install config in this environment; give entityFactory() a storage
    // engine to load so the OWA_DTD_* column-type constants get defined.
    define('OWA_DB_TYPE', 'mysql');
}
