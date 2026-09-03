<?php

namespace OWA\Module\Base\Classes;

/**
 * A goal event's conditions, as a row predicate.
 *
 * WHY THIS EXISTS AT ALL, AND WHY IT IS NOT A LOOKUP
 *
 * A funnel step is a CONDITION on the event stream, and a goal event is a
 * condition somebody named. So "the last step is my Signup goal event" ought to
 * mean the same thing as writing that goal event's conditions into the step by
 * hand -- which is what this makes true: it compiles the conditions into the
 * same kind of predicate a path step compiles to, evaluated against the facts
 * at read time.
 *
 * The tempting alternative is to read the stored conversion: owa_session
 * carries goal_N per goal, stamped at ingest. It is wrong for a funnel in three
 * separate ways, and each one alone would be enough:
 *
 *   1. IT HAS NO TIMESTAMP. A funnel is an ORDER -- step N counts only those
 *      who reached it after step N-1 -- and a flag on the session says the
 *      session converted at some point, not when. There is nothing to order.
 *
 *   2. IT IS SESSION-SCOPED. A funnel counted by visitor has no column to read.
 *
 *   3. IT IS STAMPED FORWARD ONLY. A goal event created this morning has no
 *      conversions behind it, so a funnel drawn over last month would be empty
 *      -- while every fact needed to answer the question sits in owa_request.
 *
 * This is also how GA does it. A key event named in a funnel step is a
 * condition matched against the event stream like any other step condition; the
 * key-event flag lives on the event row, so it is a row filter, not a counter
 * being read back. Nothing in a funnel exploration consults a stored conversion
 * total.
 *
 * WHAT IT REFUSES
 *
 * The funnel's query joins the request facts to the document dimension, so the
 * properties it can speak about are the document's. A condition on anything
 * else is REFUSED BY NAME rather than dropped. Dropping one would silently
 * WIDEN the goal event -- "purchase over 50 from the pricing page" would become
 * "from the pricing page" and report a bigger number that looks perfectly
 * plausible. Silently discarded constraints have produced exactly that kind of
 * wrong answer in this codebase before.
 *
 * @since owa 1.8.0
 */
class GoalEventPredicate {

    /**
     * The properties a funnel step can be written against, and their columns.
     *
     * These are the document dimension's, because that is what the funnel query
     * joins -- see VisualizationFunnel::countFunnel(). The alias is fixed by
     * that query and passed in rather than hardcoded here, so the two cannot
     * drift into disagreeing about what `d` means.
     */
    const COLUMNS = array(
        'page_uri'   => 'uri',
        'page_url'   => 'url',
        'page_title' => 'page_title',
        'page_type'  => 'page_type',
    );

    /** Set when compile() returns null: which property could not be expressed. */
    private $error = '';

    /** @return string  empty when the last compile succeeded */
    public function getError() {

        return $this->error;
    }

    /**
     * Compile one goal event into ( sql, params ), or null if it cannot be.
     *
     * @param  \OWA\Module\Base\Entity\GoalEvent $goalEvent
     * @param  string $alias  the document table's alias in the caller's query
     * @return array|null     array( 'sql' => string, 'params' => array )
     */
    public function compile( $goalEvent, $alias = 'd' ) {

        $this->error = '';

        $conditions = $goalEvent->loadConditions();

        /*
         * NO conditions matches NOTHING, which is what matchesEvent() answers
         * for the same case and for the same reason: an empty rule is
         * vacuously true, and a half-written goal event that counted every
         * event on the site would be loudly wrong only after the fact.
         *
         * `0 = 1` rather than a refusal, because this is not an error -- it is
         * a goal event that genuinely counts nothing, and the funnel should say
         * zero rather than fail to draw.
         */
        if ( ! $conditions ) {

            return array( 'sql' => '( 0 = 1 )', 'params' => array() );
        }

        $any = $goalEvent->conditionMatch()
               === \OWA\Module\Base\Entity\GoalEvent::MATCH_ANY;

        $parts  = array();
        $params = array();

        foreach ( $conditions as $condition ) {

            $property = (string) $condition->get( 'condition_property' );

            if ( ! isset( self::COLUMNS[ $property ] ) ) {

                $this->error = $property;

                return null;
            }

            $column = $alias . '.' . self::COLUMNS[ $property ];

            $compiled = $this->comparison(
                $column,
                (string) $condition->get( 'condition_operator' ),
                (string) $condition->get( 'condition_value' ) );

            $parts[]  = $compiled['sql'];
            $params   = array_merge( $params, $compiled['params'] );
        }

        return array(
            'sql'    => '( ' . implode( $any ? ' OR ' : ' AND ', $parts ) . ' )',
            'params' => $params,
        );
    }

    /**
     * One comparison, mirroring GoalEvent::compare().
     *
     * The pair have to agree: the same goal event decides a conversion at
     * ingest through compare() and a funnel stage at read time through this, so
     * a difference between them is a funnel that disagrees with the conversion
     * count beside it.
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
     * That is worth fixing rather than working around in the test, because the
     * compiler is otherwise pure -- it turns a goal event into a string and
     * some parameters, and needing a live database to do that is a dependency
     * it should not have.
     *
     * Loading the CLASS rather than requiring the file: the dialect is a trait,
     * and the driver is what pulls it in. Falling back to MySQL's when there is
     * no configuration to ask, which is the same assumption the test bootstrap
     * makes about OWA_DB_TYPE -- there is one dialect today, and a second one
     * would come with a driver this could name.
     */
    private static function ensureSqlVocabulary() {

        if ( defined( 'OWA_SQL_CONTAINS' ) ) {

            return;
        }

        class_exists( '\OWA\Core\Db\Mysql' );
    }

    private function comparison( $column, $operator, $value ) {

        self::ensureSqlVocabulary();

        $E = \OWA\Module\Base\Entity\GoalEvent::class;

        /*
         * COALESCE, because compare() casts its input to string first -- so a
         * NULL column is the empty string there, and every operator below has
         * to see it the same way. Without it `NOT` on a NULL column answers
         * NULL, which is not a match, while compare() answers true.
         */
        $col = sprintf( OWA_SQL_COALESCE, $column, "''" );

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
                 * y) on PostgreSQL, CHARINDEX on SQL Server -- and the whole
                 * expression is the dialect's to give, not just a function
                 * name. It is also why this is not LIKE: a value containing %
                 * or _ is a wildcard to LIKE, so "50% off" would quietly match
                 * far more than it says.
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
                 * because sql_mode is empty on every connection this makes and
                 * a non-numeric string would otherwise CAST to 0 with only a
                 * warning. That would make "value > -1" true of every page on
                 * the site.
                 *
                 * In practice this arm is only reachable on a text column --
                 * the four properties above are all text -- so it matches
                 * nothing, which is exactly what compare() answers for it.
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
                 *
                 * Not exact-match, which is the obvious wrong guess: it would
                 * make the funnel disagree with the conversion count beside it
                 * on exactly the goal events that are already malformed.
                 */
                return array( 'sql' => '( 0 = 1 )', 'params' => array() );
        }
    }
}
