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
class UsersRest extends \OWA\Module\Base\Controller\Users {
    

    function success() {
	    
	    http_response_code(200);
	    
        $this->setView( 'base.usersRest' );
    }
}
