<?php

namespace OWA\Module\Base\View;

class PropertyProfile extends \OWA\Core\View {

    function render() {

        $isNew = ! $this->get( 'propertyId' );

        $this->t->set( 'page_title', $isNew ? 'New Property' : 'Property Details' );
        $this->body->set( 'headline', $isNew ? 'New Property' : 'Property Details' );
        $this->body->set_template( 'property_profile.php' );
        $this->body->set( 'property', $this->get( 'property' ) );
        $this->body->set( 'propertyId', $this->get( 'propertyId' ) );
    }
}

?>
