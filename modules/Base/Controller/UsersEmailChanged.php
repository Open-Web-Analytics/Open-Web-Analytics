<?php
namespace OWA\Module\Base\Controller;

/**
 * The mail that tells the OLD address of an account that the account's
 * address was changed, sent on base.email_address_changed.
 *
 * MyProfileSave changes the address and then raises the event with the user,
 * both addresses, when and the client address.
 */
class UsersEmailChanged extends \OWA\Core\Controller {

    /** Run by its handler through performAction(), never by a request. */
    const IN_PROCESS_ONLY = true;

    public function action() {

        $event = $this->getParam( 'event' );

        $old = (string) $event->get( 'old_email_address' );

        if ( $old === '' ) {

            return;
        }

        $this->setView( 'base.usersEmailChanged' );
        $this->set( 'email_address', $old );
        $this->set( 'user_id', (string) $event->get( 'user_id' ) );
        $this->set( 'new_email_address', (string) $event->get( 'new_email_address' ) );
        $this->set( 'changed', date( 'Y-m-d H:i T', (int) $event->get( 'changed_at' ) ) );
        $this->set( 'ip', (string) $event->get( 'ip' ) );
    }
}
