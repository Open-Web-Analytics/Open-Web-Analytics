<?php

namespace OWA\Module\Base\Update;

/**
 * Goals become goal events, and get a table of their own.
 *
 * Twenty numbered slots lived inside ONE serialized array, all twenty present
 * whether used or not, in a single settings row per Profile. A live install
 * here holds fifteen entries in 2,135 bytes to describe one real goal.
 *
 * That shape cannot be queried or indexed, loses one of two concurrent edits
 * wholesale, and puts a RECORD -- a thing that happened being worth counting --
 * inside a settings blob, which is not what a setting is. The scoped settings
 * table (Update022) did not fix it: it moved the same blob into a different
 * column.
 *
 * The new table is modelled on what v2 needs, not on what 1.x had, so the v2
 * migration reads it rather than reinterpreting it. See GoalEvent.
 *
 * COPIES, does not move. owa_setting keeps its goals rows, so a rollback to the
 * previous release still reads its own goals. They stop being written, not
 * stored.
 */
class Update025 extends \OWA\Core\Update {

    var $schema_version = 25;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        if ( ! $entity->createTable() ) {

            $this->e->notice( 'Creating owa_goal_event failed' );

            return false;
        }

        foreach ( array( 'base.funnel', 'base.funnel_step' ) as $name ) {

            $table = \OWA\Core\CoreAPI::entityFactory( $name );

            if ( ! $table->createTable() ) {

                $this->e->notice( "Creating $name failed" );

                return false;
            }
        }

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $setting->getTableName() );
        $db->selectColumn( 'scope_type, scope_id, name, value' );
        $db->where( 'name', 'goals' );

        $migrated = 0;

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ( $row['scope_type'] ?? '' ) !== 'profile' ) {

                continue;
            }

            foreach ( self::planForProfile( $row ) as $planned ) {

                $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

                /*
                 * A content-derived id, so re-running this migration updates
                 * the same rows rather than duplicating every goal. The slot
                 * number is part of it because that is what made a goal unique
                 * within a Profile.
                 */
                $goalEvent->set( 'id', $goalEvent->generateId(
                    'goal_event:' . $planned['property_id'] . ':' . $planned['goal_number'] ) );

                foreach ( $planned as $column => $value ) {

                    if ( $column === 'steps' || $column === 'site_id' ) {

                        continue;
                    }

                    $goalEvent->set( $column, $value );
                }

                $goalEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

                if ( $goalEvent->create() ) {

                    $migrated++;
                }

                /*
                 * The funnel, if it has one.
                 *
                 * 1.x kept it three levels deep -- details.funnel_steps, inside
                 * the goal, inside the goals array, inside a settings blob.
                 * Dropping it would have been silent: this install has no
                 * funnel goals, so nothing here would have failed.
                 *
                 * It becomes a funnel of its own that NAMES this goal event,
                 * rather than a child of it. A 1.x goal conflated the two, so
                 * the migration is where they separate: the funnel takes the
                 * goal's name because that is what the goal called the path.
                 */
                if ( $planned['steps'] ) {

                    $funnel = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );

                    $funnelId = $funnel->generateId(
                        'funnel:' . $planned['property_id'] . ':' . $planned['goal_number'] );

                    $funnel->set( 'id', $funnelId );
                    $funnel->set( 'property_id', $planned['property_id'] );
                    $funnel->set( 'name', $planned['name'] );
                    $funnel->set( 'goal_event_id', $goalEvent->get( 'id' ) );
                    $funnel->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
                    $funnel->create();

                    foreach ( $planned['steps'] as $plannedStep ) {

                        $row = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

                        $row->set( 'id', $row->generateId(
                            'funnel_step:' . $funnelId . ':' . $plannedStep['step_number'] ) );

                        $row->set( 'funnel_id', $funnelId );

                        foreach ( $plannedStep as $column => $value ) {

                            $row->set( $column, $value );
                        }

                        $row->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
                        $row->create();
                    }
                }
            }
        }

        $this->e->notice( "Migrated $migrated goal(s) into owa_goal_event." );

        return true;
    }

    /**
     * The goal events one Profile's goals blob describes.
     *
     * Pure so it can be tested without a database, and so the rules below are
     * stated once rather than inferred from what the loop happened to do.
     *
     * @param  array $row  a settings row: scope_id and a serialized goals map
     * @return array
     */
    public static function planForProfile( $row ) {

        $goals = @unserialize( (string) ( $row['value'] ?? '' ) );

        if ( ! is_array( $goals ) ) {

            /*
             * One unreadable blob must not stop every other Profile migrating.
             * Same rule as Update022.
             */
            return array();
        }

        $planned = array();

        foreach ( $goals as $number => $goal ) {

            if ( ! is_array( $goal ) ) {

                continue;
            }

            /*
             * EMPTY SLOTS ARE DROPPED, not migrated as empty rows.
             *
             * Nineteen of twenty slots on a typical install are stubs with
             * every field blank -- they exist because the blob was a
             * fixed-length array, not because anyone made them. Carrying them
             * over would reproduce the exact thing this table exists to stop.
             */
            if ( trim( (string) ( $goal['goal_name'] ?? '' ) ) === '' ) {

                continue;
            }

            $details = isset( $goal['details'] ) && is_array( $goal['details'] )
                ? $goal['details'] : array();

            $planned[] = array(
                'site_id'            => $row['scope_id'],
                'property_id'        => self::propertyFor( $row['scope_id'] ),
                'name'               => (string) $goal['goal_name'],
                'goal_number'        => (int) ( $goal['goal_number'] ?: $number ),
                'goal_group'         => (string) ( $goal['goal_group'] ?? '' ),
                'is_active'          => ( ( $goal['goal_status'] ?? '' ) === 'active' ) ? 1 : 0,
                'value'              => self::valueInCents( $goal ),
                'trigger_event_type' => \OWA\Module\Base\Entity\GoalEvent::TRIGGER_PAGE_VIEW,
                'condition_property' => \OWA\Module\Base\Entity\GoalEvent::PROPERTY_PAGE_URI,
                'condition_operator' => (string) ( $details['match_type'] ?? '' ),
                'condition_value'    => (string) ( $details['goal_url'] ?? '' ),
                'steps'              => self::planSteps( $details ),
            );
        }

        return $planned;
    }

    /**
     * The Property a Profile observes.
     *
     * Goals were per site; goal events are per Property, so the migration makes
     * that hop. Self-contained rather than calling GoalManager: a migration
     * runs against a codebase that may be older than itself.
     *
     * @return string|null
     */
    public static function propertyFor( $site_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $site_id );

        return $site->get( 'property_id' );
    }

    /**
     * A funnel's steps, in order.
     *
     * 1.x stored a step as { name, path } and applied the path as a REGEX
     * against page_uri -- preg_match( '@<path>@i', $page_uri ). So the
     * condition is exact rather than interpretive: page_uri, regex, path.
     *
     * @return array
     */
    public static function planSteps( $details ) {

        if ( empty( $details['funnel_steps'] ) || ! is_array( $details['funnel_steps'] ) ) {

            return array();
        }

        $steps = array();

        foreach ( $details['funnel_steps'] as $number => $step ) {

            if ( ! is_array( $step ) || trim( (string) ( $step['path'] ?? '' ) ) === '' ) {

                /*
                 * A step with no path is a row someone added and left alone.
                 * The edit screen already drops those rather than treating them
                 * as a mistake; carrying them over would migrate a blank.
                 */
                continue;
            }

            $steps[] = array(
                'step_number'        => (int) $number,
                'name'               => (string) ( $step['name'] ?? '' ),
                'condition_property' => \OWA\Module\Base\Entity\GoalEvent::PROPERTY_PAGE_URI,
                'condition_operator' => \OWA\Module\Base\Entity\GoalEvent::MATCH_REGEX,
                'condition_value'    => (string) $step['path'],
            );
        }

        return $steps;
    }

    /**
     * A goal's value as whole cents.
     *
     * 1.x stored a free-form string, so this is the one field that can fail to
     * convert. A value that is not a number becomes 0 AND is reported, rather
     * than being silently rounded away -- someone typed it, and a money field
     * quietly becoming zero is not something a migration should do without
     * saying so.
     */
    private static function valueInCents( $goal ) {

        $cents = \OWA\Module\Base\Entity\GoalEvent::decimalToCents( $goal['goal_value'] ?? '' );

        if ( $cents === null ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Goal "%s" had a non-numeric value (%s); migrated as 0.',
                (string) ( $goal['goal_name'] ?? '' ),
                var_export( $goal['goal_value'] ?? null, true ) ) );

            return 0;
        }

        return $cents;
    }

    function down() {

        return true;
    }
}
