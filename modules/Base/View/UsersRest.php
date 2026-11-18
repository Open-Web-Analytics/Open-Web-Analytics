<?php
namespace OWA\Module\Base\View;

/**
 * Sites Roster View
 * 
 */
class UsersRest extends \OWA\Core\View\RestApi {
        
    public function render() {

        // Private fields are declared on the User entity, so setResponseData()
        // applies them here and on every other route returning a user.
        $this->setResponseData( $this->get('users_objs') );
    }
}
