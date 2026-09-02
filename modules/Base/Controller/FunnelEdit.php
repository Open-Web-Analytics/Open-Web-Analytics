<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or edit one funnel.
 */
class FunnelEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $funnel = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );

        if ( $this->getParam( 'funnelId' ) ) {

            $funnel->load( $this->getParam( 'funnelId' ) );
        }

        $this->set( 'funnel', $funnel->_getProperties() );
        $this->set( 'funnelId', $funnel->get( 'id' ) );
        $this->set( 'steps', $funnel->get( 'id' ) ? $funnel->loadSteps() : array() );
        $this->set( 'goalEvents', GoalEvents::listFor( $siteId ) );
        $this->set( 'siteId', $siteId );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 2 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.funnelEdit' );
    }
}
