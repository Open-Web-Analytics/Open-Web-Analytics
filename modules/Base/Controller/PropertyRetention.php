<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A Property's Data Retention page: how many months its reports keep.
 *
 * edit_settings, not edit_sites: retention is an install administrator's
 * decision even where it is set per Property (Classes\Retention).
 */
class PropertyRetention extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );

        parent::__construct( $params );
    }

    function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( $this->getParam( 'propertyId' ) ) {

            $property->load( $this->getParam( 'propertyId' ) );
        }

        $this->set( 'property', $property->_getProperties() );
        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ), $this->getParam( 'propertyId' ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'hierarchy_tier', 2 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId, $this->getParam( 'propertyId' ) ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.propertyRetention' );
    }
}

?>
