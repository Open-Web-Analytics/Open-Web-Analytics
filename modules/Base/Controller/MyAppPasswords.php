<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The signed-in user's application passwords: the list, the form that makes
 * one, and a revoke button on each.
 *
 * Like base.myProfile it takes no user_id. It always shows whoever is signed
 * in, so there is no account to authorise.
 *
 * base.myAppPasswordCreate redirects here with the new token sealed in the
 * REVEAL_COOKIE cookie. The first GET to read it shows the token and expires
 * the cookie, so that page is the only place a token is ever shown.
 */
class MyAppPasswords extends \OWA\Core\Controller {

    /** Without the install's namespace prefix, which is added to it. */
    const REVEAL_COOKIE = 'app_password_reveal';

    function __construct( $params ) {

        // Every signed-in role makes its own; see base.myProfile.
        $this->setRequiredCapability( 'view_site_list' );

        parent::__construct( $params );
    }

    function action() {

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $app_passwords = \OWA\Module\Base\Classes\AppPasswords::forUser( $user_id );

        $this->set( 'app_passwords', $app_passwords );
        $this->set( 'new_app_password', $this->takeRevealed( $user_id, $app_passwords ) );
        $this->set( 'scopes', \OWA\Module\Base\Classes\AppPasswords::scopes() );
        $this->set( 'enabled', (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'app_passwords_enabled' ) );
        $this->set( 'require_https', (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'app_passwords_require_https' ) );
        $this->set( 'api_url', (string) \OWA\Core\CoreAPI::getSetting( 'base', 'rest_api_url' ) );

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $siteId ) ) );
        $this->set( 'hierarchy_tier', 0 );
        $this->set( 'hierarchy_root_label', 'My Preferences' );
        $this->set( 'siteId', $siteId );

        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.myAppPasswords' );
    }

    /**
     * The password just made, read once from its cookie, or null.
     *
     * The cookie is expired whatever it holds. Nothing is shown for a password
     * revoked since, or one sealed for someone else.
     */
    protected function takeRevealed( $user_id, array $app_passwords ) {

        $name   = self::revealCookieName();
        $sealed = (string) ( $_COOKIE[ $name ] ?? '' );

        if ( $sealed === '' ) {

            return null;
        }

        unset( $_COOKIE[ $name ] );
        $this->writeRevealCookie( '', time() - 3600 );

        $made = \OWA\Module\Base\Classes\AppPasswords::openReveal( $sealed, $user_id );

        if ( ! $made || ! in_array( $made['lookup_id'] ?? null, array_column( $app_passwords, 'lookup_id' ), true ) ) {

            return null;
        }

        // A cache that kept this page would keep the token.
        if ( ! headers_sent() ) {

            header( 'Cache-Control: no-store' );
        }

        return $made;
    }

    /**
     * Host-only, HttpOnly and SameSite=Strict: only this install's own pages
     * receive it, and no script can read it.
     */
    protected function writeRevealCookie( $value, $expires ) {

        if ( headers_sent() ) {

            return;
        }

        setcookie( self::revealCookieName(), $value, array(
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => \OWA\Core\Lib::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ) );
    }

    protected static function revealCookieName() {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . self::REVEAL_COOKIE;
    }
}
