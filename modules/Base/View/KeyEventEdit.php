<?php
namespace OWA\Module\Base\View;

class KeyEventEdit extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'key_event_edit.php' );

        $keyEvent = (array) $this->get( 'keyEvent' );

        $this->body->set( 'headline',
            ! empty( $keyEvent['id'] ) ? 'Key Event' : 'New Key Event' );

        $this->body->set( 'keyEvent', $keyEvent );
        $this->body->set( 'keyEventId', $this->get( 'keyEventId' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
        $this->body->set( 'conditionProperties', $this->get( 'conditionProperties' ) );
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?? array() );
    }
}
