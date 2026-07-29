<?php
namespace OWA\Module\Base\View;


/**
 * Open Web Analytics - The Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 * Website: http://www.openwebanalytics.con
 */



/**
 * Sites Roster View
 * 
 */
class UsersRest extends \OWA\Core\View\RestApi {
        
    function render() {
        
        $users = $this->get('users_objs');
       
        $users_sanitized = [];
        
        if ( $users ) {
	        
	        foreach ( $users as $k => $user ) {
		        
		        $users_sanitized[ $k ] = $user->getProperties( ['temp_passkey', 'password', 'api_key'] );
	        }
        }
        
        $this->setResponseData( $users_sanitized );
    }
}

?>