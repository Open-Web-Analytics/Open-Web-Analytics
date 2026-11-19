<?php
namespace OWA\Module\Base\Controller;

/**
 * New user Account Controller
 */

class UsersNewAccount extends \OWA\Core\Controller {

    /** Run by its handler through performAction(), never by a request. */
    const IN_PROCESS_ONLY = true;

    public function action() {

        $event = $this->getParam('event');

        // return email view
        $data['user_id']= $event->get('user_id');
        $data['email_address']= $event->get('email_address');
        $data['temp_passkey'] = $event->get('temp_passkey');
        $data['subject'] = \OWA\Core\CoreAPI::t( 'OWA User Account Setup' );
        $data['view'] = 'base.usersNewAccount';
        $data['view_method'] = 'email';
        $data['name'] = $event->get('real_name');

        return $data;
    }

}
