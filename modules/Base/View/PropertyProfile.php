<?php

namespace OWA\Module\Base\View;

class PropertyProfile extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Edit Property' );
        $this->body->set_template( 'property_profile.php' );
        $this->body->set( 'property', $this->get( 'property' ) );
        $this->body->set( 'propertyId', $this->get( 'propertyId' ) );
    }
}

?>
