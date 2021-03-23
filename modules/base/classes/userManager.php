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

/**
 * User Manager Class
 * 
 * handels the common tasks associated with creating and manipulating user accounts
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_userManager extends owa_base {
    /**
     * owa_userManager constructor.
     */
    public function __construct() {

        return parent::__construct();
    }

    public function createNewUser($user_params) {

        if ( isset( $user_params['password'] ) ) {
            $password = $user_params['password'];
        } else {
            $password = '';
        }

        // save new user to db
        $u = owa_coreAPI::entityFactory('base.user');
        $ret = $u->createNewUser(
                $user_params['user_id'],
                $user_params['role'],
                $password,
                $user_params['email_address'],
                $user_params['real_name']
        );

        if ( $ret ) {
            return $u;
        } else {
            return false;
        }

    }

    public function updateUserPassword($user_params)
    {
        $u = owa_coreAPI::entityFactory('base.user');

        if (!isset($user_params['temp_passkey']) && !isset($user_params['user_id'])) {
            owa_coreAPI::error( "No user identification given!" );
            return false;
        }

        if (isset($user_params['temp_passkey'])) {
            $u->getByColumn('temp_passkey', $user_params['temp_passkey']);
        }

        if (isset($user_params['user_id'])) {
            $u->getByColumn('user_id', $user_params['user_id']);
        }

        $u->set('temp_passkey', $u->generateTempPasskey($user_params['user_id']));
        $u->set('password', owa_lib::encryptPassword($user_params['password']));
        $ret = $u->update();

        return $ret ? $u : false;

    }

    public function deleteUser($user_id) {

        $u = owa_coreAPI::entityFactory('base.user');

        $ret = $u->delete($user_id, 'user_id');

        if ( $ret ) {
            return true;
        } else {
            return false;
        }
    }
}

?>