<?php

namespace OWA\Module\Base\Update;

/**
 * Goals become key events, and get a table of their own.
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
 * migration reads it rather than reinterpreting it. See KeyEvent.
 *
 * COPIES, does not move. owa_setting keeps its goals rows, so a rollback to the
 * previous release still reads its own goals. They stop being written, not
 * stored.
 */
class Update025 extends \OWA\Core\Update {

    var $schema_version = 25;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        if ( ! $entity->createTable() ) {

            $this->e->notice( 'Creating owa_key_event failed' );

            return false;
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

                $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

                /*
                 * A content-derived id, so re-running this migration updates
                 * the same rows rather than duplicating every goal. The slot
                 * number is part of it because that is what made a goal unique
                 * within a Profile.
                 */
                $keyEvent->set( 'id', $keyEvent->generateId(
                    'key_event:' . $planned['site_id'] . ':' . $planned['goal_number'] ) );

                foreach ( $planned as $column => $value ) {

                    $keyEvent->set( $column, $value );
                }

                $keyEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

                if ( $keyEvent->create() ) {

                    $migrated++;
                }
            }
        }

        $this->e->notice( "Migrated $migrated goal(s) into owa_key_event." );

        return true;
    }

    /**
     * The key events one Profile's goals blob describes.
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
                'name'               => (string) $goal['goal_name'],
                'goal_number'        => (int) ( $goal['goal_number'] ?: $number ),
                'goal_group'         => (string) ( $goal['goal_group'] ?? '' ),
                'is_active'          => ( ( $goal['goal_status'] ?? '' ) === 'active' ) ? 1 : 0,
                'value'              => self::valueInCents( $goal ),
                'trigger_event_type' => \OWA\Module\Base\Entity\KeyEvent::TRIGGER_PAGE_VIEW,
                'condition_property' => \OWA\Module\Base\Entity\KeyEvent::PROPERTY_PAGE_URI,
                'condition_operator' => (string) ( $details['match_type'] ?? '' ),
                'condition_value'    => (string) ( $details['goal_url'] ?? '' ),
            );
        }

        return $planned;
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

        $cents = \OWA\Module\Base\Entity\KeyEvent::decimalToCents( $goal['goal_value'] ?? '' );

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
