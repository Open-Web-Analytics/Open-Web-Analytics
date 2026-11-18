<?php
namespace OWA\Module\Base\Controller;

/**
 * Users Roster View
 */
class Users extends \OWA\Core\AdminController {
        
    public function __construct($params) {
        
        $this->setRequiredCapability('edit_users');
        parent::__construct($params);
    }
    
    public function action() {
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName() );
        $db->selectColumn("*");
        $users = $db->getAllRows();
        
        // remove this after switch to REST API in Admin Interface
        $this->set('users', $users);
        
        $user_objs = \OWA\Core\CoreAPI::loadEntitiesFromArray( $users, 'base.user');
        $this->set('users_objs', $user_objs );
    }
    
    public function success() {
        
        /*
         * The settings chrome: user accounts live in the Organization, so this
         * is an Organization screen reached from the site control's nav. The
         * install settings nav beside it would offer a way out that has nothing
         * to do with where you are.
         */
        $owa_site_id = $this->resolveCurrentSiteId();
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview( 'base.users', [
            'template' => 'users.php',
            'vars'     => [ 'headline' => \OWA\Core\CoreAPI::t( 'User Roster' ), 'users' ] ] );
    }
}
