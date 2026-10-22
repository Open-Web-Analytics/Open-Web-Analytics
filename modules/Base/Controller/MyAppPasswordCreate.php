<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Makes an application password for the signed-in user, then redirects to the
 * list, which shows its token once.
 *
 * A redirect, so reloading the page that shows the token re-runs a GET rather
 * than this POST and makes no second password. The token crosses the redirect
 * sealed in a short-lived cookie (AppPasswords::sealReveal), never in the URL,
 * where it would land in the browser history, the access logs and any Referer
 * sent from the next page.
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

        $this->writeRevealCookie( \OWA\Module\Base\Classes\AppPasswords::sealReveal( $made, $user_id ),
            time() + \OWA\Module\Base\Classes\AppPasswords::REVEAL_TTL );

        $this->set( 'siteId', (string) $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.myAppPasswords' );
    }

    private function refuse( $code ) {

        $this->set( 'error_code', $code );
        $this->set( 'siteId', (string) $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.myAppPasswords' );
    }
}
