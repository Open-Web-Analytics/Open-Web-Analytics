<?php

namespace OWA\Module\Base\Entity;

/**
 * One key event: an author-named thing worth counting.
 *
 * This replaces the twenty numbered goal slots that lived, all twenty of them
 * whether used or not, inside one serialized array in a settings row. That
 * shape could not be queried, could not be indexed, could not be edited by two
 * people without one clobbering the other, and put a RECORD -- a thing that
 * happened being worth counting -- inside a settings blob, which is not what a
 * setting is.
 *
 * MODELLED FOR v2, DELIBERATELY. In v2 an author names a key event, gives it an
 * event type and a condition, and when the condition matches the server
 * materialises an additional row whose event_type IS that name (PLAN.html
 * §7.14). The columns here are exactly what that needs, so the v2 migration is
 * a read of this table and not a reinterpretation of it:
 *
 *   name                 becomes the materialised row's event_type
 *   trigger_event_type   the event type the condition is evaluated against
 *   condition_property   which property of that event to test
 *   condition_operator   how to compare it
 *   condition_value      what to compare it to
 *
 * v2's worked example -- "a click whose target is x" -- is trigger_event_type
 * 'click', condition_property 'element_id', operator 'exact'. 1.x's only
 * implemented goal type, url_destination, is the same row with property
 * 'page_uri'. One shape, both eras, no translation.
 */
class KeyEvent extends \OWA\Core\Entity {

    /** How a condition compares. The vocabulary 1.x already offered. */
    const MATCH_EXACT  = 'exact';
    const MATCH_BEGINS = 'begins';
    const MATCH_REGEX  = 'regex';

    /** What 1.x's single implemented goal type watches. */
    const TRIGGER_PAGE_VIEW = 'base.page_request';
    const PROPERTY_PAGE_URI = 'page_uri';

    function __construct() {

        $this->setTableName( 'key_event' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /*
         * The Observation Profile this key event belongs to, as the TRACKING
         * site_id string -- not owa_site.id.
         *
         * The schema has both conventions: owa_site_user.site_id is the BIGINT
         * row id, owa_setting.scope_id is the tracking string. This follows the
         * settings row it replaces, and for a practical reason -- every caller
         * already holds the string. The conversion handler reads it off the
         * event, the admin controllers take it as a request param, and
         * GoalManager is constructed with it. Storing the BIGINT would mean a
         * site lookup at each of those points to convert something nobody
         * asked for.
         */
        $site_id = new \OWA\Module\Base\Classes\DbColumn( 'site_id', OWA_DTD_VARCHAR255 );
        $site_id->setIndex();
        $this->setProperty( $site_id );

        /*
         * What the author called it. In v2 this becomes the event_type of the
         * row the server materialises, which is why it is the name and not a
         * label -- and why the event_type vocabulary stops being closed.
         */
        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /* Which event type the condition is evaluated against. */
        $trigger = new \OWA\Module\Base\Classes\DbColumn( 'trigger_event_type', OWA_DTD_VARCHAR255 );
        $this->setProperty( $trigger );

        /* The condition, as a property / operator / value triple. */
        $condition_property = new \OWA\Module\Base\Classes\DbColumn( 'condition_property', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_property );

        $condition_operator = new \OWA\Module\Base\Classes\DbColumn( 'condition_operator', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_operator );

        $condition_value = new \OWA\Module\Base\Classes\DbColumn( 'condition_value', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_value );

        /*
         * CENTS, matching v2's revenue column, so the value does not have to be
         * converted twice. 1.x stored a free-form decimal string.
         */
        $value = new \OWA\Module\Base\Classes\DbColumn( 'value', OWA_DTD_BIGINT );
        $this->setProperty( $value );

        /*
         * The legacy slot, 1 to 20, or NULL for a key event created after the
         * slots stopped existing.
         *
         * Kept because 45 registered metrics -- goal{N}Completions, Starts and
         * Value -- resolve by NUMBER, and a saved custom report or an API
         * client naming goal3Completions has to keep working through a 1.x
         * release. v2 drops both the column and those metrics for
         * sessionKeyEventRate:<name>, parameterised by name and unlimited.
         *
         * A key event beyond the twentieth simply has no numbered metric.
         */
        $goal_number = new \OWA\Module\Base\Classes\DbColumn( 'goal_number', OWA_DTD_INT );
        $goal_number->setIndex();
        $this->setProperty( $goal_number );

        /* The 1.x grouping label, carried so the goals reports keep grouping. */
        $goal_group = new \OWA\Module\Base\Classes\DbColumn( 'goal_group', OWA_DTD_VARCHAR255 );
        $this->setProperty( $goal_group );

        /*
         * Falsy is INACTIVE, and that is deliberate. 1.x's goal_status was a
         * string that had to equal 'active' to count, so anything unset simply
         * did not fire. Keeping "off unless it says otherwise" means a
         * half-written row never starts counting conversions on its own.
         */
        $is_active = new \OWA\Module\Base\Classes\DbColumn( 'is_active', OWA_DTD_TINYINT );
        $this->setProperty( $is_active );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /** Does this key event count right now? */
    public function isActive() {

        return (bool) $this->get( 'is_active' );
    }

    /**
     * The 1.x goal shape, for code that still speaks it.
     *
     * The conversion evaluator and the goals reports read goals as the nested
     * array the blob held. Rebuilding that here means the storage change is not
     * also a rewrite of everything that reads a goal -- which would have made
     * one change impossible to review.
     *
     * @return array
     */
    public function toGoalArray() {

        return array(
            'goal_number' => $this->get( 'goal_number' ),
            'goal_name'   => $this->get( 'name' ),
            'goal_group'  => $this->get( 'goal_group' ),
            'goal_status' => $this->isActive() ? 'active' : 'disabled',
            'goal_value'  => self::centsToDecimal( $this->get( 'value' ) ),
            'goal_type'   => 'url_destination',
            'details'     => array(
                'match_type' => $this->get( 'condition_operator' ),
                'goal_url'   => $this->get( 'condition_value' ),
            ),
        );
    }

    /**
     * A 1.x decimal goal value as whole cents.
     *
     * Returns null when the value is not a number, so a migration can say so
     * rather than silently storing 0 for something an author typed.
     *
     * @return int|null
     */
    public static function decimalToCents( $value ) {

        $value = trim( (string) $value );

        if ( $value === '' ) {

            return 0;
        }

        if ( ! is_numeric( $value ) ) {

            return null;
        }

        /*
         * round() before casting. (int) truncates, so a value stored as 0.29
         * would become 28 cents through float representation -- the classic
         * money-in-floats error, and one that under-reports every time rather
         * than averaging out.
         */
        return (int) round( ( (float) $value ) * 100 );
    }

    /** Cents back to the decimal string 1.x reporting expects. */
    public static function centsToDecimal( $cents ) {

        return number_format( ( (int) $cents ) / 100, 2, '.', '' );
    }
}
