<?php

namespace OWA\Module\Base\Classes;

/**
 * An event and the conditions on it, as a row predicate over the cube.
 *
 * A funnel step is a condition on the event stream: an event name, and
 * optionally conditions on the properties that event carries, combined all or
 * any. This compiles one into ( sql, params ) evaluated against the facts at
 * read time, so a step defined this morning counts last month.
 *
 * Two callers. A funnel step (VisualizationFunnel) hands its own event, match
 * and conditions. A goal event definition (GoalEventDefinitionPredicate, which
 * Classes\Migration\GoalMigrator uses to find 1.x conversions) hands its
 * trigger and its stored conditions. Both mean the same thing by a condition,
 * which is the one GoalEventDefinition::compare() decides at ingest, so the
 * comparison lives here once.
 *
 * WHAT A CONDITION MAY NAME
 *
 * A column of the cube, and only one that passes a check first: the column is
 * interpolated into SQL, so a name reaches it only after it is found in
 *
 *   - Classes\GoalVocabulary, the raw row's columns, which the cube copies
 *     under the same names; or
 *   - the custom dimensions APPLIED to the Property whose cube this is, by
 *     column (cd_plan), handed in by the caller (customColumnsFor()). A cd_
 *     name not among them is refused like any other unknown name.
 *
 * Not `params`, the JSON document: GoalVocabulary excludes it, and an
 * unregistered key is not a step condition.
 *
 * A name the check does not know is REFUSED BY NAME (getError()) rather than
 * dropped. Dropping one would silently WIDEN the step -- "purchase over 50 from
 * the pricing page" would become "from the pricing page" and report a bigger
 * number that looks plausible.
 *
 * A REGEX CONDITION RUNS IN THE DATABASE, so its pattern passes the check a
 * report filter's does (ResultSetManager::regexProblem()) or the condition is
 * refused the same way, naming its property (getError()) and the reason
 * (getProblem()). The saves refuse such a pattern; this catches one stored
 * before they did. Refused rather than dropped, for the reason above, and
 * rather than run: an unportable construct is an SQL error on MySQL, and a
 * nested repeat backtracks with no time limit on MariaDB.
 *
 * PAGE PATHS ARE CANONICALISED ON THE READ SIDE, when the caller gives the
 * directory index pages. page_path is stored canonical (V2Event::canonicalPath():
 * a directory index page and a trailing slash collapsed), so a condition typed
 * `/store/` or `/store/index.php` would otherwise never equal the stored
 * `/store`. The typed value is what the author asked for and stays as stored;
 * the comparison uses the same function and the same setting as the row
 * (TrackingEventHelpers::directoryIndexPages()), so the two cannot disagree.
 * Exact and not-exact only: the other operators match on part of the value,
 * where collapsing the end of it would change what they say.
 *
 * Goal event definitions do not get this. Ingest compares the typed value with
 * the canonical row as it is (GoalEventDefinition::matchesRow()), and their
 * predicate has to agree with ingest, so GoalEventDefinitionPredicate passes no
 * index pages.
 */
class EventPredicate {

    /** Set when compile() returns null: which property could not be expressed. */
    private $error = '';

    /** Set with $error when it was a regex condition's pattern that was refused: why. */
    private $problem = '';

    /** @var array|null  directory index file names, or null to compare paths as written */
    private $indexPages;

    /** @var array  custom dimension column => label, applied to the Property */
    private $customColumns;

    /**
     * @param array|string|null $indexPages  canonicalise page_path conditions with
     *                                       these directory index file names
     *                                       (V2Event::indexPageNames()); null leaves
     *                                       them as written
     * @param array             $customColumns the Property's applied custom dimensions,
     *                                       column => label (customColumnsFor())
     */
    public function __construct( $indexPages = null, array $customColumns = array() ) {

        $this->indexPages    = $indexPages === null ? null : \OWA\Module\Base\Classes\V2Event::indexPageNames( $indexPages );
        $this->customColumns = $customColumns;
    }

    /** @return string  empty when the last compile succeeded */
    public function getError() {

        return $this->error;
    }

    /** @return string  why getError()'s condition's pattern was refused; empty when it was the name */
    public function getProblem() {

        return $this->problem;
    }

    /**
     * The custom dimensions applied to a Property, as column => label.
     *
     * From Classes\DimensionRegistry, where a Property's dimensions are read,
     * so a step may test what the Property's reports may group by: not
     * another Property's, and not a pending registration.
     *
     * @param  string $propertyId
     * @return array
     */
    public static function customColumnsFor( $propertyId ) {

        return array_column( DimensionRegistry::forProperty( $propertyId )->custom(), 'label', 'column' );
    }

    /** May a condition name this property? */
    public function allows( $property ) {

        $property = (string) $property;

        return isset( $this->customColumns[ $property ] )
            || \OWA\Module\Base\Classes\GoalVocabulary::has( $property );
    }

    /**
     * Compile one event and its conditions into ( sql, params ), or null.
     *
     * NO CONDITIONS matches every event of that name. A goal event definition
     * on one of OWA's own events with none matches nothing instead; that rule is
     * the definition's, and GoalEventDefinitionPredicate applies it before
     * calling this.
     *
     * @param  string $event       the event name; '' matches nothing
     * @param  string $match       'all' or 'any'; anything else reads as all
     * @param  array  $conditions  list of array( property, operator, value )
     * @param  string $alias       the cube's alias in the caller's query
     * @return array|null          array( 'sql' => string, 'params' => array )
     */
    public function compile( $event, $match, array $conditions, $alias = 'e' ) {

        $this->error   = '';
        $this->problem = '';

        $event = (string) $event;

        if ( $event === '' ) {

            return array( 'sql' => '( 0 = 1 )', 'params' => array() );
        }

        if ( ! $conditions ) {

            return array( 'sql' => '( ' . $alias . '.event_type = ? )', 'params' => array( $event ) );
        }

        $any = (string) $match === \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_ANY;

        $parts  = array();
        $params = array();

        foreach ( $conditions as $condition ) {

            $condition = (array) $condition;
            $property  = (string) ( $condition['property'] ?? '' );
            $operator  = (string) ( $condition['operator'] ?? '' );
            $value     = (string) ( $condition['value'] ?? '' );

            // THE CHECK, and the only thing between a stored name and a column name in SQL.
            if ( ! $this->allows( $property ) ) {

                $this->error = $property;

                return null;
            }

            if ( $operator === \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_REGEX && $value !== '' ) {

                $problem = ResultSetManager::regexProblem( $value );

                if ( $problem !== null ) {

                    $this->error   = $property;
                    $this->problem = $problem;

                    return null;
                }
            }

            $column = $alias . '.' . $property;

            $compiled = $this->comparison( $column, $operator, $this->canonical( $property, $operator, $value ) );

            /*
             * An absent value matches no condition, whatever the operator --
             * matchesRow() asks isset() first -- so a `not` condition is not
             * met by a row that has no such value at all.
             */
            $parts[]  = '( ' . $column . ' IS NOT NULL AND ' . $compiled['sql'] . ' )';
            $params   = array_merge( $params, $compiled['params'] );
        }

        return array(
            'sql'    => '( ' . $alias . '.event_type = ? AND ( '
                        . implode( $any ? ' OR ' : ' AND ', $parts ) . ' ) )',
            'params' => array_merge( array( $event ), $params ),
        );
    }

    /** A page_path value as the row stores it, when this compiler canonicalises. */
    private function canonical( $property, $operator, $value ) {

        $E = \OWA\Module\Base\Entity\GoalEventDefinition::class;

        if ( $this->indexPages === null || $property !== 'page_path'
             || ! in_array( $operator, array( constant( $E . '::MATCH_EXACT' ), constant( $E . '::MATCH_NOT' ) ), true ) ) {

            return $value;
        }

        return (string) \OWA\Module\Base\Classes\V2Event::canonicalPath( $value, $this->indexPages );
    }

    /**
     * Make sure the SQL vocabulary exists before anything reaches for it.
     *
     * OWA_SQL_CONTAINS and friends are defined at FILE SCOPE in the driver's
     * dialect, so they come into being when a driver class is autoloaded --
     * which happens when a connection is made, and not before. Every path that
     * reaches this compiler in a running installation has one, so it worked
     * everywhere except where it was tested alone: the file on its own, with no
     * connection, fatalled on an undefined constant.
     *
     * Loading the CLASS rather than requiring the file: the dialect is a trait,
     * and the driver is what pulls it in. Falling back to MySQL's when there is
     * no configuration to ask, which is the same assumption the test bootstrap
     * makes about OWA_DB_TYPE.
     */
    private static function ensureSqlVocabulary() {

        if ( defined( 'OWA_SQL_CONTAINS' ) ) {

            return;
        }

        class_exists( '\OWA\Core\Db\Mysql' );
    }

    /**
     * One comparison, mirroring GoalEventDefinition::compare().
     *
     * The pair have to agree: the same condition decides a goal event at ingest
     * through compare() and a funnel stage at read time through this.
     *
     * Two places where they cannot agree exactly, both stated rather than
     * papered over:
     *
     *   - CASE. strpos() and === are case-SENSITIVE; SQL follows the column's
     *     collation, which is case-insensitive here. So a contains-condition
     *     can match a stage the ingest-time check would not. Forcing a binary
     *     collation would fix it and would also stop the comparison using any
     *     index, on the table that holds every page view.
     *
     *   - REGEX. PCRE at ingest, the database's own engine here. The ordinary
     *     patterns people write -- anchors, character classes, alternation --
     *     mean the same thing in both; the exotic corners do not.
     *
     * @param  string $column
     * @param  string $operator
     * @param  string $value
     * @return array  array( 'sql' => string, 'params' => array )
     */
    private function comparison( $column, $operator, $value ) {

        self::ensureSqlVocabulary();

        $E = \OWA\Module\Base\Entity\GoalEventDefinition::class;

        // NULL never reaches here: compile() requires the column to be set,
        // as matchesRow() does before it compares.
        $col = $column;

        switch ( $operator ) {

            case constant( $E . '::MATCH_NOT' ):
                return array( 'sql' => $col . ' <> ?', 'params' => array( $value ) );

            case constant( $E . '::MATCH_CONTAINS' ):

                /*
                 * An empty target matches nothing, which is what compare()
                 * says -- and LOCATE would answer 1 for it, so the guard is
                 * load-bearing rather than defensive.
                 *
                 * Through the dialect, because containment is spelled three
                 * different ways across backends -- LOCATE here, POSITION(x IN
                 * y) on PostgreSQL, CHARINDEX on SQL Server. It is also why
                 * this is not LIKE: a value containing % or _ is a wildcard to
                 * LIKE, so "50% off" would quietly match far more than it says.
                 */
                if ( $value === '' ) {

                    return array( 'sql' => '( 0 = 1 )', 'params' => array() );
                }

                return array( 'sql' => sprintf( OWA_SQL_CONTAINS, '?', $col ),
                              'params' => array( $value ) );

            case constant( $E . '::MATCH_BEGINS' ):

                if ( $value === '' ) {

                    return array( 'sql' => '( 0 = 1 )', 'params' => array() );
                }

                return array( 'sql' => sprintf( OWA_SQL_STARTS_WITH, '?', $col ),
                              'params' => array( $value ) );

            case constant( $E . '::MATCH_REGEX' ):

                if ( $value === '' ) {

                    return array( 'sql' => '( 0 = 1 )', 'params' => array() );
                }

                return array( 'sql' => $col . ' ' . OWA_SQL_REGEXP . ' ?',
                              'params' => array( $value ) );

            case constant( $E . '::MATCH_GT' ):
            case constant( $E . '::MATCH_LT' ):

                /*
                 * NUMERIC, and only when both sides are numbers -- compare()
                 * refuses a lexicographic answer to "greater than" and so does
                 * this.
                 *
                 * The target is checked here; the COLUMN is checked in SQL,
                 * because a CAST of a non-numeric string is a read, not a write:
                 * sql_mode governs what may be STORED, so strict mode does not
                 * stop it yielding 0 with a warning. That would make
                 * "value > -1" true of every page on the site.
                 */
                if ( ! is_numeric( $value ) ) {

                    return array( 'sql' => '( 0 = 1 )', 'params' => array() );
                }

                return array(
                    'sql' => sprintf(
                        '( %s REGEXP \'^[-+]?[0-9]*\\\\.?[0-9]+$\' AND CAST( %s AS DECIMAL(30,10) ) %s ? )',
                        $col, $col,
                        $operator === constant( $E . '::MATCH_GT' ) ? '>' : '<' ),
                    'params' => array( $value ) );

            case constant( $E . '::MATCH_EXACT' ):
                return array( 'sql' => $col . ' = ?', 'params' => array( $value ) );

            default:
                /*
                 * An operator nobody recognises matches NOTHING -- which is
                 * what compare() answers by falling off the end of its switch.
                 * Not exact-match, which is the obvious wrong guess: it would
                 * make a definition-backed predicate disagree with the goal
                 * event count on exactly the definitions already malformed.
                 */
                return array( 'sql' => '( 0 = 1 )', 'params' => array() );
        }
    }
}
