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

        parent::__construct( $params );
    }

    function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        /*
         * No propertyId means ADD. The same form serves both, as the Profile
         * form does -- a separate add screen would duplicate every field and
         * the two would drift.
         */
        if ( $this->getParam( 'propertyId' ) ) {

            $property->load( $this->getParam( 'propertyId' ) );
        }

        $this->set( 'property', $property->_getProperties() );
        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );
        /*
         * The hierarchy wrapper, not base.options: the left column is the site
         * control, because that is what these screens are reached from and what
         * they describe. The settings nav belongs to install-wide options.
         */
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /*
         * A current Profile even here, so the tile shows all three tiers rather
         * than the Organization over two blank lines -- and so the nav can
         * offer the Property and Profile groups at all.
         */
        $siteId = $this->resolveCurrentSiteId(
            $this->getParam( 'siteId' ), $this->getParam( 'propertyId' ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        /* Tier 2: this screen is about a Property, so the context line stops there. */
        $this->set( 'hierarchy_tier', 2 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav(
            $siteId, $this->getParam( 'propertyId' ) ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.propertyProfile' );
    }
}

?>
