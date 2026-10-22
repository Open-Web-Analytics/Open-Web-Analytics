<?php
namespace OWA\Module\Base\View;

class PropertyProfiles extends \OWA\Core\View {

    function render( $data ) {

        $this->t->set( 'page_title', 'Observation Profiles' );
        $this->body->set_template( 'property_profiles.php' );
        $this->body->set( 'property', (array) ( $this->get( 'property' ) ?: array() ) );
        $this->body->set( 'propertyId', (string) $this->get( 'propertyId' ) );
        $this->body->set( 'profiles', (array) ( $this->get( 'profiles' ) ?: array() ) );
    }
}

?>
