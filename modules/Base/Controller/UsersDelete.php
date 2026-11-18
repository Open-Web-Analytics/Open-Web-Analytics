<?php
namespace OWA\Module\Base\Controller;

/**
 * Delete User Controller
 */

class UsersDelete extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
        parent::__construct($params);
    }
    
    public function validate() {
        
        $this->addValidation('user_id', $this->getParam('user_id'), 'required', ['stopOnError'	=> true]);
        $this->addValidation('user_id', $this->getParam('user_id'), 'isNotCurrentUser');
    }

    public function action() {

        $userManager = new \OWA\Module\Base\Classes\UserManager();

        // add check here to ensure that this is not the default user....
        $userManager->deleteUser($this->getParam('user_id'));
    }
    
    public function success() {
        
        $this->setRedirectAction('base.users');
        $this->setStatusCode( 3004 );
    }
}
