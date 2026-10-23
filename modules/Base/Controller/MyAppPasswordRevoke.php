<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Revokes one of the signed-in user's own application passwords.
 *
 * The user comes from the session, so a lookup id that belongs to someone
 * else revokes nothing. base.userAppPasswordRevoke is the admin's version.
 */
class MyAppPasswordRevoke extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'view_site_list' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    function action() {

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $revoked = \OWA\Module\Base\Classes\AppPasswords::revoke(
            (string) $this->getParam( 'lookup_id' ), $user_id );

        $this->set( $revoked ? 'status_code' : 'error_code', $revoked ? 3213 : 3214 );
        $this->set( 'siteId', (string) $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.myAppPasswords' );
    }
}
