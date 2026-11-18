<?php
namespace OWA\Module\Base\Controller;

/**
 * The mail that confirms a password change, sent on base.set_password.
 *
 * UsersChangePassword sets the password and then raises the event with the
 * user and the IP address only.
 */

class UsersSetPassword extends \OWA\Core\Controller {

    public function action() {

        $event = $this->getParam('event');

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $event->get('user_id'));

        $data = [];

        if ( $u->get('id') ) {
            $data['view'] = 'base.usersSetPassword';
            $data['view_method'] = 'email';
            $data['ip'] = $event->get('ip');
            $data['subject'] = 'Password Change Complete';
            $data['email_address'] = $u->get('email_address');
        }

        return $data;
    }

}
