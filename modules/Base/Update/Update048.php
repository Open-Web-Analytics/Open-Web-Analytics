<?php

namespace OWA\Module\Base\Update;

/**
 * Delete goal event conditions that belong to no goal event.
 *
 * Entity\GoalEventDefinition::delete() cascades to its conditions; this clears
 * the conditions left by goal events deleted before it did.
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
 *
 * The goal tables are read under the names they had before Update076
 * (namedBefore076()), which is what an upgrade meets here.
 */
class Update048 extends \OWA\Core\Update {

    public $schema_version = 48;

    

    public $down_does_not_restore = 'the orphaned conditions it deleted, which no goal could reach.';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $goal      = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();
        $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )->namedBefore076();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $goal->getTableName() );
        $db->selectColumn( 'id' );

        $live = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $live[ (string) $row['id'] ] = true;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $condition->getTableName() );
        $db->selectColumn( 'id, goal_event_id' );

        $orphans = [];

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

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' )->namedBefore076();

            if ( $entity->delete( $id ) === false ) {

                $this->e->notice( sprintf(
                    'Deleting orphaned goal event condition %s failed', $id ) );

                return false;
            }
        }

        if ( $orphans ) {

            $this->e->notice( sprintf(
                'Deleted %d goal event %s belonging to no goal event',
                count( $orphans ), \OWA\Core\Lib::plural( count( $orphans ), 'condition', 'conditions' ) ) );
        }

        return true;
    }

    public function down() {

        $this->e->notice(
            'Nothing to reverse: the rows this deleted were unreachable, and '
            . 're-creating them would re-create the defect.' );

        return true;
    }
}
