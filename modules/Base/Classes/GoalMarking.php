<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Whether a stored row met a goal condition.
 *
 * A LISTENER AT Ingest::STORE_POST, not a step inside the raw handler, and the
 * move buys two things.
 *
 * THE ROW IS COMPLETE THERE. Marking used to happen inside the row literal, from
 * the EVENT -- so it ran before deviceColumns() and taggedColumns() were merged
 * in, and neither device_type nor any tagged_* value was in scope when
 * conditions were matched. Those are exactly the things an author reaches for:
 * "a signup from mobile", "a purchase from organic". A goal declared on one of
 * them matched nothing and said nothing.
 *
 * AND THE HANDLER NO LONGER KNOWS WHAT A GOAL IS. It assembles a row and hands
 * it to the point; goals are one listener there. Anything else that wants to
 * decide something about a complete row attaches the same way, and ingest does
 * not grow a step for each.
 *
 * ONE FLAG, NOT ONE PER GOAL. An event meeting two goals is still one event, and
 * goalConversions counts events -- so the first match ends the walk. Which goal
 * converted is a question for Classes\GoalEventPredicate against the stored
 * rows, not for a column per slot the way owa_session carried goal_1..goal_N.
 *
 * AT INGEST, NOT IN THE PASS, and the difference is how many times a partition
 * is rebuilt: here it is N predicates against an array already in memory, once
 * per row, ever. What that costs is retroactivity -- a goal defined today does
 * not mark yesterday -- and GA4 behaves the same way for the same reason.
 */
class GoalMarking {

    /**
     * site_id => list of array( 'event' => GoalEvent, 'conditions' => array )
     *
     * Ingest runs this for every row of every beacon, so the goals and their
     * conditions are read ONCE per site per process. Both halves matter:
     * loadConditions() is a query, and matching used to call it per goal per
     * event -- a query per beacon per goal, which is the shape that makes a
     * tracker endpoint slow. The old docblock claimed this was already the case
     * because the goal ENTITIES were memoised; their conditions were not.
     */
    private static $goals = array();

    /**
     * Mark one row.
     *
     * @param  array  $row    the assembled raw row, from Ingest::STORE_POST
     * @param  object $event  the beacon, as unchained context (unused here: the
     *                        row is the subject, and reading the event again is
     *                        how the old version came to match against values
     *                        the row did not have)
     * @return array
     */
    public static function mark( $row, $event = null ) {

        if ( ! is_array( $row ) ) {

            return $row;
        }

        $site_id = (string) ( $row['site_id'] ?? '' );

        if ( $site_id === '' ) {

            return $row;
        }

        /*
         * Set either way rather than only on a match. The handler writes 0 into
         * the literal so the column is never absent from a NOT NULL insert, and
         * this is the authority -- so a 1 arriving from anywhere else does not
         * survive a run that disagrees with it.
         */
        $row['is_goal_event'] = 0;

        foreach ( self::goalsFor( $site_id ) as $goal ) {

            if ( $goal['event']->matchesRow( $row, $goal['conditions'] ) ) {

                $row['is_goal_event'] = 1;

                break;
            }
        }

        return $row;
    }

    /**
     * The active goal events of the Property this Profile belongs to, with their
     * conditions.
     *
     * READ FROM THE TABLE, not through GoalManager::getActiveGoals(). That
     * method answers in the 1.x goal shape, keyed by SLOT NUMBER, and it seeds
     * slots 1..20 and keeps only the goals whose number is one of them -- so a
     * goal event created without a slot, which is what the current screen makes,
     * was never marked at ingest at all. Reading the table by property_id and
     * is_active has no opinion about slots.
     *
     * @param  string $site_id
     * @return array
     */
    protected static function goalsFor( $site_id ) {

        if ( isset( self::$goals[ $site_id ] ) ) {

            return self::$goals[ $site_id ];
        }

        self::$goals[ $site_id ] = array();

        $property_id = GoalManager::propertyFor( $site_id );

        /*
         * No Property means NO goal events. Db::where() drops a clause whose
         * value is empty instead of matching nothing, so an unguarded query here
         * would hand this Profile every Property's goal events -- a
         * cross-Property leak, not an empty list.
         */
        if ( ! $property_id ) {

            return self::$goals[ $site_id ];
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $property_id );
        $db->where( 'is_active', 1 );

        foreach ( (array) $db->getAllRows() as $row ) {

            $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $goalEvent->setProperties( $row );

            $conditions = $goalEvent->loadConditions();

            /*
             * A goal event with no conditions is dropped here rather than
             * carried and refused per row. matchesRow() answers false for it
             * anyway -- an empty rule is not a universal one -- so this only
             * saves the walk.
             */
            if ( ! $conditions ) {

                continue;
            }

            self::$goals[ $site_id ][] = array(
                'event'      => $goalEvent,
                'conditions' => $conditions,
            );
        }

        return self::$goals[ $site_id ];
    }

    /**
     * Drop the memo.
     *
     * For tests, which create a goal event and then expect the next row to be
     * marked by it. In a request the memo is exactly right: goals do not change
     * while a beacon is being stored.
     */
    public static function forget() {

        self::$goals = array();
    }
}

?>
