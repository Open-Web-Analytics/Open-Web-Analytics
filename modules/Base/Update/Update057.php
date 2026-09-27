<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Index owa_event_raw on (site_id, transaction_id).
 *
 * Ingest now looks up whether a purchase's transaction id is already stored for
 * its site before storing it (Classes\PurchaseDeduplication), once per purchase.
 * Without the index that is a scan of every partition.
 *
 * AN UPDATE because owa_event_raw is on master (Update034, #1119). A fresh
 * install gets the index from the entity, under the same name.
 *
 * NOT CLI-ONLY: adding a secondary index is an online operation.
 */
class Update057 extends \OWA\Core\Update {

    var $schema_version = 57;

    var $is_cli_mode_required = false;

    const INDEX = 'site_transaction';

    function up( $force = false ) {

        if ( $this->hasIndex() ) {

            return true;
        }

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'ALTER TABLE %s ADD INDEX %s (site_id, transaction_id)',
            $this->table(), self::INDEX ) ) !== false;
    }

    /** The exact inverse: the index this added, by its name. */
    function down() {

        if ( ! $this->hasIndex() ) {

            return true;
        }

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'DROP INDEX %s ON %s', self::INDEX, $this->table() ) ) !== false;
    }

    private function table() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
    }

    private function hasIndex() {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
          . " AND TABLE_NAME = '%s' AND INDEX_NAME = '%s' LIMIT 1",
            $this->table(), self::INDEX ) );
    }
}

?>
