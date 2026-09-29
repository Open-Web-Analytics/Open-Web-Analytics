<?php

namespace OWA\Module\Base\View;

class CustomDimensions extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Custom Dimensions' );
        $this->body->set_template( 'custom_dimensions.php' );

        foreach ( array( 'siteId', 'propertyId', 'dimensions', 'cube' ) as $key ) {

            $this->body->set( $key, $this->get( $key ) );
        }
    }
}

?>
