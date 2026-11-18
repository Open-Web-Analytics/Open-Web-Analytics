<?php
namespace OWA\Module\Base\Controller;

/**
 * Password Reset Request Controller
 */

class PasswordResetRequest extends \OWA\Core\Controller {

    public function validate()
    {
        $this->addValidation('email_address', $this->getParam('email_address'), 'emailAddress', ['stopOnError' => true]);

        /*
         * There is deliberately NO check that the address belongs to a user.
         *
         * This form is unauthenticated, and an entityExists validation here
         * answered "A user with that email address does not exist" for an
         * unknown address and "an e-mail has been sent to X" for a known one --
         * which let anyone confirm whether an address is registered, one guess
         * at a time.
         *
         * An unknown address now takes the same path and gets the same reply;
         * UsersResetPassword simply finds no user and sends nothing.
         */
    }

    public function action() {

        // Log password reset request to event queue
        $ed = \OWA\Core\CoreAPI::getEventDispatch();

        $event = $ed->makeEvent( 'base.reset_password' );
        $event->set('email_address', $this->getParam( 'email_address' ) );
        $ed->notify( $event );

        // return view
        $this->setView( 'base.passwordResetForm', PasswordResetForm::page() );
        $email_address = trim((string) $this->getParam('email_address'));
        $msg = $this->getMsg(2000, ['message' => [$email_address]]);
        $this->set('status_msg', $msg);
    }

    public function errorAction() {

        $this->setView( 'base.passwordResetForm', PasswordResetForm::page() );
        $email_address = trim((string) $this->getParam('email_address'));
        $this->set('error_msg', $this->getMsg(2001, ['message' => [$email_address]]));
    }
}
