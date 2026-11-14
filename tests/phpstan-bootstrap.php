<?php
/**
 * PHPStan bootstrap.
 *
 * Loads Composer's autoloader and the MySQL dialect, whose file-scope define()s
 * of the OWA_SQL_* and OWA_DTD_* constants PHPStan would not otherwise see.
 * Paths and the version are \OWA\Core\Env's, not constants.
 */

$owa_vendor_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($owa_vendor_autoload)) {
    require_once $owa_vendor_autoload;

    class_exists(\OWA\Core\Db\Mysql::class);
}
