<?php
namespace OWA\Module\Base\View;

/**
 * Reset Password Notification View
 */

class UsersResetPassword extends \OWA\Core\View\Mail {
    
    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {
        
        $this->t->set_template('wrapper_email.php');
        $this->body->set_template('users_reset_password_email.php');
        $this->body->set('key', $this->get('key'));
        $this->setMailSubject( \OWA\Core\CoreAPI::t( 'Your New OWA Password' ) );
        $this->addMailToAddress($this->get('email_address'));
    }
}
