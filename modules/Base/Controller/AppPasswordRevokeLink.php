<?php
namespace OWA\Module\Base\Controller;

/**
 * Revokes an application password from the link in the e-mail sent when it
 * was made (AppPasswords::announce()), signed out: the link's key is what
 * authorises it (AppPasswords::forRevokeLink()), so a password made by
 * someone who has the account's session can be revoked by whoever reads the
 * account's mail.
 *
 * A GET asks; only the form's POST revokes, so a mail scanner that follows
 * the link revokes nothing.
 */
class AppPasswordRevokeLink extends \OWA\Core\Controller {

    public function action() {

        $id  = (string) $this->getParam( 'id' );
        $key = (string) $this->getParam( 'key' );
        $row = \OWA\Module\Base\Classes\AppPasswords::forRevokeLink( $id, $key );

        $this->setView( 'base.appPasswordRevokeLink', [
            'template' => 'app_password_revoke_link.php',
            'wrapper'  => 'wrapper_public.php',
            'title'    => \OWA\Core\CoreAPI::t( 'Revoke an application password' ),
        ] );

        $this->set( 'id', $id );
        $this->set( 'key', $key );

        if ( ! $row ) {

            $this->set( 'outcome', 'gone' );

            return;
        }

        $this->set( 'name', (string) $row['name'] );

        $posted = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) === 'POST';

        if ( ! $posted ) {

            $this->set( 'outcome', 'ask' );

            return;
        }

        $revoked = \OWA\Module\Base\Classes\AppPasswords::revoke( $id, (string) $row['user_id'] );

        $this->set( 'outcome', $revoked ? 'revoked' : 'gone' );
    }
}
