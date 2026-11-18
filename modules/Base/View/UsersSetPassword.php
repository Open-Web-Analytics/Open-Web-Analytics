<?php
namespace OWA\Module\Base\View;

/**
 * Set Password Notification View
 */

class UsersSetPassword extends \OWA\Core\View {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        $this->t->set_template('wrapper_email.php');
        $this->body->set_template('users_set_password_email.php');
        $this->body->set('ip', $data['ip']);
    }
}
