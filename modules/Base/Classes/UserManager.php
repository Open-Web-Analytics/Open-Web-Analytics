<?php
namespace OWA\Module\Base\Classes;


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

class UserManager extends \OWA\Core\Base {
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
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
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
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');

        if (!isset($user_params['temp_passkey']) && !isset($user_params['user_id'])) {
            \OWA\Core\CoreAPI::error( "No user identification given!" );
            return false;
        }

        if (isset($user_params['temp_passkey'])) {
            $u->getByColumn('temp_passkey', $user_params['temp_passkey']);
        }

        if (isset($user_params['user_id'])) {
            $u->getByColumn('user_id', $user_params['user_id']);
        }

        $u->set('temp_passkey', $u->generateTempPasskey($user_params['user_id']));
        $u->set('password', \OWA\Core\Lib::encryptPassword($user_params['password']));
        $ret = $u->update();

        return $ret ? $u : false;

    }

    /**
     * Delete a user, and the site access that only meant anything for them.
     *
     * The grants in owa_site_user were left behind, keyed on a user row that
     * no longer existed. Nothing reads them -- every screen lists users and
     * looks their grants up, never the reverse -- so they accumulated
     * invisibly, and Property Access Management could not clear them either:
     * it submits a delta of the users it RENDERED, and a deleted user is never
     * rendered, so its row survived every save forever.
     *
     * They are also not quite inert. User ids are auto-increment rather than
     * derived from the username, so re-creating a deleted username does NOT
     * inherit its access -- but a restore from backup can reset the counter to
     * MAX(id)+1, and then a new user can be minted onto a deleted user's id and
     * pick up whatever it was granted.
     *
     * Grants first: if that succeeds and the user delete then fails, the user
     * keeps their account and loses their site access, which is recoverable by
     * re-granting. The other order can leave access attached to nothing.
     */
    public function deleteUser($user_id) {

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->load( $user_id, 'user_id' );

        if ( $u->wasPersisted() ) {

            $this->deleteSiteAccessFor( $u->get('id') );
        }

        $ret = $u->delete($user_id, 'user_id');

        if ( $ret ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Remove every site grant held by one user row.
     *
     * @param  int|string $id  the user's own row id, not their user_id
     */
    private function deleteSiteAccessFor( $id ) {

        if ( ! $id ) {

            return;
        }

        $siteUser = \OWA\Core\CoreAPI::entityFactory('base.site_user');

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( $siteUser->getTableName() );
        $db->where( 'user_id', $id );
        $db->executeQuery();

        // The assigned-users cache is keyed by SITE, and this revoked across
        // all of them at once, so there is no single key to evict.
        \OWA\Module\Base\Entity\Site::forgetAssignedUsers();
    }
}

?>