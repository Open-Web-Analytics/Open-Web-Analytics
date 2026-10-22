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

    /** Nothing to do: the answer is the headers the view sends. */
    function action() {

    }

    function success() {
	    
	    http_response_code(200);

        $this->setView( 'base.corsPreflight' );
    }
}
