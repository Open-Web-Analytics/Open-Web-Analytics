<?php
namespace OWA\Module\Base\View;

class GoalEvents extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'goal_events.php' );
        $this->body->set( 'headline', 'Goal Events' );
        $this->body->set( 'goalEvents', $this->get( 'goalEvents' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
    }
}
