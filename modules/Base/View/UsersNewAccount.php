<?php
namespace OWA\Module\Base\View;

/**
 * New Account Notification View
 */

class UsersNewAccount extends \OWA\Core\View\Mail {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        $this->t->set_template('wrapper_email.php');
        $this->body->set_template('users_new_account_email.php');
        $this->body->set('user_id', $data['user_id']);
        $this->body->set('key', $data['temp_passkey']);
        // mailer specific
        $this->setMailSubject($data['subject']);
        $this->addMailToAddress($data['email_address'], $this->get('name'));

    }
}
