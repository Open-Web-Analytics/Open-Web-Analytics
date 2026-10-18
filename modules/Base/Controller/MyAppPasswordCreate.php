<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Makes an application password for the signed-in user and shows its token.
 *
 * NO REDIRECT ON SUCCESS. The token is drawn into this response, which is the
 * only time it exists outside the person's own copy. A redirect would have to
 * carry it in the URL, where it would land in the browser history, the access
 * logs and any Referer sent from the next page.
 *
 * A refusal redirects back to the list as usual: it has no secret to show.
 */
class MyAppPasswordCreate extends MyAppPasswords {

    function __construct( $params ) {

        // Inherited from the list as well; a nonce-guarded controller declares its own.
        $this->setRequiredCapability( 'view_site_list' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    function action() {

        $name  = trim( (string) $this->getParam( 'name' ) );
        $scope = (string) $this->getParam( 'scope' );

        if ( $name === '' ) {

            return $this->refuse( 3215 );
        }

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $made = \OWA\Module\Base\Classes\AppPasswords::create( $user_id, $name, $scope );

        if ( ! $made ) {

            return $this->refuse( 3216 );
        }

        // A cache that kept this page would keep the token.
        if ( ! headers_sent() ) {

            header( 'Cache-Control: no-store' );
        }

        parent::action();

        $this->set( 'new_app_password', $made );
    }

    private function refuse( $code ) {

        $this->set( 'error_code', $code );
        $this->set( 'siteId', (string) $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.myAppPasswords' );
    }
}
