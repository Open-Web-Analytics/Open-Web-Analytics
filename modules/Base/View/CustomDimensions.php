<?php

namespace OWA\Module\Base\View;

class CustomDimensions extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Custom Dimensions' );
        $this->body->set_template( 'custom_dimensions.php' );

        foreach ( array( 'siteId', 'propertyId', 'dimensions', 'cube', 'scopes', 'types' ) as $key ) {

            $this->body->set( $key, $this->get( $key ) );
        }

        /*
         * What was typed, when a refused form comes back. View::get() answers
         * FALSE for an absent key rather than null, so the default is written
         * out here instead of being left to the template.
         */
        $submitted = $this->get( 'submitted' );

        $this->body->set( 'submitted', is_array( $submitted ) ? $submitted : array(
            'dimensionKey' => '', 'scope' => '', 'dataType' => '', 'label' => '',
        ) );
    }
}

?>
