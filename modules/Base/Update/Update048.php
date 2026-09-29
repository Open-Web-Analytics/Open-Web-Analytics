<?php

namespace OWA\Module\Base\Update;

/**
 * Delete goal event conditions that belong to no goal event.
 *
 * Entity::delete() removes one row from one table, and nothing overrode it for
 * a goal event -- so every goal event ever deleted left its conditions behind
 * with nothing able to reach them. Entity\GoalEvent::delete() cascades now; this
 * clears what accumulated before it did.
 *
 * MEASURED, not suspected. On the test install at the time of writing: 40
 * condition rows, of which 31 pointed at a goal event that no longer existed --
 * left by e2e fixtures that create a goal, delete it, and had no way to take the
 * conditions with it. The demo install had one goal event and its one condition,
 * with no orphans, which is what a hand-made goal that nobody deleted looks
 * like.
 *
 * WHY IT MATTERS BEYOND TIDINESS. A condition row carries no property_id and no
 * site_id: it is reachable only through its goal event. An orphan is therefore
 * invisible to every screen and every report, and stays invisible while the ids
 * are 64-bit random -- until one collides with a new goal event's id, at which
 * point a condition somebody deleted years ago starts deciding what converts.
 *
 * NO down(). A delete of unreachable rows has no inverse: there is nothing to
 * restore them to, and re-creating them would re-create the defect. down()
 * returns true and says so rather than pretending to reverse it.
 *
 * IDEMPOTENT: after it runs there are no orphans, so a second run deletes
 * nothing. That also means it is safe on an install where the cascade has always
 * been in place.
 */
class Update048 extends \OWA\Core\Update {

    var $schema_version = 48;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $goal      = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $goal->getTableName() );
        $db->selectColumn( 'id' );

        $live = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $live[ (string) $row['id'] ] = true;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $condition->getTableName() );
        $db->selectColumn( 'id, goal_event_id' );

        $orphans = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! isset( $live[ (string) $row['goal_event_id'] ] ) ) {

                $orphans[] = $row['id'];
            }
        }

        /*
         * ONE AT A TIME, THROUGH THE ENTITY. goal_event_condition is cachable,
         * so a raw DELETE would leave every deleted row still answering from
         * cache -- the migration entity-cache trap, in the direction where the
         * data is gone and the cache is not.
         */
        foreach ( $orphans as $id ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

            if ( $entity->delete( $id ) === false ) {

                $this->e->notice( sprintf(
                    'Deleting orphaned goal event condition %s failed', $id ) );

                return false;
            }
        }

        if ( $orphans ) {

            $this->e->notice( sprintf(
                'Deleted %d goal event condition(s) belonging to no goal event',
                count( $orphans ) ) );
        }

        return true;
    }

    function down() {

        $this->e->notice(
            'Nothing to reverse: the rows this deleted were unreachable, and '
            . 're-creating them would re-create the defect.' );

        return true;
    }
}

?>
