<?php
namespace OWA\Module\Base\View;

class GoalEventDefinitions extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'goal_event_definitions.php' );
        $this->body->set( 'headline', \OWA\Core\CoreAPI::t( 'Goal Event Definitions' ) );
        $this->body->set( 'goalEventDefinitions', $this->get( 'goalEventDefinitions' ) );
        $this->body->set( 'propertyId', $this->get( 'propertyId' ) );
    }
}
