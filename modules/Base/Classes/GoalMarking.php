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
 * is met by the first_visit; one whose trigger is page_view only by the page
 * view, even though the materialized events beside it carry the same page.
 *
 * A GOAL EVENT IS A ROW OF ITS OWN, named by the definition's name and flagged
 * is_goal_event = 1, materialized from the event that met it the way
 * session_start is from a page view. The event that met it stays an ordinary
 * event with is_goal_event = 0: flagging both would count it twice. So a
 * definition's goal events are the rows under its name, and an event meeting
 * two definitions adds two.
 *
 * HOW OFTEN, by the definition's count mode (GoalEventDefinition::countMode()).
 * Once per event adds one for every event that meets it. Once per session adds
 * none when the session already has a goal event of that name: in this set, or
 * in raw (alreadyInSession()). Two beacons of one session stored at the same
 * moment can each find none there and both add one. That race is accepted
 * rather than closed with a lock per session on the ingest path.
 *
 * WHAT IT IS WORTH, in `value` with `currency` beside it (valueOf()).
 *
 * TESTED AGAINST THE ROW EACH EVENT WILL BECOME (EventRawHandlers::rowFor), not
 * the raw properties: conditions name columns, and the row is where a value
 * becomes what its column holds.
 *
 * AT INGEST, NOT IN THE PASS, and the difference is how many times a partition
 * is rebuilt: here it is N predicates against values already in memory, once per
 * event, ever. What that costs is retroactivity -- a definition made today does
 * not mark yesterday.
 */
class GoalMarking {

    /**
     * site_id => list of array( 'definition' => GoalEventDefinition, 'conditions' => array )
     *
     * Ingest runs this for every row of every beacon, so the definitions and
     * their conditions are read ONCE per site per process. Both halves matter:
     * loadConditions() is a query, and matching used to call it per definition
     * per event -- a query per beacon per definition, which is the shape that
     * makes a tracker endpoint slow. The old docblock claimed this was already
     * the case because the definition ENTITIES were memoised; their conditions
     * were not.
     */
    private static $goals = array();

    /**
     * Judge every event in the set, and add a goal event for each definition met.
     *
     * @param  array $events  the set from Ingest::TRACKING_EVENTS_PRE_SAVE
     * @return array          the same set, each marked 0, and a goal event marked
     *                        1 per definition met
     */
    public static function mark( $events ) {

        if ( ! is_array( $events ) ) {

            return $events;
        }

        // name => the goal event, and name => the row of the event that met it
        $goalEvents = array();
        $triggers   = array();

        // name => true, for the names whose definition counts once per session
        $perSession = array();

        foreach ( $events as $event ) {

            if ( ! is_object( $event ) ) {

                continue;
            }

            /*
             * Set either way rather than only on a match: this is the authority,
             * so a value arriving from anywhere else -- a materialized event
             * built before this ran -- does not survive a run that disagrees.
             * value likewise: only a goal event carries one.
             */
            $event->set( 'is_goal_event', 0 );
            $event->delete( 'value' );

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
                 * One row per NAME in the set, whatever the count mode. Every row
                 * of one beacon shares its ts, so two of the same name would
                 * derive one id and the set's insert would fail on its own key,
                 * losing the beacon.
                 */
                if ( isset( $goalEvents[ $name ] ) ) {

                    continue;
                }

                $goalEvents[ $name ] = self::goalEvent( $event, $goal['definition'], $row );
                $triggers[ $name ]   = $row;

                if ( $goal['definition']->countMode()
                     === \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION ) {

                    $perSession[ $name ] = true;
                }
            }
        }

        if ( $perSession ) {

            foreach ( self::alreadyInSession( $triggers, array_keys( $perSession ) ) as $name ) {

                unset( $goalEvents[ $name ] );
            }
        }

        return array_merge( $events, array_values( $goalEvents ) );
    }

    /**
     * The goal event an event adds: the event that met the definition, under
     * the definition's name, carrying what it is worth.
     *
     * @param  object $trigger     the event that met the definition
     * @param  \OWA\Module\Base\Entity\GoalEventDefinition $definition
     * @param  array  $row         the row the trigger will become
     * @return object
     */
    public static function goalEvent( $trigger, $definition, array $row ) {

        $goalEvent = TrackingEventHelpers::materialize( $trigger, (string) $definition->get( 'name' ) );
        $goalEvent->set( 'is_goal_event', 1 );

        $value = self::valueOf( $definition, $row );

        if ( $value ) {

            $goalEvent->set( 'value', $value['value'] );
            $goalEvent->set( 'currency', $value['currency'] );
        }

        return $goalEvent;
    }

    /**
     * What one goal event is worth, in minor units, and the currency they are of.
     *
     *   - the definition's value, when it is above 0, in the currency the
     *     Property's reports format money in (Currency::ofProperty()) -- the one
     *     an author typing a value is reading reports in;
     *   - else, when a purchase met it, that purchase's revenue and currency;
     *   - else nothing.
     *
     * A value is never stored without a currency: minor units of an unknown
     * currency cannot be shown or summed.
     *
     * @param  \OWA\Module\Base\Entity\GoalEventDefinition $definition
     * @param  array $row  the row of the event that met it
     * @return array|null  array( 'value' => int, 'currency' => string )
     */
    public static function valueOf( $definition, array $row ) {

        $cents = (int) $definition->get( 'value' );

        if ( $cents > 0 ) {

            $currency = Currency::normalize( Currency::ofProperty( (string) $definition->get( 'property_id' ) ) );
            $currency = $currency !== '' ? $currency : 'USD';

            // The definition holds hundredths (decimalToCents()); the row, the currency's minor units.
            return array( 'value' => Currency::toMinorUnits( $cents / 100, $currency ), 'currency' => $currency );
        }

        $currency = Currency::normalize( $row['currency'] ?? '' );

        if ( ( $row['event_type'] ?? '' ) === 'purchase'
             && is_numeric( $row['revenue'] ?? null ) && $currency !== '' ) {

            return array( 'value' => (int) $row['revenue'], 'currency' => $currency );
        }

        return null;
    }

    /**
     * Which of these names the session already has a goal event under.
     *
     * Read from raw only when a once-per-session definition was met, never per
     * beacon otherwise: one query for the set, by session_id (indexed) and
     * bounded by yyyymmdd, the partition key, so it reads the partitions the
     * session can be in rather than every one.
     *
     * THE BOUNDS. yyyymmdd is the server's ts as a date in the install's zone
     * (TrackingEventHelpers::deriveYyyymmdd(), date() under the zone Settings
     * sets), and this runs in the same process, so date() here gives the same
     * day for the same instant. The upper bound is this event's day. The lower
     * is the day the session started: session_start_ts, the tracker's sts, in
     * seconds. A session that started at 23:50 and is still going at 00:10 is
     * found across the two days.
     *
     * sts is the VISITOR'S clock, so it is trusted only as far as one day before
     * this event. A clock behind by a year would otherwise send this lookup
     * across a year of partitions on every such beacon. A session is ended by
     * thirty minutes idle, so one lasting more than a day is a clock or a bot,
     * and the cost of being wrong about it is a second goal event.
     *
     * The race with a beacon of the same session landing at the same moment is
     * the class docblock's.
     *
     * REDELIVERY. A retried or repeated beacon finds its own goal event here
     * and so drops it. The set it comes out as differs from the first time
     * only after its first row, and the write's idempotence check reads the
     * first row (EventRawHandlers::alreadyIngested()), which is the incoming
     * event's.
     *
     * @param  array    $triggers  name => the row of the event that met it
     * @param  string[] $names     the once-per-session names among them
     * @return string[]
     */
    protected static function alreadyInSession( array $triggers, array $names ) {

        $found = array();

        // By session: a set is one beacon, so this is one query in practice.
        $sessions = array();

        foreach ( $names as $name ) {

            $row = $triggers[ $name ];
            $key = $row['site_id'] . '|' . $row['visitor_id'] . '|' . $row['session_id'];

            $sessions[ $key ]['row']     = $row;
            $sessions[ $key ]['names'][] = $name;
        }

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $db  = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $sessions as $session ) {

            $row = $session['row'];

            list( $from, $to ) = self::sessionDays( $row );

            $stored = (array) $db->get_results( sprintf(
                'SELECT DISTINCT event_type FROM %s WHERE session_id = ? AND visitor_id = ? AND site_id = ?'
              . ' AND yyyymmdd BETWEEN ? AND ? AND is_goal_event = 1 AND event_type IN (%s)',
                $raw, implode( ', ', array_fill( 0, count( $session['names'] ), '?' ) ) ),
                array_merge(
                    array( $row['session_id'], $row['visitor_id'], (string) $row['site_id'], $from, $to ),
                    $session['names'] ) );

            foreach ( $stored as $r ) {

                $found[] = (string) ( (array) $r )['event_type'];
            }
        }

        return $found;
    }

    /**
     * The first and last yyyymmdd a session's rows can be in, as of this row.
     * See alreadyInSession().
     *
     * @param  array $row
     * @return int[] array( from, to )
     */
    public static function sessionDays( array $row ) {

        $to = (int) $row['yyyymmdd'];

        $now   = intdiv( (int) $row['ts'], 1000000 );
        $start = (int) ( $row['session_start_ts'] ?? 0 );

        if ( $start <= 0 || $start > $now ) {

            $start = $now;
        }

        $from = (int) date( 'Ymd', max( $start, $now - 86400 ) );

        return array( min( $from, $to ), $to );
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
     * marked by it. In a request the memo is exactly right: definitions do not
     * change while a beacon is being stored.
     */
    public static function forget() {

        self::$goals = array();
    }
}

?>
