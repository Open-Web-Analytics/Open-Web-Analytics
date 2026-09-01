<?php

namespace OWA\Module\Base\Controller;

/**
 * The edit form for the Organization.
 *
 * There is exactly one, created as "My Organization" -- a name nobody chose --
 * and it heads the site control, so it needs somewhere to be renamed. Reached
 * from the control's first column, which is also where a list will go when
 * several Organizations exist.
 */
class OrganizationProfile extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );

        return parent::__construct( $params );
    }

    function action() {

        $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $organization->load( $sm->ensureOrganization() );

        $this->set( 'organization', $organization->_getProperties() );
        /*
         * The hierarchy wrapper, not base.options: the left column is the site
         * control, because that is what these screens are reached from and what
         * they describe. The settings nav belongs to install-wide options.
         */
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.organizationProfile' );
    }
}

?>
