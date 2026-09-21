<?php

namespace OWA\Module\Base\Update;

/**
 * Create owa_event, the reporting cube.
 *
 * Empty, and written only by cmd=cube-rebuild, so this adds storage and
 * changes no behaviour -- the same shape as Update034, which created the two
 * tables it reads.
 *
 * Created ALREADY PARTITIONED, because Db::createTable() reads the entity's
 * partition column: partitioning a table after it holds rows rewrites every one
 * of them, and a build cannot publish into a table with no partitions to
 * exchange.
 */
class Update035 extends \OWA\Core\Update {

    var $schema_version = 35;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        if ( $entity->createTable() === false ) {

            $this->e->notice( sprintf( 'Create table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }

    /**
     * Drop it.
     *
     * The exact inverse: everything in it was derived by a build from
     * owa_event_raw, which this does not touch, so a rebuild reproduces it.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        if ( $entity->dropTable() === false ) {

            $this->e->notice( sprintf( 'Drop table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
