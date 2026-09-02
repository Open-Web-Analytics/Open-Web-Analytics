<?php
namespace OWA\Module\Base\View;

class Funnels extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'funnels.php' );
        $this->body->set( 'headline', 'Funnels' );
        $this->body->set( 'funnels', (array) $this->get( 'funnels' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
    }
}
