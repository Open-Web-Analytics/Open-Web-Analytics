<?php

/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */


/**
 * CORS Preflight Request Controller
 * 
 * Responds to an OPTIONS preflight request made by browsers for non-simple HTTP CORS requests. 
 *
 */
class owa_corsPreflightController extends owa_controller {
    
    function success() {
	    
	    http_response_code(200);
	    
	    $service = owa_coreAPI::serviceSingleton();
	    $this->set('HTTP_ACCESS_CONTROL_REQUEST_HEADERS', $service->request->getServerParam('HTTP_ACCESS_CONTROL_REQUEST_HEADERS') );
	    $service->request->getRequestType();
	    
        $this->setView( 'base.corsPreflight' );
    }
}


require_once(OWA_DIR.'owa_view.php');

/**
 * cors preflight response.
 * 
 */
class owa_corsPreflightView extends owa_restApiView {
        
    function render() {
        
        // set the required HTTP_ACCESS_CONTROL_REQUEST_HEADERS
        if ($this->get('HTTP_ACCESS_CONTROL_REQUEST_HEADERS') ) {
	        
        	header("Access-Control-Allow-Headers: ". $this->get('HTTP_ACCESS_CONTROL_REQUEST_HEADERS') );
        }
        
        header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, DELETE");
        
        $this->setResponseData( '' );
    }
}

?>