<?php
namespace OWA\Module\Base\View;

/**
 * The mail that confirms a password change, to the user whose password it was.
 */

class UsersSetPassword extends \OWA\Core\View\Mail {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        $this->t->set_template('wrapper_email.php');
        $this->body->set_template('users_set_password_email.php');
        $this->body->set('ip', $data['ip']);
        $this->setMailSubject( $data['subject'] );
        $this->addMailToAddress( $data['email_address'] );
    }
}
