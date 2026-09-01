<?php

namespace OWA\Module\Base\Controller;

/**
 * Property Access Management.
 *
 * Access is granted to a WEBSITE, not to one way of observing it, so this sits
 * under the Property. The grants are still stored per Profile -- moving that is
 * a migration and a change to how sitesEditAllowedUsers resolves them -- but
 * the screen is where the concept belongs.
 */
class PropertyAccess extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        return parent::__construct( $params );
    }

    function action() {

        $site_id = $this->getParam( 'siteId' );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $site_id );

        $this->set( 'site', $site->_getProperties() );
        $this->set( 'siteId', $site_id );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $site_id ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.propertyAccess' );
    }
}

?>
