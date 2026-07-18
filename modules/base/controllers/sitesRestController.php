<?php

/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */

require_once(OWA_BASE_MODULE_DIR.'sites.php');

/**
 * Tracked Sites REST Controller
 * 
 * A GET REST method for obtaiing the list of tracked web sites
 *
 */
class owa_sitesRestController extends owa_sitesController {

	function action() {
        
        $site_id = $this->getParam('siteId');
        if (!empty($site_id)) {
            $site = owa_coreAPI::entityFactory('base.site');
            $site->getByColumn('site_id', $site_id);
            $this->set('site', $site);
        } else {
            $s = owa_coreAPI::entityFactory('base.site');
            $sites = $this->getSitesAllowedForCurrentUser();
            $this->set('tracked_sites', $sites);
        }
    }

    function success() {
	    
	    http_response_code(200);
	    
        $this->setView( 'base.sitesRest' );
    }
}


require_once(OWA_DIR.'owa_view.php');

/**
 * Sites Roster View
 * 
 */
class owa_sitesRestView extends owa_restApiView {
        
    function render() {
        
        $site = $this->get('site');
        if (empty($site)) {
            $this->setResponseData($this->get('tracked_sites'));
        } else {
            $this->setResponseData($site);
        }
    }
}

?>
