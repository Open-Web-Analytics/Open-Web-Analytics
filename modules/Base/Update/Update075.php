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
 *   - A goal event's name becomes the event name its conversion rows carry, so
 *     one that is not a custom event name is renamed
 *     (GoalEventDefinition::nameFrom()).
 *     One that another goal event of the Property already has, or that OWA
 *     collects, gets a number on the end. The names it had are kept in the
 *     goal_event_names_before_075 setting for down().
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
 *
 * The goal tables are read under the names they had before Update076
 * (namedBefore076()), which is what an upgrade meets here.
 */
class Update075 extends \OWA\Core\Update {

    var $schema_version = 75;

    

    var $down_does_not_restore = 'exactly which rows were flagged before: re-flagging by day, site, visitor, session, time and trigger can flag more, or delete rows written after the upgrade.';

    var $is_cli_mode_required = false;

    const BATCH = 500;

    /** The setting that keeps each renamed goal event's name: id => array( 'was', 'now' ). */
    const BEFORE = 'goal_event_names_before_075';

    function up( $force = false ) {

        $this->goals = array();

        if ( ! $this->renameGoalEvents() ) {

            return false;
        }

        return $this->convertFlaggedRows();
    }

    /**
     * The inverse of up(): each conversion row flags the event it was made from
     * again, and goes; then the goal events get back the names they had.
     *
     * On an upgrade from 1.x that includes the conversion rows Update062
     * stored, not only the ones up() made: raw has no marker that tells the two
     * apart, so both become flagged trigger rows. up() converts them all again,
     * so down() then up() ends where it started. Rewinding past Update062 as
     * well does not: GoalMigrator::revertSite() deletes Update062's conversion
     * rows by name, and they are no longer there to find.
     */
    function down() {

        $this->goals = array();

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

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076()->getTableName();

        foreach ( (array) \OWA\Core\CoreAPI::getSetting( 'base', self::BEFORE ) as $id => $names ) {

            // Matched on the name it was given, so a goal event made since is left alone.
            if ( $db->query( sprintf( 'UPDATE %s SET name = ? WHERE id = ? AND name = ?', $table ),
                    array( $names['was'], $id, $names['now'] ) ) === false ) {

                return false;
            }
        }

        \OWA\Core\CoreAPI::persistSetting( 'base', self::BEFORE, array() );
        \OWA\Module\Base\Classes\GoalMarking::forget();

        return true;
    }

    /**
     * Rename every goal event whose name is not a usable event name, unique
     * within its Property (GoalEventDefinition::eventNames()). On an upgrade
     * from 1.x the conversion rows Update062 stored already carry these names.
     */
    private function renameGoalEvents() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076()->getTableName();
        $rows  = array_map( function ( $r ) { return (array) $r; }, (array) $db->get_results(
            sprintf( 'SELECT id, property_id, name FROM %s ORDER BY id', $table ) ) );
        $was   = array_column( $rows, 'name', 'id' );

        $before = (array) \OWA\Core\CoreAPI::getSetting( 'base', self::BEFORE );

        foreach ( \OWA\Module\Base\Entity\GoalEventDefinition::eventNames( $rows ) as $id => $name ) {

            $before[ $id ] = array( 'was' => (string) $was[ $id ], 'now' => $name );

            // Kept before the rename, so a run that stops between the two can still be undone.
            \OWA\Core\CoreAPI::persistSetting( 'base', self::BEFORE, $before );

            if ( $db->query( sprintf( 'UPDATE %s SET name = ? WHERE id = ?', $table ), array( $name, $id ) ) === false ) {

                $this->e->notice( sprintf( 'Renaming goal event %s failed', $id ) );

                return false;
            }

            $this->e->notice( sprintf( 'Goal event "%s" is now %s.', $was[ $id ], $name ) );
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

                        $names[ (string) $goal['event']->get( 'name' ) ] = true;
                    }
                }

                if ( ! $names ) {

                    $unmatched++;

                    continue;
                }

                foreach ( array_keys( $names ) as $name ) {

                    if ( ! \OWA\Module\Base\Classes\Migration\GoalMigrator::storeGoalEvent( $row, $name ) ) {

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

        $this->e->notice( sprintf( 'Goal conversions: %d flagged %s conversion rows; %d %s no goal event and %s their flag.',
            $converted, \OWA\Core\Lib::plural( $converted, 'event now has', 'events now have' ), $unmatched, \OWA\Core\Lib::plural( $unmatched, 'meets', 'meet' ), \OWA\Core\Lib::plural( $unmatched, 'keeps', 'keep' ) ) );

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

    /** @var array site_id => list of array( 'event' => GoalEventDefinition, 'conditions' => array ) */
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

        $property_id = \OWA\Module\Base\Entity\GoalEventDefinition::propertyFor( $site_id );

        // No Property is no goal events; an empty where() would read them all.
        if ( ! $property_id ) {

            return $this->goals[ $site_id ];
        }

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076()->getTableName();

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
                'SELECT * FROM %s WHERE property_id = ? ORDER BY id', $table ), array( (string) $property_id ) ) as $row ) {

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();
            $goal->setProperties( (array) $row );

            $conditions = $goal->loadConditions();

            if ( $conditions ) {

                $this->goals[ $site_id ][] = array( 'event' => $goal, 'conditions' => $conditions );
            }
        }

        return $this->goals[ $site_id ];
    }

    /** The goal event of a Profile's Property with a name, or null. */
    private function goalNamed( $site_id, $event_name ) {

        foreach ( $this->goalEventsOf( $site_id ) as $goal ) {

            if ( (string) $goal['event']->get( 'name' ) === $event_name ) {

                return $goal['event'];
            }
        }

        return null;
    }
}

?>
