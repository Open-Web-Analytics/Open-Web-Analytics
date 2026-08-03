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

        // Private fields are declared on the User entity, so setResponseData()
        // applies them here and on every other route returning a user.
        $this->setResponseData( $this->get('users_objs') );
    }
}

?>