<?php
namespace OWA\Module\Base\View;


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


/**
 * cors preflight response.
 * 
 */
class CorsPreflight extends \OWA\Core\View\RestApi {
        
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