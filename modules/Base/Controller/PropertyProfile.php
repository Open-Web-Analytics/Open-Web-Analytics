<?php

namespace OWA\Module\Base\Controller;

/**
 * The edit form for ONE Property.
 *
 * Reached from the site control's Properties column, which is where the
 * hierarchy is navigated now -- there is no Property roster screen. A roster
 * was a second place to browse the same tree, answering a question people ask
 * while looking at reports, on a screen reached from the admin menu.
 */
class PropertyProfile extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        return parent::__construct( $params );
    }

    function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $this->getParam( 'propertyId' ) );

        $this->set( 'property', $property->_getProperties() );
        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );
        $this->setView( 'base.options' );
        $this->setSubview( 'base.propertyProfile' );
    }
}

?>
