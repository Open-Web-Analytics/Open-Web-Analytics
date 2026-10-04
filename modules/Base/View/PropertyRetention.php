<?php
namespace OWA\Module\Base\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

class PropertyRetention extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Data Retention' );
        $this->body->set( 'headline', 'Data Retention' );
        $this->body->set_template( 'property_retention.php' );
        $this->body->set( 'property', $this->get( 'property' ) );
        $this->body->set( 'propertyId', $this->get( 'propertyId' ) );
    }
}

?>
