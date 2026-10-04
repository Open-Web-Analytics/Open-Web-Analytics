<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The install's Data Retention page: how long event data is kept, and the
 * reporting window every Property starts from (Classes\Retention).
 */
class OptionsRetention extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $owa_site_id = $this->resolveCurrentSiteId();

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 0 );
        $this->setView( 'base.optionsHierarchy' );
        $this->data['subview'] = 'base.optionsRetention';
        $this->data['view_method'] = 'delegate';

        return $this->data;
    }
}

?>
