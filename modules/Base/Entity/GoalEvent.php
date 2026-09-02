<?php

namespace OWA\Module\Base\Entity;

/**
 * One goal event: an author-named thing worth counting.
 *
 * This replaces the twenty numbered goal slots that lived, all twenty of them
 * whether used or not, inside one serialized array in a settings row. That
 * shape could not be queried, could not be indexed, could not be edited by two
 * people without one clobbering the other, and put a RECORD -- a thing that
 * happened being worth counting -- inside a settings blob, which is not what a
 * setting is.
 *
 * MODELLED FOR v2, DELIBERATELY. In v2 an author names a goal event, gives it an
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
class GoalEvent extends \OWA\Core\Entity {

    /*
     * How a condition compares.
     *
     * The first three are 1.x's goal match types, kept verbatim so migrated
     * goals mean exactly what they meant. The rest close the gaps that made
     * this vocabulary too small to describe a behaviour: you could not say "not
     * this page", "contains", or anything numeric at all.
     *
     * Named rather than symbolic (== and =@ in the report builder's
     * constraints) because these are stored and read back by a UI, where a name
     * survives being looked at. The LABELS match the constraint builder's, so
     * the same comparison reads the same wherever it appears.
     */
    const MATCH_EXACT    = 'exact';
    const MATCH_BEGINS   = 'begins';
    const MATCH_REGEX    = 'regex';
    const MATCH_NOT      = 'not';
    const MATCH_CONTAINS = 'contains';
    const MATCH_GT       = 'gt';
    const MATCH_LT       = 'lt';

    /** How several conditions combine. */
    const MATCH_ALL = 'all';
    const MATCH_ANY = 'any';

    /** What a condition is for. See GoalEventCondition. */
    const ROLE_MATCH = 'match';
    const ROLE_START = 'start';

    /**
     * The comparisons an author can choose, in the order they are offered.
     *
     * @return array  operator => label
     */
    public static function operators() {

        return array(
            self::MATCH_EXACT    => 'Exactly matching',
            self::MATCH_NOT      => 'Not matching',
            self::MATCH_CONTAINS => 'Contains',
            self::MATCH_BEGINS   => 'Begins with',
            self::MATCH_REGEX    => 'Matches regex',
            self::MATCH_GT       => 'Greater than',
            self::MATCH_LT       => 'Less than',
        );
    }

    /**
     * Does one value satisfy one comparison?
     *
     * The single place a condition is decided, so the goal event's own test,
     * its funnel steps and anything added later agree about what "contains"
     * means.
     *
     * @param  mixed  $value     the property's value on the event
     * @param  string $operator  one of the MATCH_ constants
     * @param  mixed  $target    what the author typed
     * @return bool
     */
    public static function compare( $value, $operator, $target ) {

        $value  = (string) $value;
        $target = (string) $target;

        switch ( $operator ) {

            case self::MATCH_EXACT:
                return $value === $target;

            case self::MATCH_NOT:
                return $value !== $target;

            case self::MATCH_CONTAINS:
                /*
                 * strpos() !== false, not a truthy test: a match at position 0
                 * returns 0, which is falsy, so "/thanks contains /" would read
                 * as no match. That trap has been found in this codebase before.
                 */
                return $target !== '' && strpos( $value, $target ) !== false;

            case self::MATCH_BEGINS:
                return $target !== '' && strpos( $value, $target ) === 0;

            case self::MATCH_REGEX:
                /*
                 * Delimited and quiet. An author's pattern is not trusted to be
                 * well formed, and a warning on every tracked event would be
                 * worse than the condition simply not matching.
                 */
                return $target !== ''
                    && @preg_match( '@' . $target . '@i', $value ) === 1;

            case self::MATCH_GT:
            case self::MATCH_LT:
                /*
                 * NUMERIC, and only when both sides are numbers. PHP would
                 * happily compare two strings here and answer something -- but
                 * "greater than" on a page URL is not a question anyone asked,
                 * and a silent lexicographic answer is worse than no match.
                 */
                if ( ! is_numeric( $value ) || ! is_numeric( $target ) ) {

                    return false;
                }

                return $operator === self::MATCH_GT
                    ? ( (float) $value > (float) $target )
                    : ( (float) $value < (float) $target );
        }

        return false;
    }

    /** What 1.x's single implemented goal type watches. */
    const TRIGGER_PAGE_VIEW = 'base.page_request';
    const PROPERTY_PAGE_URI = 'page_uri';

    function __construct() {

        $this->setTableName( 'goal_event' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /*
         * The PROPERTY this goal event belongs to -- the website, not one way
         * of watching it.
         *
         * A behaviour worth counting is a fact about the product: two Profiles
         * of one website both want to count the same signup, and defining it
         * twice is how the two definitions drift. GA puts key events on the
         * property for the same reason and applies them across every data
         * stream under it.
         *
         * COUNTING stays per Profile regardless, because in 1.x a conversion is
         * a flag on the session row and a session belongs to a Profile. So the
         * definition is inherited downward and the counting happens where it
         * always did.
         */
        $property_id = new \OWA\Module\Base\Classes\DbColumn( 'property_id', OWA_DTD_BIGINT );
        $property_id->setIndex();
        $this->setProperty( $property_id );

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

        /*
         * How often a match counts: once per session, or once per event.
         *
         * RECORDED HERE, NOT HONOURED IN 1.x. A 1.x conversion is a TINYINT on
         * the session row -- goal_N -- so "this session did or did not convert"
         * is the only thing the storage can say. Once-per-event is not a
         * handler choice there, it is not representable.
         *
         * It is stored anyway because v2 is the other way round: v2
         * materialises a row per match, so once-per-event is the natural
         * behaviour and once-per-session is the one needing suppression. The
         * column carries the author's intent across, so the v2 migration reads
         * it rather than guessing.
         *
         * Falsy reads as once per session, which is what every 1.x goal is.
         */
        $count_mode = new \OWA\Module\Base\Classes\DbColumn( 'count_mode', OWA_DTD_VARCHAR255 );
        $this->setProperty( $count_mode );

        /*
         * How several conditions combine: 'all' or 'any'.
         *
         * On the goal event rather than on each condition, because it is a fact
         * about the SET. A row that carried its own conjunction would let two
         * disagree, and there would be no answer to what the third one meant.
         *
         * Falsy reads as ALL -- the safer reading, and what a single-condition
         * goal event means either way.
         */
        $condition_match = new \OWA\Module\Base\Classes\DbColumn( 'condition_match', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_match );

        /*
         * CENTS, matching v2's revenue column, so the value does not have to be
         * converted twice. 1.x stored a free-form decimal string.
         */
        $value = new \OWA\Module\Base\Classes\DbColumn( 'value', OWA_DTD_BIGINT );
        $this->setProperty( $value );

        /*
         * The legacy slot, 1 to 20, or NULL for a goal event created after the
         * slots stopped existing.
         *
         * Kept because 45 registered metrics -- goal{N}Completions, Starts and
         * Value -- resolve by NUMBER, and a saved custom report or an API
         * client naming goal3Completions has to keep working through a 1.x
         * release. v2 drops both the column and those metrics for
         * sessionGoalEventRate:<name>, parameterised by name and unlimited.
         *
         * A goal event beyond the twentieth simply has no numbered metric.
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

    /** Does this goal event count right now? */
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

        $conditions = $this->loadConditions();
        $first      = $conditions ? $conditions[0] : null;

        return array(
            'goal_number' => $this->get( 'goal_number' ),
            'goal_name'   => $this->get( 'name' ),
            'goal_group'  => $this->get( 'goal_group' ),
            'goal_status' => $this->isActive() ? 'active' : 'disabled',
            'goal_value'  => self::centsToDecimal( $this->get( 'value' ) ),
            'goal_type'   => 'url_destination',
            'details'     => array_filter( array(
                /*
                 * The FIRST condition only.
                 *
                 * The 1.x goal shape holds one match_type and one goal_url, so
                 * a goal event with several conditions cannot be described in
                 * it. Everything that evaluates a conversion reads the
                 * conditions directly now; this shape is what the goals REPORTS
                 * still speak, and they show a single rule.
                 */
                'match_type'   => $first ? $first->get( 'condition_operator' ) : '',
                'goal_url'     => $first ? $first->get( 'condition_value' ) : '',
                /*
                 * Only when there are some. The evaluator tests
                 * array_key_exists( 'funnel_steps', ... ) and then indexes
                 * [1] unconditionally, so an empty array here would be a
                 * fatal on every event rather than "this goal has no funnel".
                 */
                'funnel_steps' => $this->loadSteps(),
            ), static function ( $value ) {

                return $value !== array() && $value !== null;
            } ),
        );
    }

    /** Once per session (1.x's only behaviour) or once per event. */
    const COUNT_PER_SESSION = 'once_per_session';
    const COUNT_PER_EVENT   = 'once_per_event';

    /**
     * How often a match counts.
     *
     * Falsy reads as once per session: every 1.x goal is one, and the storage
     * there cannot express the alternative.
     */
    public function countMode() {

        return $this->get( 'count_mode' ) === self::COUNT_PER_EVENT
            ? self::COUNT_PER_EVENT : self::COUNT_PER_SESSION;
    }

    /** 'all' or 'any'; falsy reads as all. */
    public function conditionMatch() {

        return $this->get( 'condition_match' ) === self::MATCH_ANY
            ? self::MATCH_ANY : self::MATCH_ALL;
    }

    /**
     * This goal event's conditions, in the order they were written.
     *
     * @return array of \OWA\Module\Base\Entity\GoalEventCondition
     */
    public function loadConditions( $role = self::ROLE_MATCH ) {

        if ( ! $this->get( 'id' ) ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'goal_event_id', $this->get( 'id' ) );
        $db->orderBy( 'sort_order', OWA_SQL_ASCENDING );

        $conditions = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_condition' );
            $condition->setProperties( $row );

            /*
             * Filtered here rather than in the query: role is falsy on every
             * condition that predates it, and Db::where() drops an empty value
             * rather than matching it -- so where( 'role', 'match' ) would
             * return everything, and where( 'role', '' ) would too.
             */
            if ( $condition->role() !== $role ) {

                continue;
            }

            $conditions[] = $condition;
        }

        return $conditions;
    }

    /**
     * Did this event BEGIN the goal event?
     *
     * Separate from matchesEvent() because starting and completing are
     * different questions with different answers, and 1.x records both.
     *
     * No start condition means no start -- the same rule as matching, and for
     * the same reason: a vacuously true rule would mark every event as
     * beginning every goal event on the site.
     */
    public function startedByEvent( $event ) {

        $conditions = $this->loadConditions( self::ROLE_START );

        if ( ! $conditions ) {

            return false;
        }

        foreach ( $conditions as $condition ) {

            if ( ! $condition->matches( $event->get( $condition->get( 'condition_property' ) ) ) ) {

                return false;
            }
        }

        return true;
    }

    /**
     * Does this event satisfy the goal event?
     *
     * @param  object $event  the tracking event
     * @return bool
     */
    public function matchesEvent( $event ) {

        $conditions = $this->loadConditions();

        /*
         * NO conditions means no match, not every match.
         *
         * An empty rule is vacuously true, and treating it that way would make
         * a half-written goal event count every single event on the site --
         * loudly wrong, and only after it had already happened. This install
         * already had a goal that could never fire; one that fires for
         * everything is the worse direction to be wrong in.
         */
        if ( ! $conditions ) {

            return false;
        }

        $any = $this->conditionMatch() === self::MATCH_ANY;

        foreach ( $conditions as $condition ) {

            $matched = $condition->matches( $event->get( $condition->get( 'condition_property' ) ) );

            if ( $any && $matched ) {

                return true;
            }

            if ( ! $any && ! $matched ) {

                return false;
            }
        }

        // Fell through: every one matched under ALL, or none did under ANY.
        return ! $any;
    }

    /**
     * The funnel that leads to this goal event, in the 1.x shape.
     *
     * Looked UP rather than owned. A funnel names its goal event, not the other
     * way round -- so a goal event usually has none, two funnels can lead to the
     * same one, and a funnel is worth having with no goal event at all.
     *
     * @return array
     */
    public function loadSteps() {

        if ( ! $this->get( 'id' ) ) {

            return array();
        }

        $funnel = \OWA\Module\Base\Entity\Funnel::forGoalEvent( $this->get( 'id' ) );

        return $funnel->wasPersisted() ? $funnel->loadSteps() : array();
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
