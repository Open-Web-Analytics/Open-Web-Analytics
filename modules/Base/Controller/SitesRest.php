<?php
namespace OWA\Module\Base\Controller;


/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */


/**
 * Tracked Sites REST Controller
 * 
 * A GET REST method for obtaiing the list of tracked web sites
 *
 */
class SitesRest extends \OWA\Core\ReportController {

    function __construct( $params ) {

        parent::__construct( $params );

        /*
         * view_site_list is the capability meaning "any signed-in user" -- the
         * endpoint answers with only the Profiles the caller may see, so the
         * gate is on being someone rather than on a particular site.
         */
        $this->setRequiredCapability( 'view_site_list' );
    }

    /*
     * This action used to be inherited from base.sites, the flat roster screen
     * that was also the landing page. That screen is retired; the endpoint is
     * not, so the one thing it needed from it lives here now. Inheriting a
     * screen controller to reuse four lines is how deleting the screen broke
     * the API.
     */
    function action() {

        $this->set( 'tracked_sites', $this->getSitesAllowedForCurrentUser() );
    }

    

    function success() {
	    
	    http_response_code(200);
	    
        $this->setView( 'base.sitesRest' );
    }
}
