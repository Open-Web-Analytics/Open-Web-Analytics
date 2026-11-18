<?php
namespace OWA\Module\Base\Controller;

/**
 * New user Account Controller
 */

class UsersSetPassword extends \OWA\Core\Controller {

    public function action() {

        $event = $this->getParam('event');

        /**
         * @var \OWA\Module\Base\Classes\UserManager $userManager
         */
        $userManager = new \OWA\Module\Base\Classes\UserManager();
        $u = $userManager->updateUserPassword([
            'temp_passkey' => $event->get('key'),
            'password' => $event->get('password'),
            'user_id'  => $event->get('user_id')
        ]);

        // Returned unconditionally below, but only populated when the user
        // loaded. Returning an undefined variable made the failure path hand
        // back null instead of an empty payload.
        $data = [];

        if ($u !== false) {
            $data['view'] = 'base.usersSetPassword';
            $data['view_method'] = 'email';
            $data['ip'] = $event->get('ip');
            $data['subject'] = 'Password Change Complete';
            $data['email_address'] = $u->get('email_address');
            
           
        }
        
        return $data;
    }

}
