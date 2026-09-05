<?php

namespace OWA\Module\Base\Entity;

/**
 * One condition of a goal event.
 *
 * A goal event carried a single property / operator / value triple to begin
 * with, which is 1.x's shape: one goal, one URL, one match type. It is not
 * enough to describe a behaviour pattern -- "a purchase over 50 from the pricing
 * page" is two conditions, and there was no way to write it.
 *
 * So the triple moved off the goal event into rows. Which also makes the shape
 * consistent with everything else here: a funnel step is a condition, a report
 * constraint is a condition, and now a goal event's own test is a condition
 * too, spelled the same way each time.
 *
 * How several combine is on the goal event itself (condition_match), not here.
 * A row says what it tests; it does not get an opinion on the others.
 */
class GoalEventCondition extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'goal_event_condition' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $goal_event_id = new \OWA\Module\Base\Classes\DbColumn( 'goal_event_id', OWA_DTD_BIGINT );
        $goal_event_id->setIndex();
        $this->setProperty( $goal_event_id );

        /*
         * The order they were written in. Not evaluation order -- ANDs and ORs
         * do not care -- but the order they are shown back in, so a rule an
         * author reads matches the one they wrote.
         */
        $sort_order = new \OWA\Module\Base\Classes\DbColumn( 'sort_order', OWA_DTD_INT );
        $this->setProperty( $sort_order );

        /*
         * What this condition is FOR: 'match' or 'start'.
         *
         * A match condition decides whether the goal event happened. A START
         * condition decides whether someone BEGAN it -- which is what feeds
         * goal_N_start on the session, and through it the seven registered
         * goalNStarts metrics.
         *
         * Its own role rather than its own table, because it is the same shape
         * being asked a different question, and one mechanism is easier to keep
         * honest than two.
         *
         * "Began it" used to be inferred from the first step of the goal's
         * FUNNEL, which tied an ingest-time decision to a reporting artefact --
         * so a funnel could not become a read-time report widget without
         * silently taking goal starts with it. It is stated directly now.
         *
         * Falsy reads as 'match': every condition that predates this is one.
         */
        $role = new \OWA\Module\Base\Classes\DbColumn( 'role', OWA_DTD_VARCHAR255 );
        $role->setIndex();
        $this->setProperty( $role );

        /* Which property of the event to test. */
        $condition_property = new \OWA\Module\Base\Classes\DbColumn( 'condition_property', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_property );

        /* How to compare it. See GoalEvent::operators(). */
        $condition_operator = new \OWA\Module\Base\Classes\DbColumn( 'condition_operator', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_operator );

        /* What to compare it to. */
        $condition_value = new \OWA\Module\Base\Classes\DbColumn( 'condition_value', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_value );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /** 'match' or 'start'; falsy reads as match. */
    public function role() {

        return $this->get( 'role' ) === GoalEvent::ROLE_START
            ? GoalEvent::ROLE_START : GoalEvent::ROLE_MATCH;
    }

    /**
     * Does one event property satisfy this condition?
     *
     * @param  mixed $value  the property's value on the event
     * @return bool
     */
    public function matches( $value ) {

        return GoalEvent::compare(
            $value, $this->get( 'condition_operator' ), $this->get( 'condition_value' ) );
    }
}
