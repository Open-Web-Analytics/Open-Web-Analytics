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

        foreach ( array( 'base.goal_event_condition' ) as $name ) {

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

                    /*
                     * The condition triple is not on the goal event any more --
                     * conditions are rows -- and site_id was replaced by the
                     * Property. Written below rather than set here.
                     */
                    if ( in_array( $column, array(
                             'steps', 'site_id',
                             'condition_property', 'condition_operator', 'condition_value' ),
                         true ) ) {

                        continue;
                    }

                    $goalEvent->set( $column, $value );
                }

                $goalEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

                if ( $goalEvent->create() ) {

                    $migrated++;
                }

                /*
                 * The condition, as its own row.
                 *
                 * A 1.x goal had exactly one -- one URL, one match type -- and
                 * that is not enough to describe a behaviour: "a purchase over
                 * 50 from the pricing page" is two conditions and could not be
                 * written. So the triple became rows, and the migration writes
                 * the one it has.
                 */
                if ( $planned['condition_value'] !== '' ) {

                    $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

                    $condition->set( 'id', $condition->generateId(
                        'goal_event_condition:' . $goalEvent->get( 'id' ) . ':1' ) );
                    $condition->set( 'goal_event_id', $goalEvent->get( 'id' ) );
                    $condition->set( 'sort_order', 1 );
                    $condition->set( 'condition_property', $planned['condition_property'] );
                    $condition->set( 'condition_operator', $planned['condition_operator'] );
                    $condition->set( 'condition_value', $planned['condition_value'] );
                    $condition->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
                    $condition->create();
                }

                /*
                 * The funnel is NOT migrated here.
                 *
                 * A funnel stopped being configuration attached to a goal. It
                 * is a VISUALIZATION now -- a row on owa_custom_report drawn by
                 * its own controller, defined where it is looked at -- so there
                 * is no goal-owned funnel table for this to write into.
                 *
                 * Which leaves 1.x funnel steps behind, and that is stated here
                 * rather than left to be discovered. Nothing is lost: Update022
                 * COPIED the site blobs rather than moving them, so the steps
                 * are still in owa_setting. But a funnel goal becomes a goal
                 * event with no funnel, and its owner rebuilds the path as a
                 * visualization.
                 *
                 * Migrating them automatically would mean inventing a
                 * visualization per funnel goal -- named and owned by nobody in
                 * particular, in a list that is meant to hold what someone
                 * deliberately made.
                 */
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

    /**
     * Drop the goal event tables.
     *
     * Safe BECAUSE the up() copied rather than moved: the goals it read are
     * still in the settings blob, untouched, so dropping these tables returns
     * the installation to reading them from there. If the up had emptied the
     * blob this down would be destroying the only copy.
     *
     * Idempotent: DROP TABLE IF EXISTS, so a half-finished rollback finishes.
     */
    function down() {

        foreach ( array( 'base.goal_event_condition', 'base.goal_event' ) as $name ) {

            // Conditions first. Nothing enforces the reference in SQL, but
            // dropping the parent first would leave rows pointing at a table
            // that is gone if the second drop then failed.
            \OWA\Core\CoreAPI::entityFactory( $name )->dropTable();
        }

        return true;
    }
}
