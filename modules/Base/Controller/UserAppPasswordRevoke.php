<?php
namespace OWA\Module\Base\Controller;

/**
 * Revokes another user's application password, from their user screen.
 *
 * Revoke only. An administrator cannot make a password for someone else, and
 * never sees a token: what they get is the list of names and when each was
 * last used, which is enough to decide one should go.
 */
class UserAppPasswordRevoke extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_users' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    public function action() {

        $user_id = (string) $this->getParam( 'user_id' );

        $revoked = \OWA\Module\Base\Classes\AppPasswords::revoke(
            (string) $this->getParam( 'lookup_id' ), $user_id );

        $this->set( $revoked ? 'status_code' : 'error_code', $revoked ? 3213 : 3214 );
        $this->set( 'user_id', $user_id );
        $this->set( 'edit', true );
        $this->set( 'siteId', (string) $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.usersProfile' );
    }
}
