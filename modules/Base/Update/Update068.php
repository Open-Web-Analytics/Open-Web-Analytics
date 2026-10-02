<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_queue_item goes (PLAN 2.30.6): the database event queue's table, which
 * nothing reads on v2. Failed beacons are retried through the tracker-ingest
 * intake.
 *
 * What it holds goes with it: retries of events whose v1 handlers failed,
 * mostly ones that fail every time. Update062 says how many were still
 * awaiting a retry; it refuses only for beacons never ingested, which were
 * in 1.x's file queue, not here.
 *
 * The table's shape is spelled out for down(): the entity is gone, and an
 * update is permanent while a shape is not.
 */
class Update068 extends \OWA\Core\Update {

    var $schema_version = 68;

    var $is_cli_mode_required = false;

    const DDL = <<<'SQL'
CREATE TABLE %s (
  `id` bigint NOT NULL,
  `event_type` varchar(255) DEFAULT NULL,
  `priority` int DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `event` blob,
  `insertion_datestamp` timestamp NULL DEFAULT NULL,
  `insertion_timestamp` int DEFAULT NULL,
  `handled_timestamp` int DEFAULT NULL,
  `last_attempt_timestamp` int DEFAULT NULL,
  `not_before_timestamp` int DEFAULT NULL,
  `failed_attempt_count` int DEFAULT NULL,
  `is_assigned` tinyint(1) DEFAULT NULL,
  `last_error_msg` varchar(255) DEFAULT NULL,
  `handled_by` varchar(255) DEFAULT NULL,
  `handler_duration` int DEFAULT NULL,
  PRIMARY KEY (`id`)
)
SQL;

    /** @var string|null a table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . 'queue_item';
    }

    function up( $force = false ) {

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

    function down() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        if ( $db->tableExists( $table ) ) {

            return true;
        }

        if ( $db->query( sprintf( self::DDL, $table ) ) === false ) {

            $this->e->notice( sprintf( 'Create table %s failed', $table ) );

            return false;
        }

        return true;
    }
}

?>
