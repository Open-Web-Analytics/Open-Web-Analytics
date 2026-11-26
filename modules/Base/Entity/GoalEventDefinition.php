<?php

namespace OWA\Module\Base\Entity;

/**
 * One goal event definition: an author-named thing worth counting. Each event
 * that meets it adds a goal event, a row named by it (Classes\GoalMarking).
 *
 * A row per definition, so definitions can be queried, indexed and edited by two
 * people at once.
 *
 * In v2 an author names a goal event definition,
 * gives it an event type and a condition, and when the condition matches the server
 * materialises an additional row whose event_type IS that name.
 * The columns are exactly what that needs:
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
 * 'page_uri'.
 */
class GoalEventDefinition extends \OWA\Core\Entity {

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

    /**
     * The comparisons an author can choose, in the order they are offered.
     *
     * @return array<string, string> operator => label
     */
    public static function operators() {

        return [
            self::MATCH_EXACT    => \OWA\Core\CoreAPI::t( 'Exactly matching' ),
            self::MATCH_NOT      => \OWA\Core\CoreAPI::t( 'Not matching' ),
            self::MATCH_CONTAINS => \OWA\Core\CoreAPI::t( 'Contains' ),
            self::MATCH_BEGINS   => \OWA\Core\CoreAPI::t( 'Begins with' ),
            self::MATCH_REGEX    => \OWA\Core\CoreAPI::t( 'Matches regex' ),
            self::MATCH_GT       => \OWA\Core\CoreAPI::t( 'Greater than' ),
            self::MATCH_LT       => \OWA\Core\CoreAPI::t( 'Less than' ),
        ];
    }

    /**
     * An author's regex condition as a PHP pattern: delimited by @,
     * case-insensitive.
     *
     * An @ in the pattern -- an email address, say -- would end it early, so
     * one not already escaped is escaped. "\@" is left as written; "\\@" is an
     * escaped backslash before a bare @, which gets escaped too. The database
     * side (Classes\EventPredicate, REGEXP) takes the pattern undelimited.
     *
     * @param  string $pattern what the author typed
     * @return string
     */
    public static function regexPattern( $pattern ) {

        return '@' . preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)@/', '$1\\\\@', (string) $pattern ) . '@i';
    }

    /**
     * Does one value satisfy one comparison?
     *
     * The single place a condition is decided at ingest. Classes\EventPredicate
     * mirrors it in SQL, for this definition read back and for a funnel step's
     * conditions, so they agree about what "contains" means.
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
                    && @preg_match( self::regexPattern( $target ), $value ) === 1;

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

    /**
     * What 1.x's single implemented goal type watched, in 1.x's words.
     *
     * A v1 event type and a v1 property name. Update025 wrote both when it
     * migrated the twenty numbered slots, and Update049 translates them:
     * base.page_request is page_view, and page_uri is the page_path column.
     * Kept because that is what those rows SAY, and a migration reading them has
     * to name the thing it is reading.
     */
    const TRIGGER_PAGE_VIEW = 'base.page_request';
    const PROPERTY_PAGE_URI = 'page_uri';

    /**
     * What a goal event definition triggers on when nobody chose.
     *
     * A v2 event name, because this is what gets STORED on a save -- and
     * trigger_event_type is a gate at ingest now, so a v1 name here would match
     * no row at all.
     */
    const TRIGGER_DEFAULT = 'page_view';

    /**
     * The trigger picker's value for "a custom event, named in the field
     * beside it". Not an event name: the parentheses fail the name pattern, so
     * it can never be stored as a trigger.
     */
    const TRIGGER_CUSTOM = '(custom)';

    /** The table's name before Update076. See namedBefore076(). */
    const TABLE_BEFORE_076 = 'goal_event';

    /**
     * Whether this entity is on the names before Update076.
     *
     * @var bool
     */
    private $onNamesBefore076 = false;

    public function __construct() {

        $this->setTableName( 'goal_event_definition' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /*
         * The PROPERTY this definition belongs to -- the website, not one way
         * of watching it.
         *
         * A behaviour worth counting is a fact about the product: two Profiles
         * of one website both want to count the same signup, and defining it
         * twice is how the two definitions drift.
         *
         * COUNTING stays per Profile regardless: a goal event is a row of the
         * session that met the definition, and a session belongs to a Profile.
         * So the definition is inherited downward and the counting happens
         * where the session is.
         */
        $property_id = new \OWA\Module\Base\Classes\DbColumn( 'property_id', OWA_DTD_BIGINT );
        $property_id->setIndex();
        $this->setProperty( $property_id );

        /*
         * The event name of the goal events it adds (Classes\GoalMarking), so
         * a custom event name (nameProblem()). Fixed once saved: the rows
         * already stored carry it, and a new one would split the goal's history
         * in two.
         */
        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /* Which event type the condition is evaluated against. */
        $trigger = new \OWA\Module\Base\Classes\DbColumn( 'trigger_event_type', OWA_DTD_VARCHAR255 );
        $this->setProperty( $trigger );

        /*
         * How often a match adds a goal event: COUNT_PER_EVENT, one for every
         * event that meets the definition, or COUNT_PER_SESSION, none when the
         * session already has one of its name. Classes\GoalMarking honours it;
         * GoalEventDefinitionSave stores one of the two.
         *
         * Falsy reads as once per session (countMode()), which is what every
         * 1.x goal was: 1.x recorded a goal as a flag on the session row,
         * goal_N, so a session either had it or did not.
         */
        $count_mode = new \OWA\Module\Base\Classes\DbColumn( 'count_mode', OWA_DTD_VARCHAR255 );
        $this->setProperty( $count_mode );

        /*
         * How several conditions combine: 'all' or 'any'.
         *
         * On the definition rather than on each condition, because it is a fact
         * about the SET. A row that carried its own conjunction would let two
         * disagree, and there would be no answer to what the third one meant.
         *
         * Falsy reads as ALL -- the safer reading, and what a single-condition
         * definition means either way.
         */
        $condition_match = new \OWA\Module\Base\Classes\DbColumn( 'condition_match', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_match );

        /*
         * What one goal event is worth, in HUNDREDTHS of the Property's
         * currency, as decimalToCents() reads what an author typed. 1.x stored
         * a free-form decimal string. Classes\GoalMarking converts it to minor
         * units of the currency the Property's reports use, onto each goal
         * event's `value`: the same number for the currencies with two
         * decimal places.
         */
        $value = new \OWA\Module\Base\Classes\DbColumn( 'value', OWA_DTD_BIGINT );
        $this->setProperty( $value );

        /*
         * The 1.x slot a definition was migrated from (Update025), or NULL for
         * one created since. Nothing writes it and nothing on v2 reports on it.
         * It is the only link between a definition and the
         * owa_session.goal_N columns that hold 1.x's goal history.
         *
         * PENDING REMOVAL. Kept only for the v1-to-v2 history migration; drop it
         * (an update, since Update025 shipped it in 1.13) once that migration
         * no longer needs to map goal_N to a definition.
         */
        $goal_number = new \OWA\Module\Base\Classes\DbColumn( 'goal_number', OWA_DTD_INT );
        $goal_number->setIndex();
        $this->setProperty( $goal_number );

        /*
         * Falsy is INACTIVE, and that is deliberate. 1.x's goal_status was a
         * string that had to equal 'active' to count, so anything unset simply
         * did not fire. Keeping "off unless it says otherwise" means a
         * half-written row never starts adding goal events on its own.
         */
        $is_active = new \OWA\Module\Base\Classes\DbColumn( 'is_active', OWA_DTD_TINYINT );
        $this->setProperty( $is_active );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /**
     * This entity on the table name Update076 replaced, goal_event, and its
     * conditions on theirs (GoalEventDefinitionCondition::namedBefore076()).
     *
     * For the code an upgrade from 1.x runs before Update076, which meets the
     * tables as 1.x left them: the updates up to Update075, and the v1
     * migration in Update062 (Classes\Migration\GoalMigrator).
     * loadConditions(), delete() and GoalEventDefinitionPredicate read the
     * conditions under the same names.
     *
     * @return $this
     */
    public function namedBefore076() {

        $this->setTableName( self::TABLE_BEFORE_076 );
        $this->onNamesBefore076 = true;

        return $this;
    }

    /**
     * A condition entity on the names this one is on.
     *
     * @return GoalEventDefinitionCondition
     */
    protected function conditionEntity() {

        $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );

        return $this->onNamesBefore076 ? $condition->namedBefore076() : $condition;
    }

    /**
     * The condition column holding this entity's id.
     *
     * @return string
     */
    protected function conditionColumn() {

        return $this->onNamesBefore076
            ? GoalEventDefinitionCondition::DEFINITION_COLUMN_BEFORE_076
            : GoalEventDefinitionCondition::DEFINITION_COLUMN;
    }

    /**
     * A goal event definition's name made from free text: every run of
     * characters an event name cannot hold turned into one underscore, starting
     * with a letter and cut to the length one may have. For Update075, which
     * renames the definitions made before names were event names.
     *
     * @param  string $text
     * @return string
     */
    public static function nameFrom( $text ) {

        $out = trim( (string) preg_replace( '/[^A-Za-z0-9_]+/', '_', (string) $text ), '_' );

        if ( ! preg_match( '/^[A-Za-z]/', $out ) ) {

            $out = 'goal_' . $out;
        }

        return rtrim( substr( $out, 0, 40 ), '_' );
    }

    /**
     * The event name Update075 gives each goal event definition whose name is
     * not a usable event name unique within its Property.
     *
     * One that another definition of the Property already has, or that OWA
     * collects, gets a number on the end. Read by Update075, which renames
     * them, AND by the v1 migration (Classes\Migration\GoalMigrator), which
     * runs first, in Update062: the goal events it stores must carry the
     * name the definition will have, or they group apart from every later one.
     * Pure, so both give the same answer from the same rows; once Update075
     * has run it has nothing to rename.
     *
     * @param  array<array{id: int|string, property_id: int|string|null, name: string}> $rows
     *         definitions' id, property_id and name, in id order: a Property's
     *         alone gives what all of them would
     * @return array<int|string, string> id => the new name, for each one that is renamed
     */
    public static function eventNames( array $rows ) {

        // Case-blind, like the column's collation: Contact and contact would group as one event.
        $taken = [];

        foreach ( $rows as $row ) {

            if ( self::nameProblem( $row['name'] ) === '' ) {

                $taken[ (string) $row['property_id'] ][ strtolower( $row['name'] ) ] = (string) $row['id'];
            }
        }

        $renamed = [];

        foreach ( $rows as $row ) {

            $id       = (string) $row['id'];
            $property = (string) $row['property_id'];

            if ( ( $taken[ $property ][ strtolower( $row['name'] ) ] ?? null ) === $id ) {

                continue;
            }

            $base = self::nameFrom( $row['name'] );
            $name = $base;

            for ( $n = 2; self::nameProblem( $name ) !== '' || isset( $taken[ $property ][ strtolower( $name ) ] ); $n++ ) {

                $name = substr( $base, 0, 40 - strlen( '_' . $n ) ) . '_' . $n;
            }

            $taken[ $property ][ strtolower( $name ) ] = $id;
            $renamed[ $id ] = $name;
        }

        return $renamed;
    }

    /**
     * Why a name cannot be a goal event definition's, or '' when it can.
     *
     * A name OWA collects itself is refused: a goal row called click would be
     * counted as a click by every report that counts them. In any case:
     * event_type compares case-blind (its collation), so Page_View is counted
     * as page_view, and a row's id hashes the lowercased name
     * (Lib::wideStringGuid()), so a goal row called Session_Start takes the id
     * of the beacon's session_start row and the insert fails.
     *
     * @param  string $name
     * @return string
     */
    public static function nameProblem( $name ) {

        $name = (string) $name;

        if ( ! preg_match( \OWA\Module\Base\Classes\TrackingEventHelpers::CUSTOM_NAME_PATTERN, $name ) ) {

            return \OWA\Core\CoreAPI::t( 'A name starts with a letter and has up to 40 letters, digits and underscores.' );
        }

        $owas = array_merge( \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(),
            \OWA\Core\CoreAPI::trackingEventTypes() );

        if ( in_array( strtolower( $name ), array_map( 'strtolower', $owas ), true ) ) {

            return sprintf( \OWA\Core\CoreAPI::t( '%s is an event OWA collects.' ), $name );
        }

        return '';
    }

    /**
     * Whether a trigger is a site's own event rather than one of OWA's.
     *
     * A custom event's name already says what happened -- newsletter_signup --
     * so a goal on one needs no condition to mean something, where a goal on
     * every page_view would count every page view.
     *
     * @param  string $trigger
     * @return bool
     */
    public static function isCustomTrigger( $trigger ) {

        $trigger = (string) $trigger;

        return $trigger !== ''
            && self::triggerProblem( $trigger ) === ''
            && ! in_array( $trigger,
                \OWA\Module\Base\Classes\TrackingEventHelpers::goalTriggerEventNames(), true );
    }

    /**
     * Why an event cannot trigger a goal event definition, or '' when it can.
     *
     * One of OWA's goal triggers, or a name a site can send as its own event:
     * the tracker's custom name pattern, not an event OWA or a module sends,
     * and not under the owa_ prefix the tracker refuses for a site's events.
     *
     * @param  string $trigger
     * @return string
     */
    public static function triggerProblem( $trigger ) {

        $trigger = (string) $trigger;

        if ( in_array( $trigger,
                \OWA\Module\Base\Classes\TrackingEventHelpers::goalTriggerEventNames(), true ) ) {

            return '';
        }

        if ( in_array( $trigger, \OWA\Core\CoreAPI::trackingEventTypes(), true )
             || in_array( $trigger, \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), true ) ) {

            return sprintf( \OWA\Core\CoreAPI::t( 'A goal event definition cannot be triggered by %s.' ), $trigger );
        }

        if ( ! preg_match( \OWA\Module\Base\Classes\TrackingEventHelpers::CUSTOM_NAME_PATTERN, $trigger ) ) {

            return \OWA\Core\CoreAPI::t( 'An event name starts with a letter and has up to 40 letters, digits and underscores.' );
        }

        if ( stripos( $trigger, 'owa_' ) === 0 ) {

            return \OWA\Core\CoreAPI::t( 'The tracker does not send a site\'s own event under a name starting owa_.' );
        }

        return '';
    }

    /**
     * Does this definition count right now?
     *
     * @return bool
     */
    public function isActive() {

        return (bool) $this->get( 'is_active' );
    }

    /**
     * The Property a Profile observes.
     *
     * Goal event definitions belong to the Property -- the website -- and every
     * Profile of it inherits them. Callers hold a Profile id because that is what the
     * request carries and what a session belongs to, so the hop happens here
     * rather than at each of them.
     *
     * Memoized: goal marking asks per event.
     *
     * @param  string|null $site_id
     * @return string|null
     */
    public static function propertyFor( $site_id ) {

        if ( ! $site_id ) {

            return null;
        }

        if ( ! array_key_exists( $site_id, self::$property_of_site ) ) {

            /*
             * Read the column, not the entity.
             *
             * base.site is cachable and getByColumn() answers from that cache,
             * which is populated by whatever loaded the site first -- so this
             * could be handed a Site object that predates its property_id being
             * set, and return a different Property than the row actually has.
             *
             * A column read cannot be stale, and this is on the goal marking
             * path, where it is asked once per event.
             */
            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->selectFrom( $site->getTableName() );
            $db->selectColumn( 'property_id' );
            $db->where( 'site_id', $site_id );

            $row = $db->getOneRow();

            self::$property_of_site[ $site_id ] = ! empty( $row['property_id'] ) ? $row['property_id'] : null;
        }

        return self::$property_of_site[ $site_id ];
    }

    /** @var array<string, string|null> site id => its Property, as propertyFor() read it */
    private static $property_of_site = [];

    /**
     * Forget what propertyFor() has read. CoreAPI::forgetSingletons() calls
     * this.
     *
     * @return void
     */
    public static function forgetProperties() {

        self::$property_of_site = [];
    }

    /** Once per session (1.x's only behaviour) or once per event. */
    const COUNT_PER_SESSION = 'once_per_session';
    const COUNT_PER_EVENT   = 'once_per_event';

    /**
     * How often a match adds a goal event.
     *
     * Falsy, or anything but the two constants, reads as once per session:
     * every 1.x goal is one.
     *
     * @return string one of the COUNT_ constants
     */
    public function countMode() {

        return self::countModeOf( $this->get( 'count_mode' ) );
    }

    /**
     * countMode() of a stored count_mode, for a row read without the entity.
     *
     * @param  mixed  $stored the column as read, any type
     * @return string one of the COUNT_ constants
     */
    public static function countModeOf( $stored ) {

        return $stored === self::COUNT_PER_EVENT ? self::COUNT_PER_EVENT : self::COUNT_PER_SESSION;
    }

    /**
     * The count modes an author can choose, in the order they are offered.
     *
     * @return array<string, string> mode => label
     */
    public static function countModes() {

        return [
            self::COUNT_PER_EVENT   => \OWA\Core\CoreAPI::t( 'Once per event' ),
            self::COUNT_PER_SESSION => \OWA\Core\CoreAPI::t( 'Once per session' ),
        ];
    }

    /**
     * 'all' or 'any'; falsy reads as all.
     *
     * @return string MATCH_ALL or MATCH_ANY
     */
    public function conditionMatch() {

        return $this->get( 'condition_match' ) === self::MATCH_ANY
            ? self::MATCH_ANY : self::MATCH_ALL;
    }

    /**
     * This definition's conditions, in the order they were written.
     *
     * @return list<GoalEventDefinitionCondition>
     */
    public function loadConditions() {

        if ( ! $this->get( 'id' ) ) {

            return [];
        }

        $entity = $this->conditionEntity();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( $this->conditionColumn(), $this->get( 'id' ) );
        $db->orderBy( 'sort_order', OWA_SQL_ASCENDING );

        $conditions = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $condition = $this->conditionEntity();
            $condition->setProperties( $row );

            $conditions[] = $condition;
        }

        return $conditions;
    }

    /**
     * The conditions of several definitions, in one query: id => the
     * definition's conditions, as loadConditions() gives them. A definition
     * with none maps to an empty list.
     *
     * The definitions are on the names the first one is on.
     *
     * @param  list<GoalEventDefinition> $definitions
     * @return array<string, list<GoalEventDefinitionCondition>>
     */
    public static function loadConditionsOf( array $definitions ) {

        $out = [];

        foreach ( $definitions as $definition ) {

            if ( $definition->get( 'id' ) ) {

                $out[ (string) $definition->get( 'id' ) ] = [];
            }
        }

        if ( ! $out ) {

            return $out;
        }

        $first  = reset( $definitions );
        $entity = $first->conditionEntity();
        $column = $first->conditionColumn();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( $column, array_map( 'strval', array_keys( $out ) ), 'in' );
        $db->orderBy( 'sort_order', OWA_SQL_ASCENDING );

        foreach ( (array) $db->getAllRows() as $row ) {

            $id = (string) $row[ $column ];

            if ( ! isset( $out[ $id ] ) ) {

                continue;
            }

            $condition = $first->conditionEntity();
            $condition->setProperties( $row );

            $out[ $id ][] = $condition;
        }

        return $out;
    }

    /**
     * Delete this definition AND the conditions that belong to it.
     *
     * Entity::delete() removes one row from one table, which would leave the
     * conditions behind with nothing able to reach them.
     *
     * ON THE ENTITY rather than in GoalEventDefinitionDelete, because that
     * controller is not the only caller: the e2e fixtures and the tests delete
     * definitions too, and a cascade living in one of several callers is a
     * cascade that happens sometimes.
     *
     * BY ANY COLUMN, because the inherited signature allows it. The ids are
     * resolved first, so delete( $property_id, 'property_id' ) cascades as well
     * as delete( $id ) does.
     *
     * CONDITIONS GO ONE AT A TIME, BY ID. GoalEventDefinitionCondition is
     * cachable, and Entity::delete() evicts the key it was given -- so a single
     * delete( $definition_id, 'goal_event_definition_id' ) would clear the
     * wrong key and leave every condition still in cache under its own id.
     *
     * @param  mixed  $value
     * @param  string $col
     * @return bool
     */
    public function delete( $value = '', $col = 'id' ) {

        if ( empty( $value ) ) {

            $value = $this->get( 'id' );
        }

        foreach ( $this->conditionIdsFor( $col, $value ) as $condition_id ) {

            $condition = $this->conditionEntity();
            $condition->delete( $condition_id );
        }

        return parent::delete( $value, $col );
    }

    /**
     * The condition ids belonging to whichever definitions ( $col, $value )
     * names -- every role, which is why this is not loadConditions().
     *
     * @param  string $col
     * @param  mixed  $value the value the caller gave delete()
     * @return list<int|string>
     */
    protected function conditionIdsFor( $col, $value ) {

        if ( empty( $value ) ) {

            return [];
        }

        $ids = [ $value ];

        if ( $col !== 'id' ) {

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->selectFrom( $this->getTableName() );
            $db->selectColumn( 'id' );
            $db->where( $col, $value );

            $ids = array_column( (array) $db->getAllRows(), 'id' );
        }

        $out = [];

        foreach ( $ids as $id ) {

            $entity = $this->conditionEntity();

            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->selectFrom( $entity->getTableName() );
            $db->selectColumn( 'id' );
            $db->where( $this->conditionColumn(), $id );

            foreach ( (array) $db->getAllRows() as $row ) {

                $out[] = $row['id'];
            }
        }

        return $out;
    }
    /**
     * Does this ROW satisfy the definition?
     *
     * THE ROW, NOT THE EVENT: half the vocabulary an author would reach for does
     * not exist until the row is assembled -- device_type is derived in the
     * handler from the user-agent parse, and the tagged_* columns are
     * transcribed there too.
     *
     * The row is the one EventRawHandlers::rowFor() says the event will be
     * stored as, so a condition can name any column the row has.
     *
     * THE TRIGGER IS A GATE: a goal declared on a page view is evaluated against
     * page views only, so a goal on, say, host does not fire on every event type.
     *
     * AN EMPTY TRIGGER MATCHES NOTHING, the same answer as no conditions.
     * Update025 gives every migrated definition a trigger and
     * GoalEventDefinitionSave defaults one. Meaning "every event" would mark the
     * page view AND the session_start and first_visit materialized beside it,
     * three goal events for one visit to a goal page.
     *
     * AN ABSENT OR NULL COLUMN CANNOT ANSWER, so it does not match -- whatever
     * the operator. Passing NULL through to compare() would make `not` true for
     * every row that simply does not carry the column: a condition meant to
     * exclude one medium would mark every event with no medium at all, which is
     * most of them. The cost is that "medium is not set" is not expressible as
     * a condition, and that is the right way round.
     *
     * @param  array<string, mixed> $row the assembled raw row: column => value
     * @param  list<GoalEventDefinitionCondition>|null $conditions  the conditions, already loaded, or null to
     *                                 load them -- the caller passes them when
     *                                 it is matching many rows against the same
     *                                 definition, which is ingest's shape.
     * @return bool
     */
    public function matchesRow( array $row, $conditions = null ) {

        $trigger = (string) $this->get( 'trigger_event_type' );

        if ( $trigger === '' || $trigger !== (string) ( $row['event_type'] ?? '' ) ) {

            return false;
        }

        if ( $conditions === null ) {

            $conditions = $this->loadConditions();
        }

        /*
         * NO conditions means no match, not every match.
         *
         * An empty rule is vacuously true, and treating it that way would make
         * a half-written definition count every single event on the site, which is
         * the worse direction to be wrong in.
         *
         * EXCEPT ON A CUSTOM EVENT, whose name is the condition: every
         * newsletter_signup is one.
         */
        if ( ! $conditions ) {

            return self::isCustomTrigger( $trigger );
        }

        $any = $this->conditionMatch() === self::MATCH_ANY;

        foreach ( $conditions as $condition ) {

            $property = (string) $condition->get( 'condition_property' );

            $matched = isset( $row[ $property ] )
                       && $condition->matches( $row[ $property ] );

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
     * A 1.x decimal goal value as whole cents.
     *
     * Returns null when the value is not a number, so a migration can say so
     * rather than silently storing 0 for something an author typed.
     *
     * @param  mixed $value a 1.x goal value as stored, any type
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

    /**
     * Cents back to the decimal string 1.x reporting expects.
     *
     * @param  int|string|null $cents as stored; null reads as 0
     * @return string
     */
    public static function centsToDecimal( $cents ) {

        return number_format( ( (int) $cents ) / 100, 2, '.', '' );
    }
}
