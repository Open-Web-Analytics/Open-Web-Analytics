<?php
namespace OWA\Module\Base\Controller;

/**
 * Login Form Controller
 */
class LoginForm extends \OWA\Core\Controller {

    public function action() {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        $this->set('go', $this->getParam('go'));
        $this->set('user_id', $cu->getUserData('user_id'));
        $this->setView('base.loginForm');
    }
}
