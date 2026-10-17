<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_app_password: application passwords for the REST API.
 *
 * The shape is spelled out rather than taken from the entity, so this update
 * creates the same table however the entity changes later.
 */
class Update069 extends \OWA\Core\Update {

    var $schema_version = 69;

    var $is_cli_mode_required = false;

    const DDL = <<<'SQL'
CREATE TABLE %s (
  `id` bigint NOT NULL,
  `lookup_id` varchar(16) NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `secret_hash` varchar(64) NOT NULL,
  `scope` varchar(16) NOT NULL,
  `created_at` int DEFAULT NULL,
  `last_used_at` int DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lookup_id_unique` (`lookup_id`),
  KEY `user_id` (`user_id`)
) %s
SQL;

    /** @var string|null a table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . 'app_password';
    }

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        if ( $db->tableExists( $table ) ) {

            return true;
        }

        if ( $db->query( sprintf( self::DDL, $table, $db->tableOptions() ) ) === false ) {

            $this->e->notice( sprintf( 'Create table %s failed', $table ) );

            return false;
        }

        return true;
    }

    function down() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        if ( ! $db->tableExists( $table ) ) {

            return true;
        }

        if ( $db->query( sprintf( 'DROP TABLE %s', $table ) ) === false ) {

            $this->e->notice( sprintf( 'Drop table %s failed', $table ) );

            return false;
        }

        return true;
    }
}

?>
