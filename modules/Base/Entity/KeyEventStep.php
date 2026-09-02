<?php

namespace OWA\Module\Base\Entity;

/**
 * One step of a key event's funnel.
 *
 * A funnel is an ORDERED list of conditions leading to a key event, and 1.x
 * kept it as `details.funnel_steps` -- a nested array inside the goal, inside
 * the goals array, inside a settings blob. Three levels of nesting to say "and
 * then this page".
 *
 * A step IS a condition, so it carries the same property / operator / value
 * triple the key event itself does. That is what makes it v2-shaped rather than
 * a 1.x leftover: v2 evaluates conditions against event properties, and a
 * funnel step is one of those evaluated in sequence.
 *
 * 1.x stored a step as { name, path } and applied the path as a REGEX against
 * page_uri -- preg_match( '@<path>@i', $page_uri ) -- so the migration is exact
 * rather than interpretive: property page_uri, operator regex, value path.
 */
class KeyEventStep extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'key_event_step' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $key_event_id = new \OWA\Module\Base\Classes\DbColumn( 'key_event_id', OWA_DTD_BIGINT );
        $key_event_id->setIndex();
        $this->setProperty( $key_event_id );

        /* 1-based, and the order IS the funnel -- step 1 is where it starts. */
        $step_number = new \OWA\Module\Base\Classes\DbColumn( 'step_number', OWA_DTD_INT );
        $this->setProperty( $step_number );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        $condition_property = new \OWA\Module\Base\Classes\DbColumn( 'condition_property', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_property );

        $condition_operator = new \OWA\Module\Base\Classes\DbColumn( 'condition_operator', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_operator );

        $condition_value = new \OWA\Module\Base\Classes\DbColumn( 'condition_value', OWA_DTD_VARCHAR255 );
        $this->setProperty( $condition_value );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /**
     * The 1.x funnel-step shape, for the funnel report and the evaluator.
     *
     * @return array
     */
    public function toStepArray() {

        return array(
            'name' => $this->get( 'name' ),
            'path' => $this->get( 'condition_value' ),
        );
    }
}
