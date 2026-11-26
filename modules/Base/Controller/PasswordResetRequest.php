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
         * This form is unauthenticated, and answering differently for an unknown
         * address and a known one would let anyone confirm whether an address is
         * registered, one guess at a time. An unknown address takes the same path
         * and gets the same reply; UsersResetPassword finds no user and sends
         * nothing.
         */
    }

    public function action() {

        /*
         * After the reply: the handler writes a token and sends mail only for
         * a known address, and the time that takes would say which it was.
         */
        $email_address = $this->getParam( 'email_address' );

        \OWA\Core\CoreAPI::afterResponse( static function () use ( $email_address ) {

            $ed    = \OWA\Core\CoreAPI::getEventDispatch();
            $event = $ed->makeEvent( 'base.reset_password' );
            $event->set( 'email_address', $email_address );
            $ed->notify( $event );
        } );

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
