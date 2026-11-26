<?php
namespace OWA\Module\Base\Controller;

/**
 * The e-mail that tells an account an application password was made for it
 * (AppPasswords::announce()): its name, access, when and from where, and a
 * link that revokes it without signing in (base.appPasswordRevokeLink).
 */
class AppPasswordCreatedMail extends \OWA\Core\Controller {

    /** Run by AppPasswords::announce() through performAction(), never by a request. */
    const IN_PROCESS_ONLY = true;

    public function action() {

        $made = (array) $this->getParam( 'made' );

        $u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $u->getByColumn( 'user_id', (string) ( $made['user_id'] ?? '' ) );

        if ( (string) $u->get( 'email_address' ) === '' ) {

            return;
        }

        $this->setView( 'base.appPasswordCreatedMail' );
        $this->set( 'email_address', $u->get( 'email_address' ) );
        $this->set( 'name', (string) ( $made['name'] ?? '' ) );
        $this->set( 'scope', \OWA\Module\Base\Classes\AppPasswords::scopes()[ $made['scope'] ?? '' ] ?? '' );
        $this->set( 'created', date( 'Y-m-d H:i T', (int) ( $made['created_at'] ?? 0 ) ) );
        $this->set( 'from', (string) $this->getParam( 'from' ) );
        $this->set( 'lookup_id', (string) ( $made['lookup_id'] ?? '' ) );
        $this->set( 'key', (string) $this->getParam( 'key' ) );
    }
}
