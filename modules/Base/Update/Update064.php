<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Index owa_event_raw on (site_id, ts), for the realtime screen.
 *
 * Realtime reads a site's last thirty minutes of raw (Classes\Realtime). By
 * site_date alone that is a read of the whole of today for the site on every
 * refresh; by site_ts it is the window. Raw only: the cube entity drops the
 * index, so a fresh install's cubes are created without it as well.
 *
 * NOT CLI-ONLY: adding a secondary index is an online operation.
 */
class Update064 extends \OWA\Core\Update {

    var $schema_version = 64;

    var $is_cli_mode_required = false;

    const INDEX = 'site_ts';

    function up( $force = false ) {

        if ( $this->hasIndex() ) {

            return true;
        }

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'ALTER TABLE %s ADD INDEX %s (site_id, ts)', $this->table(), self::INDEX ) ) !== false;
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
