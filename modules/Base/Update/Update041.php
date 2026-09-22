<?php

namespace OWA\Module\Base\Update;

/**
 * Retire owa_event. There is one reporting cube per Property now.
 *
 * WHY THE SPLIT. The cube is what reports read and what a registered custom
 * dimension adds a column to, and both of those belong to the Property -- GA
 * registers custom definitions on the property for the same reason. One shared
 * cube forces one namespace across the whole installation, which is the v1
 * failure custom dimensions exist to end, and shares out InnoDB's ~8KB row
 * budget so that one Property could exhaust the rest. Split, each has its own
 * namespace, its own budget, and its own retention and granularity.
 *
 * NOTHING IS LOST. Every row of owa_event was derived from owa_event_raw by a
 * build, and this does not touch raw. The per-Property cubes are created by
 * cmd=cube-rebuild on a Property's first data, and the same build refills them.
 * So this drops a derived table, not a record of anything observed -- which is
 * also why it can drop it rather than migrate it: rebuilding is cheaper than a
 * copy, and it re-applies the current classifier while it is at it.
 *
 * NOT CLI-ONLY. Dropping an empty or derived table is a DDL statement like any
 * other, and holding the upgrade back for it would stop an administrator
 * completing it from the admin screen.
 */
class Update041 extends \OWA\Core\Update {

    var $schema_version = 41;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Module\Base\Classes\Cube\Cubes::preSplitTable();

        if ( ! $db->tableExists( $table ) ) {

            return true;
        }

        if ( \OWA\Module\Base\Classes\Cube\Cubes::preSplitEntity()->dropTable() === false ) {

            $this->e->notice( sprintf( 'Drop table %s failed', $table ) );

            return false;
        }

        $this->e->notice( sprintf(
            '%s dropped. Each Property gets its own cube, created by cmd=cube-rebuild '
          . 'on its first data.', $table ) );

        return true;
    }

    /**
     * Put it back.
     *
     * The exact inverse: empty and partitioned in the shape Update035 created
     * it in, because that is what a rollback lands on. It comes back with no
     * rows for the same reason this could drop it -- everything in it was
     * derived, and cmd=cube-rebuild is what fills it.
     *
     * The per-Property cubes are deliberately NOT dropped here. They are not
     * this update's to remove: nothing created them but a build, one can exist
     * with no schema change at all, and a rollback that deleted them would take
     * away rebuildable data an installation might still be reporting from.
     */
    function down() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Module\Base\Classes\Cube\Cubes::preSplitEntity();

        if ( $db->tableExists( $entity->getTableName() ) ) {

            return true;
        }

        if ( $entity->createTable() === false ) {

            $this->e->notice( sprintf(
                'Create table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
