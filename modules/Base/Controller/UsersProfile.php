<?php
namespace OWA\Module\Base\Controller;

/**
 * Edit User Controller
 */

class UsersProfile extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_users');
        parent::__construct($params);
    }

    public function action() {

        // This needs form validation in a bad way.
        //Check to see if user is passed by constructor or else fetch the object.
        if ($this->getParam('user_id')) {
            $u = \OWA\Core\CoreAPI::entityFactory('base.user');
            $u->getByColumn('user_id', $this->getParam('user_id'));
            $this->set('profile', $u->_getProperties());
            $this->set('edit', true);
            $this->set('user_id', $this->getParam('user_id'));
        } else {
            $this->set('edit', false);
            $this->set('profile', []);
        }
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview('base.usersProfile');

    }

}
