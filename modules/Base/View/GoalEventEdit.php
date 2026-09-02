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
        $this->body->set( 'funnelSteps', (array) $this->get( 'funnelSteps' ) );
        $this->body->set( 'goalGroups', (array) $this->get( 'goalGroups' ) );
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?? array() );
    }
}
