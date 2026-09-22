<?php

namespace OWA\Module\Base\Update;

/**
 * Create owa_event, the one installation-wide reporting cube.
 *
 * SUPERSEDED BY UPDATE 041, which drops it: there is one cube per Property now
 * (Classes\Cube\Cubes) and no owa_event at all. This is left doing what it did
 * rather than gutted, so that the up and down of the schema stay exact
 * inverses of each other -- a rewind past 41 puts owa_event back, and 35 is
 * what has to take it away again. A fresh installation pays one CREATE and one
 * DROP of an empty table on its way through.
 *
 * Empty, and written only by cmd=cube-rebuild, so this adds storage and
 * changes no behaviour -- the same shape as Update034, which created the two
 * tables it reads.
 *
 * Created ALREADY PARTITIONED, because Db::createTable() reads the entity's
 * partition column: partitioning a table after it holds rows rewrites every one
 * of them, and a build cannot publish into a table with no partitions to
 * exchange.
 *
 * And already partitioned IN THE RIGHT SHAPE. Event::getDailyLeadMonths()
 * asks for the front of the lead daily, so this creates the layout
 * partition-rotate maintains rather than a monthly one that the first rotate has
 * to reshape. A cube created monthly would rewrite a whole month on every
 * cube-rebuild until that rotate ran, and on an installation whose scheduler was
 * never set up, for as long as the month lasted.
 *
 * An installation that applied this update before that was so gets a monthly
 * cube, and the next partition-rotate carves the front in one run -- including
 * the current partition, despite its rows, because leaving it costs the same
 * rewrite on every rebuild. No separate update is needed for it.
 */
class Update035 extends \OWA\Core\Update {

    var $schema_version = 35;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Module\Base\Classes\Cube\Cubes::preSplitEntity();

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

        $entity = \OWA\Module\Base\Classes\Cube\Cubes::preSplitEntity();

        if ( $entity->dropTable() === false ) {

            $this->e->notice( sprintf( 'Drop table %s failed', $entity->getTableName() ) );

            return false;
        }

        return true;
    }
}

?>
