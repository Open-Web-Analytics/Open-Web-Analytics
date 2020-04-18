<?php

/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */

require_once(OWA_BASE_MODULE_DIR.'users.php');

/**
 * Tracked Sites REST Controller
 * 
 * A GET REST method for obtaiing the list of tracked web sites
 *
 */
class owa_usersRestController extends owa_usersController {
    

    function success() {
	    
	    http_response_code(200);
	    
        $this->setView( 'base.usersRest' );
    }
}


require_once(OWA_DIR.'owa_view.php');

/**
 * Sites Roster View
 * 
 */
class owa_usersRestView extends owa_restApiView {
        
    function render() {
        
        $users = $this->get('users_objs');
       
        $users_sanitized = [];
        
        if ( $users ) {
	        
	        foreach ( $users as $k => $user ) {
		        
		        $users_sanitized[ $k ] = $user->getProperties( ['temp_passkey', 'password'] );
	        }
        }
        
        $this->setResponseData( $users_sanitized );
    }
}

?>