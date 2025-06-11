<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersProfileController extends owa_controller {

    function __construct($params) {

        $this->setRequiredCapability('edit_users');
        return parent::__construct($params);
    }

    function action() {

        // This needs form validation in a bad way.
        //Check to see if user is passed by constructor or else fetch the object.
        if ($this->getParam('user_id')) {
            $u = owa_coreAPI::entityFactory('base.user');
            $u->getByColumn('user_id', $this->getParam('user_id'));
            $this->set('profile', $u->_getProperties());
            $this->set('edit', true);
            $this->set('user_id', $this->getParam('user_id'));
        } else {
            $this->set('edit', false);
            $this->set('profile', array());
        }

        $this->setView('base.options');
        $this->setSubview('base.usersProfile');

    }

}

/**
 * OWA User Profile View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersProfileView extends owa_view {

    function __construct() {

        return parent::__construct();
    }

    function render($data) {
        $user = $this->get('profile');
        $this->body->set('isAdmin', false);

        if ($this->get('edit')) {
            $this->body->set('headline', 'Edit user profile');
            $this->body->set('action', 'base.usersEdit');
            $this->body->set('edit', true);
            $userEntity =  owa_coreAPI::entityFactory( 'base.user' );
            $userEntity->load( $user['id'] );
            $this->body->set('isAdmin', $userEntity->isOWAAdmin());
        } else {
            $this->body->set('headline', 'Add a new user profile');
            $this->body->set('action', 'base.usersAdd');
            $this->body->set('edit', false);
        }
        //page title
        $this->t->set('page_title', 'User Profile');
        $this->body->set_template('users_addoredit.tpl');
        $this->body->set('roles', owa_coreAPI::getAllRoles());

        $this->body->set('user', $user);

    }
}

?>