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

        /*
         * Resolved, not taken raw.
         *
         * getByColumn() throws "No value passed." on an empty value, and
         * getEffectiveSettings() would throw for the same reason, so arriving
         * here without a siteId -- a bookmark, a stale link, the nav before a
         * site is in context -- produced an uncaught exception rather than a
         * screen. resolveCurrentSiteId() is what the rest of this hierarchy
         * already uses to answer "which Profile are we looking at": it returns
         * the id when there is one, and otherwise the first the user may see.
         */
        $site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $site_properties = array();
        $config          = array();

        /*
         * Still empty for a user with no sites at all, which is the one case
         * resolveCurrentSiteId() cannot answer. The screen renders its empty
         * state rather than throwing; getHierarchyNav() already tolerates an
         * empty id and builds the groups that do not need one.
         */
        if ( $site_id ) {

            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->getByColumn( 'site_id', $site_id );

            $site_properties = $site->_getProperties();

            /*
             * Effective values, not this row's blob: a key the Profile does not
             * set shows the Property's, Organization's or install's value, which
             * is what the Profile will actually observe with.
             */
            $config = \OWA\Core\CoreAPI::getEffectiveSettings(
                'profile', $site_id, 'base' );
        }

        $this->set( 'site', $site_properties );
        $this->set( 'config', $config );
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
