<?php
namespace OWA\Module\Base\Controller;

/**
 * Reset Password Controller
 */

class UsersResetPassword extends \OWA\Core\Controller {
        
    public function action() {
    
        $event = $this->getParam('event');
        
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('email_address', $event->get('email_address'));

        /*
         * An address with no account reaches here now, because the request form
         * deliberately no longer reports whether one exists. Stop here rather
         * than mint a passkey for nobody and run an UPDATE keyed on an empty id.
         *
         * Nothing is reported back either: the caller shows the same message
         * whether or not this found anyone, which is the point.
         */
        if ( ! $u->get('id') ) {

            \OWA\Core\CoreAPI::debug( 'Password reset requested for an address with no account.' );

            return;
        }

        /*
         * The request form is unauthenticated, so anyone can ask for any
         * address as often as they like. One reset email per account per
         * RESET_RESEND_AFTER: a request inside that keeps the key already sent
         * and sends nothing. The requester sees the same reply either way.
         */
        if ( \OWA\Module\Base\Entity\User::resetMailedRecently( $u->get( 'temp_passkey' ) ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Password reset for user %s not sent: one was sent less than %d minutes ago.',
                $u->get( 'user_id' ), \OWA\Module\Base\Entity\User::RESET_RESEND_AFTER / 60 ) );

            return;
        }

        $u->set('temp_passkey', \OWA\Module\Base\Entity\User::newTempPasskey( \OWA\Module\Base\Entity\User::RESET_TTL ) );
        $status = $u->update();
        \OWA\Core\CoreAPI::debug('status: '.$status);
        
        if ($status === true) {
    
            $this->setView( 'base.usersResetPassword' );
            $this->set( 'key', $u->get('temp_passkey' ) );
            $this->set( 'email_address', $u->get('email_address' ) );
            
        } else {
        
            \OWA\Core\CoreAPI::debug( "could not update password in db." );
        }
    }
    
}
