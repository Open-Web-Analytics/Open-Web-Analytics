<?php
namespace OWA\Module\Base\Controller;

/**
 * Property Access Management.
 *
 * Access is granted to a Property. The grants are stored per Profile, and
 * Classes\PropertyGrants reads and writes them at the Property: a user granted
 * any Profile is shown as granted, and a save is written to every Profile.
 *
 * Addressed by propertyId. A siteId is still accepted and names its Property,
 * for links made before the screen moved.
 */
class PropertyAccess extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    function action() {

        $property_id = (string) $this->getParam( 'propertyId' );

        if ( $property_id === '' && $this->getParam( 'siteId' ) ) {

            $property_id = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $this->getParam( 'siteId' ) );
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( $property_id !== '' ) {

            $property->load( $property_id );
        }

        $this->set( 'property', $property->_getProperties() );
        $this->set( 'propertyId', $property_id );
        $this->set( 'grantedUserIds', $property_id !== ''
            ? \OWA\Module\Base\Classes\PropertyGrants::userIds( $property_id ) : array() );
        $this->set( 'params', array_merge( (array) $this->params, array( 'propertyId' => $property_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( '', $property_id ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.propertyAccess' );
    }
}

?>
