<?php
/**
 * The oldest database servers OWA runs on: DatabaseRequirement::MYSQL_MIN and
 * MARIADB_MIN, the floors install and update enforce.
 */
return static function () {

    $version = static fn ( $v ) => preg_replace( '/\.0$/', '', (string) $v );

    return "| Server | Oldest supported |\n|---|---|\n"
         . sprintf( "| MySQL, and servers that report a MySQL version | %s |\n",
             $version( \OWA\Module\Base\Classes\DatabaseRequirement::MYSQL_MIN ) )
         . sprintf( "| MariaDB | %s |\n",
             $version( \OWA\Module\Base\Classes\DatabaseRequirement::MARIADB_MIN ) );
};
