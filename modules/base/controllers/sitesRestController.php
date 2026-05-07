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
        
        $this->setResponseData( $this->get('tracked_sites') );
    }
}

?>