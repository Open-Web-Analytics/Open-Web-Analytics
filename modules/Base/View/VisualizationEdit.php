<?php
namespace OWA\Module\Base\View;

class VisualizationEdit extends \OWA\Core\View {

    function render( $data ) {

        $this->body->set_template( 'visualization_edit.php' );
        $this->body->set( 'visualization', (array) $this->get( 'visualization' ) );
        $this->body->set( 'visualizationId', $this->get( 'visualizationId' ) );
        $this->body->set( 'steps', (array) $this->get( 'steps' ) );
        $this->body->set( 'visualizationTypes', (array) $this->get( 'visualizationTypes' ) );
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?? array() );
    }
}
