<?php
namespace OWA\Module\Base\Controller;


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
class CorsPreflight extends \OWA\Core\Controller {
    
    function success() {
	    
	    http_response_code(200);
	    
	    $service = \OWA\Core\CoreAPI::serviceSingleton();
	    $this->set('HTTP_ACCESS_CONTROL_REQUEST_HEADERS', $service->request->getServerParam('HTTP_ACCESS_CONTROL_REQUEST_HEADERS') );
	    $service->request->getRequestType();
	    
        $this->setView( 'base.corsPreflight' );
    }
}
