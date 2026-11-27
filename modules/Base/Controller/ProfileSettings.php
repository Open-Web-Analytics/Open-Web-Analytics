<?php

namespace OWA\Module\Base\Controller;

/**
 * Observation Settings: how a Profile watches its site.
 *
 * An Observation Profile IS a way of observing, so these settings are what
 * define it.
 */
class ProfileSettings extends \OWA\Core\AdminController {

    // edit_sites, and access to the Property of the Profile the request names.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    /** The screen, which SitesEditSettings draws again for a refused save. */
    const SCREEN = [ 'template' => 'profile_settings.php', 'vars' => [ 'site', 'posted', 'siteId' ] ];

    public function action() {

        /*
         * Resolved, not taken raw.
         *
         * getByColumn() throws "No value passed." on an empty value, so
         * arriving here without a siteId -- a bookmark, a stale link, the nav before a
         * site is in context -- produced an uncaught exception rather than a
         * screen. resolveCurrentSiteId() is what the rest of this hierarchy
         * already uses to answer "which Profile are we looking at": it returns
         * the id when there is one, and otherwise the first the user may see.
         */
        $site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $site_properties = [];

        /*
         * Still empty for a user with no sites at all, which is the one case
         * resolveCurrentSiteId() cannot answer. The screen renders its empty
         * state rather than throwing; getHierarchyNav() already tolerates an
         * empty id and builds the groups that do not need one.
         */
        if ( $site_id ) {

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

            $site_properties = $site->_getProperties();
        }

        $this->set( 'site', $site_properties );
        $this->set( 'siteId', $site_id );
        $this->settingsChrome( [], $site_id );
        $this->setSubview( 'base.profileSettings', self::SCREEN );
    }
}
