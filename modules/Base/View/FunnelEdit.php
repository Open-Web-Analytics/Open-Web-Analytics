<?php
namespace OWA\Module\Base\View;

class FunnelEdit extends \OWA\Core\View {

    function render( $data ) {

        $funnel = (array) $this->get( 'funnel' );

        $this->body->set_template( 'funnel_edit.php' );
        $this->body->set( 'headline', ! empty( $funnel['id'] ) ? 'Funnel' : 'New Funnel' );
        $this->body->set( 'funnel', $funnel );
        $this->body->set( 'funnelId', $this->get( 'funnelId' ) );
        $this->body->set( 'steps', (array) $this->get( 'steps' ) );
        $this->body->set( 'goalEvents', (array) $this->get( 'goalEvents' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?? array() );
    }
}
