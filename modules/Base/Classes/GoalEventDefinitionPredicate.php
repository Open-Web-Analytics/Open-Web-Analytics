<?php

namespace OWA\Module\Base\Classes;

/**
 * A goal event definition's conditions, as a row predicate.
 *
 * The definition's own rules -- its trigger gates, and on one of OWA's own
 * events no conditions matches nothing -- applied here, and the compiling done
 * by Classes\EventPredicate, which funnel steps use too. Its caller is
 * Classes\Migration\GoalMigrator, which finds the 1.x conversions a
 * definition would have marked. Funnel steps named a definition through this
 * until Update079 made each such step an event step carrying a copy of its
 * definition's trigger and conditions.
 *
 * WHY A PREDICATE AND NOT A LOOKUP
 *
 * The tempting alternative is to read what ingest stored: 1.x's owa_session
 * carried goal_N per goal, and v2 stores a goal event per match. Both are wrong
 * for an ordered or backward-looking read in three separate ways:
 *
 *   1. IT HAS NO TIMESTAMP. A flag on the session says the session met the
 *      goal at some point, not when. There is nothing to order.
 *
 *   2. IT IS SESSION-SCOPED. A read by visitor has no column to read.
 *
 *   3. IT IS STAMPED FORWARD ONLY. is_goal_event is written at ingest, so a
 *      definition created this morning has no goal events behind it, while
 *      every fact needed to answer the question is sitting in the rows.
 *
 * WHAT IT COMPILES AGAINST
 *
 * The raw row's columns, which the cube copies under the same names, checked
 * against Classes\GoalVocabulary -- the check the builder, the save and marking
 * perform. A name it does not know is REFUSED BY NAME rather than dropped
 * (EventPredicate). Custom dimensions are not offered: a goal is judged at
 * ingest on the raw row, and cd_ columns are the cube's.
 *
 * A stored condition CAN still name something unknown: Update049 leaves an
 * untranslatable property in place and switches its goal off, so the name
 * survives to be shown to whoever has to fix it.
 *
 * @since owa 1.8.0
 */
class GoalEventDefinitionPredicate {

    /** Set when compile() returns null: which property could not be expressed. */
    private $error = '';

    /** @return string  empty when the last compile succeeded */
    public function getError() {

        return $this->error;
    }

    /**
     * Compile one definition into ( sql, params ), or null if it cannot be.
     *
     * @param  \OWA\Module\Base\Entity\GoalEventDefinition $definition
     * @param  string $alias  the table's alias in the caller's query
     * @return array|null     array( 'sql' => string, 'params' => array )
     */
    public function compile( $definition, $alias = 'd' ) {

        $this->error = '';

        $conditions = $definition->loadConditions();

        /*
         * THE TRIGGER GATES, as it does in matchesRow(): a definition on
         * page_view is not met by a click on the same page. No trigger matches
         * nothing, there as here.
         */
        $trigger = (string) $definition->get( 'trigger_event_type' );

        if ( $trigger === '' ) {

            return array( 'sql' => '( 0 = 1 )', 'params' => array() );
        }

        /*
         * NO conditions matches NOTHING, which is what matchesRow() answers
         * for the same case and for the same reason: an empty rule is
         * vacuously true, and a half-written definition that counted every
         * event on the site would be loudly wrong only after the fact.
         *
         * Except on a custom event, whose name is its own condition, as in
         * matchesRow() -- and which is what EventPredicate answers for an event
         * with no conditions.
         */
        if ( ! $conditions
             && ! \OWA\Module\Base\Entity\GoalEventDefinition::isCustomTrigger( $trigger ) ) {

            return array( 'sql' => '( 0 = 1 )', 'params' => array() );
        }

        $rows = array();

        foreach ( $conditions as $condition ) {

            $rows[] = array(
                'property' => (string) $condition->get( 'condition_property' ),
                'operator' => (string) $condition->get( 'condition_operator' ),
                'value'    => (string) $condition->get( 'condition_value' ),
            );
        }

        // No index pages: ingest compares the typed path as written, and this has to agree with it.
        $predicate = new EventPredicate();

        $compiled = $predicate->compile( $trigger, $definition->conditionMatch(), $rows, $alias );

        $this->error = $predicate->getError();

        return $compiled;
    }
}
