<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Goal groups and start conditions are removed.
 *
 * GOAL GROUPS were 1.x's way of arranging numbered goals into metric-set tabs:
 * each group with an active goal became a tab measuring goal{N}Completions and
 * goalValueAll. Neither metric exists on v2, so every tab they produced asked
 * for metrics that cannot resolve. The goal_group column and the goal_groups
 * setting that labelled them go.
 *
 * START CONDITIONS (role = 'start') decided whether a visitor BEGAN a goal. They
 * fed goal_N_start on 1.x's session row and the goalNStarts metrics; nothing on
 * v2 reads them. Where a visitor begins a path is a funnel visualization's
 * question now. The rows are DELETED BEFORE the role column is dropped: without
 * the column a start condition would read as a match condition, and change what
 * the goal event marks.
 *
 * AN UPDATE, because both columns shipped: owa_goal_event and
 * owa_goal_event_condition are 1.13's (Update025).
 *
 * Update025 creates both tables from the current entities, so on an upgrade
 * from before 1.13 neither column exists by the time this runs, and each drop is
 * guarded.
 */
class Update056 extends \OWA\Core\Update {

    var $schema_version = 56;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $db        = \OWA\Core\CoreAPI::dbSingleton();
        $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        if ( $this->hasColumn( $condition, 'role' ) ) {

            $db->query( sprintf( "DELETE FROM %s WHERE role = 'start'",
                $condition->getTableName() ) );
        }

        if ( $this->dropColumnIfPresent( $condition, 'role' ) === false ) {

            $this->e->notice( 'Dropping owa_goal_event_condition.role failed' );

            return false;
        }

        if ( $this->dropColumnIfPresent( $goalEvent, 'goal_group' ) === false ) {

            $this->e->notice( 'Dropping owa_goal_event.goal_group failed' );

            return false;
        }

        $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = 'goal_groups'",
            \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName() ) );

        return true;
    }

    /**
     * The columns come back; what was in them does not.
     *
     * The shape is the inverse this can offer. Start conditions, group
     * assignments and group labels were deleted, and re-creating them would be
     * inventing them. The definitions are spelled out because the entities no
     * longer declare either column.
     */
    function down() {

        if ( $this->addColumnIfMissing( \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' ),
                'goal_group', OWA_DTD_VARCHAR255 ) === false ) {

            return false;
        }

        $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        if ( $this->addColumnIfMissing( $condition, 'role', OWA_DTD_VARCHAR255 ) === false ) {

            return false;
        }

        // role carried an index; the inverse restores it.
        return \OWA\Core\CoreAPI::dbSingleton()->addIndex(
            $condition->getTableName(), 'role' ) !== false;
    }

    private function hasColumn( $entity, $column ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'", $entity->getTableName(), $column ) );
    }
}

?>
