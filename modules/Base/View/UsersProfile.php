<?php
namespace OWA\Module\Base\View;

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

class UsersProfile extends \owa_view {

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
            $userEntity =  \owa_coreAPI::entityFactory( 'base.user' );
            $userEntity->load( $user['id'] );
            $this->body->set('isAdmin', $userEntity->isOWAAdmin());
        } else {
            $this->body->set('headline', 'Add a new user profile');
            $this->body->set('action', 'base.usersAdd');
            $this->body->set('edit', false);
        }
        //page title
        $this->t->set('page_title', 'User Profile');
        $this->body->set_template('users_addoredit.php');
        $this->body->set('roles', \owa_coreAPI::getAllRoles());

        $this->body->set('user', $user);

    }
}
