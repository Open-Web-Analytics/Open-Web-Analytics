<?php

namespace OWA\Module\Base\Controller;

/**
 * Observation Settings: how a Profile watches its site.
 *
 * Split out of the old site page, which stacked details, these settings and the
 * access grants into three forms that saved in pieces. An Observation Profile
 * IS a way of observing, so these settings are what define it.
 */
class ProfileSettings extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    function action() {

        $site_id = $this->getParam( 'siteId' );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $site_id );

        $this->set( 'site', $site->_getProperties() );
        /*
         * Effective values, not this row's blob: a key the Profile does not
         * set shows the Property's, Organization's or install's value, which
         * is what the Profile will actually observe with.
         */
        $this->set( 'config', \OWA\Core\CoreAPI::getEffectiveSettings(
            'profile', $site_id, 'base' ) );
        $this->set( 'siteId', $site_id );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /* Tier 3: this screen is about an Observation Profile, so the context line stops there. */
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $site_id ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.profileSettings' );
    }
}

?>
