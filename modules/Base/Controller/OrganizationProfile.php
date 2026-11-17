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

        parent::__construct( $params );
    }

    function action() {

        $sm = new \OWA\Module\Base\Classes\SiteManager();

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $organization->load( $sm->ensureOrganization() );

        $this->set( 'organization', $organization->_getProperties() );
        /*
         * A current Profile even here, so the tile shows all three tiers rather
         * than the Organization over two blank lines -- and so the nav can
         * offer the Property and Profile groups at all.
         */
        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->settingsChrome( array( 'siteId' => $siteId ), '',
            $this->getParam( 'propertyId' ) ?: \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $siteId ) );
        $this->setSubview( 'base.organizationProfile', [
            'template' => 'organization_profile.php', 'vars' => [ 'organization' ] ] );
    }
}

?>
