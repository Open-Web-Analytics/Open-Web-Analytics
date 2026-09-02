<?php
namespace OWA\Module\Base\View;

class KeyEvents extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'key_events.php' );
        $this->body->set( 'headline', 'Key Events' );
        $this->body->set( 'keyEvents', $this->get( 'keyEvents' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
    }
}
