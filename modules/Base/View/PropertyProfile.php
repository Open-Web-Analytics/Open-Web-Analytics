<?php

namespace OWA\Module\Base\View;

class PropertyProfile extends \OWA\Core\View {

    function render() {

        $isNew = ! $this->get( 'propertyId' );

        $this->t->set( 'page_title', $isNew ? \OWA\Core\CoreAPI::t( 'New Property' ) : \OWA\Core\CoreAPI::t( 'Property Details' ) );
        $this->body->set( 'headline', $isNew ? \OWA\Core\CoreAPI::t( 'New Property' ) : \OWA\Core\CoreAPI::t( 'Property Details' ) );
        $this->body->set_template( 'property_profile.php' );
        $this->body->set( 'property', $this->get( 'property' ) );
        $this->body->set( 'propertyId', $this->get( 'propertyId' ) );
        // A refused save's values, to show in place of what is stored.
        $this->body->set( 'posted', $this->get( 'posted' ) );
    }
}

?>
