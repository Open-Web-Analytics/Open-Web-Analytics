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
 * EVERY CUBE TOO. A cube is created from the same entity, so a fresh one has
 * the index, and one that predates this does not. Covering them both ways is
 * also what keeps a rollback exact: Update050's down() drops transaction_id
 * from the cube, and dropping a column that is in an index SHRINKS the index
 * to what is left -- site_transaction on (site_id) alone -- which the way back
 * up never repairs. Dropped here first, there is nothing to shrink.
 *
 * NOT CLI-ONLY: adding a secondary index is an online operation.
 */
class Update057 extends \OWA\Core\Update {

    var $schema_version = 57;

    var $is_cli_mode_required = false;

    const INDEX = 'site_transaction';

    function up( $force = false ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->tables() as $table ) {

            if ( $this->hasIndex( $table ) ) {

                continue;
            }

            if ( $db->query( sprintf(
                    'ALTER TABLE %s ADD INDEX %s (site_id, transaction_id)',
                    $table, self::INDEX ) ) === false ) {

                $this->e->notice( sprintf( 'Indexing %s failed', $table ) );

                return false;
            }
        }

        return true;
    }

    /** The exact inverse: the index this added, by its name, wherever it is. */
    function down() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->tables() as $table ) {

            if ( ! $this->hasIndex( $table ) ) {

                continue;
            }

            if ( $db->query( sprintf( 'DROP INDEX %s ON %s', self::INDEX, $table ) ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s on %s failed', self::INDEX, $table ) );

                return false;
            }
        }

        return true;
    }

    /** Raw, then every cube. */
    private function tables() {

        return array_merge(
            array( \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ),
            \OWA\Module\Base\Classes\Cube\Cubes::allTables() );
    }

    private function hasIndex( $table ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
          . " AND TABLE_NAME = '%s' AND INDEX_NAME = '%s' LIMIT 1",
            $table, self::INDEX ) );
    }
}

?>
