<?php

namespace OWA\Module\Base\Entity;

/**
 * One condition of a goal event definition.
 *
 * A goal event definition carried a single property / operator / value triple
 * to begin with, which is 1.x's shape: one goal, one URL, one match type. It is
 * not enough to describe a behaviour pattern -- "a purchase over 50 from the
 * pricing page" is two conditions, and there was no way to write it.
 *
 * So the triple moved off the definition into rows. Which also makes the shape
 * consistent with everything else here: a funnel step is a condition, a report
 * constraint is a condition, and now a definition's own test is a condition
 * too, spelled the same way each time.
 *
 * How several combine is on the definition itself (condition_match), not here.
 * A row says what it tests; it does not get an opinion on the others.
 */
class GoalEventDefinitionCondition extends \OWA\Core\Entity {

    /** The column holding the id of the definition a condition belongs to. */
    const DEFINITION_COLUMN = 'goal_event_definition_id';

    /** The names Update076 replaced. See namedBefore076(). */
    const TABLE_BEFORE_076             = 'goal_event_condition';
    const DEFINITION_COLUMN_BEFORE_076 = 'goal_event_id';

    function __construct() {

        $this->setTableName( 'goal_event_definition_condition' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $definition_id = new \OWA\Module\Base\Classes\DbColumn( self::DEFINITION_COLUMN, OWA_DTD_BIGINT );
        $definition_id->setIndex();
        $this->setProperty( $definition_id );

        /*
         * The order they were written in. Not evaluation order -- ANDs and ORs
         * do not care -- but the order they are shown back in, so a rule an
         * author reads matches the one they wrote.
         */
        $sort_order = new \OWA\Module\Base\Classes\DbColumn( 'sort_order', OWA_DTD_INT );
        $this->setProperty( $sort_order );

        /* Which property of the event to test. */
        $condition_property = new \OWA\Module\Base\Classes\DbColumn( 'condition_property', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_property );

        /* How to compare it. See GoalEventDefinition::operators(). */
        $condition_operator = new \OWA\Module\Base\Classes\DbColumn( 'condition_operator', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_operator );

        /* What to compare it to. */
        $condition_value = new \OWA\Module\Base\Classes\DbColumn( 'condition_value', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_value );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /**
     * This entity on the names Update076 replaced: the table
     * goal_event_condition, and the definition's id in goal_event_id.
     *
     * For the code an upgrade from 1.x runs before Update076, which meets the
     * table as 1.x left it: the updates up to Update075, and the v1 migration
     * in Update062 (Classes\Migration\GoalMigrator).
     *
     * @return $this
     */
    public function namedBefore076() {

        $this->setTableName( self::TABLE_BEFORE_076 );

        unset( $this->properties[ self::DEFINITION_COLUMN ] );

        $definition_id = new \OWA\Module\Base\Classes\DbColumn( self::DEFINITION_COLUMN_BEFORE_076, OWA_DTD_BIGINT );
        $definition_id->setIndex();
        $this->setProperty( $definition_id );

        return $this;
    }

    /**
     * Does one event property satisfy this condition?
     *
     * @param  mixed $value  the property's value on the event
     * @return bool
     */
    public function matches( $value ) {

        return GoalEventDefinition::compare(
            $value, $this->get( 'condition_operator' ), $this->get( 'condition_value' ) );
    }
}
