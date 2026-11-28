<?php
namespace OWA\Module\Base\Controller;

/**
 * Delete User Controller
 *
 * Nobody deletes their own account, and the last account with the admin role
 * is not deleted (UserManager::deleteRefusal()). DeleteUserRest, the REST
 * route, extends this.
 */

class UsersDelete extends \OWA\Core\AdminController {

    /** @var int the message code of a refused delete, 0 until one is refused */
    protected $refusal = 0;

    public function __construct($params) {

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
        parent::__construct($params);
    }
    
    public function validate() {
        
        $this->addValidation('user_id', $this->getParam('user_id'), 'required', ['stopOnError'  => true]);
    }

    public function action() {

        $user_id = (string) $this->getParam( 'user_id' );
        $acting  = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $userManager = $this->userManager();
        $this->refusal = $userManager->deleteRefusal( $user_id, $acting );

        if ( $this->refusal ) {

            $this->set( 'validation_errors', [ 'user_id' => $this->getMsg( $this->refusal )['message'] ] );
            $this->errorAction();

            // Non-empty, so finishActionCall() does not go on to success().
            return $this->data;
        }

        $userManager->deleteUser( $user_id, $acting );
    }

    /** @return \OWA\Module\Base\Classes\UserManager */
    protected function userManager() {

        return new \OWA\Module\Base\Classes\UserManager();
    }
    
    public function success() {
        
        $this->setRedirectAction('base.users');
        $this->setStatusCode( 3004 );
    }

    /** Back to the user list, saying why when the delete was refused. */
    public function errorAction() {

        $this->setRedirectAction( 'base.users' );

        if ( $this->refusal ) {

            $this->set( 'error_code', $this->refusal );
        }
    }
}
