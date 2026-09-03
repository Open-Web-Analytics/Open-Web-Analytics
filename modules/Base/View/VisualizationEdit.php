<?php
namespace OWA\Module\Base\View;

class VisualizationEdit extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'visualization_edit.php' );
        $this->body->set( 'visualization', (array) $this->get( 'visualization' ) );
        $this->body->set( 'visualizationId', $this->get( 'visualizationId' ) );
        $this->body->set( 'steps', (array) $this->get( 'steps' ) );
        $this->body->set( 'visualizationType', $this->get( 'visualizationType' ) );
        $this->body->set( 'visualizationTypeLabel', $this->get( 'visualizationTypeLabel' ) );
        $this->body->set( 'visualizationTypeHint', $this->get( 'visualizationTypeHint' ) );
        $this->body->set( 'visualizationTypeIcon', $this->get( 'visualizationTypeIcon' ) );
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?? array() );
    }
}
