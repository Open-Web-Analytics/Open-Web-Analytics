<?php
namespace OWA\Module\Base\Controller;

/**
 * Tracked Sites REST Controller
 * 
 * A GET REST method for obtaiing the list of tracked web sites
 *
 */
class UsersRest extends \OWA\Module\Base\Controller\Users {
    

    public function success() {
        
        http_response_code(200);
        
        $this->setView( 'base.usersRest' );
    }
}
