<?php

namespace OWA\Module\Base\View;

class CustomDimensionEdit extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'New Custom Dimension' );
        $this->body->set_template( 'custom_dimension_edit.php' );

        foreach ( array( 'siteId', 'propertyId', 'cube', 'scopes', 'types', 'submitted' ) as $key ) {

            $this->body->set( $key, $this->get( $key ) );
        }

        $this->body->set( 'used', (int) $this->get( 'used' ) );

        /* Per-field reasons for a refused form; see the spans in the template. */
        $this->body->set( 'validation_errors', $this->get( 'validation_errors' ) ?: array() );
    }
}

?>
