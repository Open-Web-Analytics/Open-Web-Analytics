<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A goal event's conversion becomes a row of its own (PLAN 1.3.2).
 *
 * Until now ingest flagged the event that met a goal event, is_goal_event = 1,
 * so the stored rows could not say WHICH goal event converted: the Goals report
 * had a total and nothing to group it by. A conversion is now a row named by
 * the goal event (Classes\GoalMarking), and the event that met it keeps 0.
 *
 * up():
 *   - owa_goal_event gets event_name, the name its conversion rows carry, made
 *     from each goal event's name (GoalEvent::eventNameFrom()). One that is not
 *     a usable event name, or that another goal event of the Property already
 *     has, gets a number on the end.
 *   - Each flagged row in raw gets a conversion row for every goal event of its
 *     Property it meets, active or not, and its flag goes to 0. A flagged row
 *     that meets none -- its goal event was deleted or changed -- keeps its
 *     flag and is counted in the notice.
 *
 * The conversion rows' created_at is now, but a routine cube build only looks
 * back to where it is still catching up, so the days they land in are rebuilt
 * by `cmd=cube-rebuild from=<the first one>`, which the notice names.
 *
 * NOT CLI-ONLY. It touches the rows that were flagged, in batches.
 */
class Update075 extends \OWA\Core\Update {

    var $schema_version = 75;

    var $is_cli_mode_required = false;

    const BATCH = 500;

    function up( $force = false ) {

        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        if ( $this->addColumnIfMissing( $goal, 'event_name' ) === false ) {

            $this->e->notice( sprintf( 'Adding %s.event_name failed', $goal->getTableName() ) );

            return false;
        }

        if ( ! $this->nameGoalEvents() ) {

            return false;
        }

        return $this->convertFlaggedRows();
    }

    /** The exact inverse: each conversion row flags the event it was made from again, and goes. */
    function down() {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        $after = array( 0, 0 );

        while ( $rows = $this->batch( 'r.event_type NOT IN (' . $this->firstClass() . ')', $after ) ) {

            foreach ( $rows as $row ) {

                $after = array( (int) $row['yyyymmdd'], $row['id'] );
                $goal  = $this->goalNamed( (string) $row['site_id'], (string) $row['event_type'] );

                if ( $goal && $goal->get( 'trigger_event_type' ) ) {

                    if ( $db->query( sprintf(
                            'UPDATE %s SET is_goal_event = 1 WHERE yyyymmdd = ? AND site_id = ? AND visitor_id = ?'
                          . ' AND session_id = ? AND ts = ? AND event_type = ?', $raw ),
                            array( (int) $row['yyyymmdd'], $row['site_id'], $row['visitor_id'], $row['session_id'],
                                $row['ts'], $goal->get( 'trigger_event_type' ) ) ) === false ) {

                        return false;
                    }
                }

                if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ? AND yyyymmdd = ?', $raw ),
                        array( $row['id'], (int) $row['yyyymmdd'] ) ) === false ) {

                    return false;
                }
            }
        }

        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        if ( $this->dropColumnIfPresent( $goal, 'event_name' ) === false ) {

            $this->e->notice( sprintf( 'Dropping %s.event_name failed', $goal->getTableName() ) );

            return false;
        }

        return true;
    }

    /** Give every goal event without one an event name, unique within its Property. */
    private function nameGoalEvents() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )->getTableName();

        $taken = array();

        foreach ( (array) $db->get_results( sprintf(
                "SELECT property_id, event_name FROM %s WHERE event_name IS NOT NULL AND event_name <> ''", $table ) ) as $row ) {

            $taken[ (string) $row['property_id'] ][ (string) $row['event_name'] ] = true;
        }

        foreach ( (array) $db->get_results( sprintf(
                "SELECT id, property_id, name FROM %s WHERE event_name IS NULL OR event_name = '' ORDER BY id", $table ) ) as $row ) {

            $property = (string) $row['property_id'];
            $base     = \OWA\Module\Base\Entity\GoalEvent::eventNameFrom( $row['name'] );
            $name     = $base;

            for ( $n = 2; \OWA\Module\Base\Entity\GoalEvent::eventNameProblem( $name ) !== '' || isset( $taken[ $property ][ $name ] ); $n++ ) {

                $name = substr( $base, 0, 40 - strlen( '_' . $n ) ) . '_' . $n;
            }

            $taken[ $property ][ $name ] = true;

            if ( $db->query( sprintf( 'UPDATE %s SET event_name = ? WHERE id = ?', $table ),
                    array( $name, $row['id'] ) ) === false ) {

                $this->e->notice( sprintf( 'Naming goal event %s failed', $row['id'] ) );

                return false;
            }
        }

        // The goal events were read through the entity cache before this.
        \OWA\Module\Base\Classes\GoalMarking::forget();

        return true;
    }

    /** Store each flagged event's conversion rows and clear its flag. */
    private function convertFlaggedRows() {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        $after     = array( 0, 0 );
        $converted = 0;
        $unmatched = 0;
        $first     = null;

        while ( $rows = $this->batch( 'r.event_type IN (' . $this->firstClass() . ')', $after ) ) {

            foreach ( $rows as $row ) {

                $after = array( (int) $row['yyyymmdd'], $row['id'] );
                $names = array();

                foreach ( $this->goalEventsOf( (string) $row['site_id'] ) as $goal ) {

                    if ( $goal['event']->matchesRow( $row, $goal['conditions'] ) ) {

                        $names[ $goal['event']->eventName() ] = true;
                    }
                }

                if ( ! $names ) {

                    $unmatched++;

                    continue;
                }

                foreach ( array_keys( $names ) as $name ) {

                    if ( ! \OWA\Module\Base\Classes\Migration\GoalMigrator::storeConversion( $row, $name ) ) {

                        return false;
                    }
                }

                if ( $db->query( sprintf( 'UPDATE %s SET is_goal_event = 0 WHERE id = ? AND yyyymmdd = ?', $raw ),
                        array( $row['id'], (int) $row['yyyymmdd'] ) ) === false ) {

                    return false;
                }

                $converted++;
                $first = $first === null ? (int) $row['yyyymmdd'] : min( $first, (int) $row['yyyymmdd'] );
            }
        }

        $this->e->notice( sprintf( 'Goal conversions: %d flagged event(s) now have conversion rows; %d meet no goal event and keep their flag.',
            $converted, $unmatched ) );

        if ( $first !== null ) {

            $this->e->notice( sprintf( 'Rebuild the cube for the days they are on: php cli.php cmd=cube-rebuild from=%d', $first ) );
        }

        return true;
    }

    /**
     * The next flagged rows after a (yyyymmdd, id) position, in that order.
     *
     * @param  string $which  SQL on r.event_type
     * @param  array  $after  array( yyyymmdd, id )
     * @return array
     */
    private function batch( $which, array $after ) {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        return array_map( function ( $r ) { return (array) $r; }, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            'SELECT r.* FROM %s r WHERE r.is_goal_event = 1 AND %s'
          . ' AND (r.yyyymmdd > ? OR (r.yyyymmdd = ? AND r.id > ?)) ORDER BY r.yyyymmdd, r.id LIMIT %d',
            $raw, $which, self::BATCH ),
            array( $after[0], $after[0], $after[1] ) ) );
    }

    /** The first-class event names, quoted for an IN list: a trigger is one, a conversion row is not. */
    private function firstClass() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        return implode( ', ', array_map( function ( $name ) use ( $db ) {

            return "'" . $db->prepare( $name ) . "'";

        }, \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames() ) );
    }

    /** @var array site_id => list of array( 'event' => GoalEvent, 'conditions' => array ) */
    private $goals = array();

    /**
     * Every goal event of a Profile's Property, active or not, with its
     * conditions: a row flagged while one was active converted on it.
     */
    private function goalEventsOf( $site_id ) {

        if ( isset( $this->goals[ $site_id ] ) ) {

            return $this->goals[ $site_id ];
        }

        $this->goals[ $site_id ] = array();

        $property_id = \OWA\Module\Base\Entity\GoalEvent::propertyFor( $site_id );

        // No Property is no goal events; an empty where() would read them all.
        if ( ! $property_id ) {

            return $this->goals[ $site_id ];
        }

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' )->getTableName();

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
                'SELECT * FROM %s WHERE property_id = ? ORDER BY id', $table ), array( (string) $property_id ) ) as $row ) {

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $goal->setProperties( (array) $row );

            $conditions = $goal->loadConditions();

            if ( $conditions ) {

                $this->goals[ $site_id ][] = array( 'event' => $goal, 'conditions' => $conditions );
            }
        }

        return $this->goals[ $site_id ];
    }

    /** The goal event of a Profile's Property stored under an event name, or null. */
    private function goalNamed( $site_id, $event_name ) {

        foreach ( $this->goalEventsOf( $site_id ) as $goal ) {

            if ( $goal['event']->eventName() === $event_name ) {

                return $goal['event'];
            }
        }

        return null;
    }
}

?>
