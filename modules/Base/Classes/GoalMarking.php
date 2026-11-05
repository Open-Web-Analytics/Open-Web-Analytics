<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A goal event for each goal event definition an event met (PLAN 1.3.2).
 *
 * A CALLBACK ON Ingest::TRACKING_EVENTS_PRE_SAVE, at priority 100 -- after the
 * materializers at 10 -- so it is handed every event one beacon will be saved as
 * and judges each on its own values. A definition whose trigger is first_visit
 * converts on the first_visit; one whose trigger is page_view converts only on
 * the page view, even though the materialized events beside it carry the same
 * page.
 *
 * A CONVERSION IS A ROW OF ITS OWN, named by the definition's name and
 * flagged is_goal_event = 1, materialized from the event that met it the way
 * session_start is from a page view. The event that met it stays an ordinary
 * event with is_goal_event = 0: flagging both would count the conversion twice.
 * So a definition's conversions are the rows under its name, and an event
 * meeting two definitions adds two.
 *
 * TESTED AGAINST THE ROW EACH EVENT WILL BECOME (EventRawHandlers::rowFor), not
 * the raw properties: conditions name columns, and the row is where a value
 * becomes what its column holds.
 *
 * AT INGEST, NOT IN THE PASS, and the difference is how many times a partition
 * is rebuilt: here it is N predicates against values already in memory, once per
 * event, ever. What that costs is retroactivity -- a goal defined today does not
 * mark yesterday.
 */
class GoalMarking {

    /**
     * site_id => list of array( 'definition' => GoalEventDefinition, 'conditions' => array )
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
     * Judge every event in the set, and add a row for each definition met.
     *
     * @param  array $events  the set from Ingest::TRACKING_EVENTS_PRE_SAVE
     * @return array          the same set, each marked 0, and a row marked 1 per
     *                        definition met
     */
    public static function mark( $events ) {

        if ( ! is_array( $events ) ) {

            return $events;
        }

        $conversions = array();

        foreach ( $events as $event ) {

            if ( ! is_object( $event ) ) {

                continue;
            }

            /*
             * Set either way rather than only on a match: this is the authority,
             * so a value arriving from anywhere else -- a materialized event
             * built before this ran -- does not survive a run that disagrees.
             */
            $event->set( 'is_goal_event', 0 );

            $goals = self::goalsFor( (string) $event->getSiteId() );

            if ( ! $goals ) {

                continue;
            }

            $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $event );

            if ( ! $row ) {

                continue;
            }

            foreach ( $goals as $goal ) {

                if ( ! $goal['definition']->matchesRow( $row, $goal['conditions'] ) ) {

                    continue;
                }

                $name = (string) $goal['definition']->get( 'name' );

                /*
                 * One row per NAME in the set. Every row of one beacon
                 * shares its ts, so two of the same name would derive one id and
                 * the set's insert would fail on its own key, losing the beacon.
                 */
                if ( ! isset( $conversions[ $name ] ) ) {

                    $conversions[ $name ] = self::conversion( $event, $name );
                }
            }
        }

        return array_merge( $events, array_values( $conversions ) );
    }

    /**
     * The row a conversion adds: the event that met the definition, under the
     * definition's name.
     *
     * @param  object $trigger
     * @param  string $name     the definition's name
     * @return object
     */
    public static function conversion( $trigger, $name ) {

        $row = TrackingEventHelpers::materialize( $trigger, $name );
        $row->set( 'is_goal_event', 1 );

        return $row;
    }

    /**
     * The active definitions of the Property this Profile belongs to, with their
     * conditions.
     *
     * READ FROM THE TABLE, not through GoalManager::getActiveGoals(). That
     * method answers in the 1.x goal shape, keyed by SLOT NUMBER, and it seeds
     * slots 1..20 and keeps only the goals whose number is one of them -- so a
     * definition created without a slot, which is what the current screen makes,
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

        if ( $site_id === '' ) {

            return self::$goals[ $site_id ];
        }

        $property_id = \OWA\Module\Base\Entity\GoalEventDefinition::propertyFor( $site_id );

        /*
         * No Property means NO definitions. Db::where() drops a clause whose
         * value is empty instead of matching nothing, so an unguarded query here
         * would hand this Profile every Property's definitions -- a
         * cross-Property leak, not an empty list.
         */
        if ( ! $property_id ) {

            return self::$goals[ $site_id ];
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $property_id );
        $db->where( 'is_active', 1 );

        foreach ( (array) $db->getAllRows() as $row ) {

            $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $definition->setProperties( $row );

            $conditions = $definition->loadConditions();

            /*
             * A definition with no conditions is dropped here rather than
             * carried and refused per row. matchesRow() answers false for it
             * anyway -- an empty rule is not a universal one -- so this only
             * saves the walk. Not on a custom event, whose name is its
             * condition.
             */
            if ( ! $conditions
                 && ! \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $definition->get( 'trigger_event_type' ) ) ) {

                continue;
            }

            self::$goals[ $site_id ][] = array(
                'definition' => $definition,
                'conditions' => $conditions,
            );
        }

        return self::$goals[ $site_id ];
    }

    /**
     * Drop the memo.
     *
     * For tests, which create a definition and then expect the next row to be
     * marked by it. In a request the memo is exactly right: goals do not change
     * while a beacon is being stored.
     */
    public static function forget() {

        self::$goals = array();
    }
}

?>
