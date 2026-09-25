<?php
namespace OWA\Module\Base\View;

class GoalEventEdit extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'goal_event_edit.php' );

        $goalEvent = (array) $this->get( 'goalEvent' );

        $this->body->set( 'headline',
            ! empty( $goalEvent['id'] ) ? 'Goal Event' : 'New Goal Event' );

        $this->body->set( 'goalEvent', $goalEvent );
        $this->body->set( 'goalEventId', $this->get( 'goalEventId' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
        $this->body->set( 'conditionProperties', $this->get( 'conditionProperties' ) );

        /*
         * The trigger, and the events it can be. Both are read by the template, so
         * both have to be set here: View::get() answers FALSE for a key nobody
         * set, and a ViewScope throws on a var that was never assigned -- so
         * forgetting one of these is a blank screen rather than a blank field.
         */
        $this->body->set( 'triggerEvent',
            $this->get( 'triggerEvent' ) ?: \OWA\Module\Base\Entity\GoalEvent::TRIGGER_DEFAULT );
        $this->body->set( 'triggerEvents', (array) $this->get( 'triggerEvents' ) );
        $this->body->set( 'goalGroups', (array) $this->get( 'goalGroups' ) );
        $this->body->set( 'conditions', (array) $this->get( 'conditions' ) );
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?? array() );
    }
}
