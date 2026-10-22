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
 * base.myAppPasswordCreate extends this to draw the same screen with the new
 * token on it. That response is the only place a token is ever shown.
 */
class MyAppPasswords extends \OWA\Core\Controller {

    function __construct( $params ) {

        // Every signed-in role makes its own; see base.myProfile.
        $this->setRequiredCapability( 'view_site_list' );

        parent::__construct( $params );
    }

    function action() {

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $this->set( 'app_passwords', \OWA\Module\Base\Classes\AppPasswords::forUser( $user_id ) );
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
}
