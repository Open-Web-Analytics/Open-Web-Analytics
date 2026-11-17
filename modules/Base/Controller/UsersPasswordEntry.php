<?php
namespace OWA\Module\Base\Controller;


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
 * Change Password Controller
 * 
 * handles from input from the Change password screen
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UsersPasswordEntry extends \OWA\Core\Controller {

    /**
     * The form, which UsersChangePassword draws again when a submission is
     * refused. Signed out: the person on it has no password yet.
     *
     * @param \OWA\Core\Base $owa reads the headline from the message catalogue
     */
    public static function page( \OWA\Core\Base $owa ) {

        return [
            'template' => 'users_change_password.php',
            'wrapper'  => 'wrapper_public.php',
            'title'    => \OWA\Core\CoreAPI::t( 'OWA Password Entry' ),
            'vars'     => [ 'headline' => $owa->getMsg( 3005 ), 'key' ],
        ];
    }

    /**
     * Why a link's key cannot set a password: 2012 when it has expired, 2011
     * when it is not a key at all; null when it is current. Whether an account
     * holds it is checked when the form is posted (UsersChangePassword).
     */
    public static function keyProblem( $key ) {

        if ( ! \OWA\Module\Base\Entity\User::cleanTempPasskey( $key ) ) {

            return 2011;
        }

        return \OWA\Module\Base\Entity\User::tempPasskeyIsCurrent( $key ) ? null : 2012;
    }

    /**
     * The form, for a link that can still set a password. One that cannot is
     * told so on the login form before anyone types a password into it.
     */
    function action() {

        $problem = self::keyProblem( $this->getParam('k') );

        if ( $problem ) {

            $this->setRedirectAction('base.loginForm');
            $this->set('error_code', $problem);

            return;
        }

        $this->set('key', $this->getParam('k'));
        $this->setView( 'base.usersPasswordEntry', self::page( $this ) );
    }


}



?>
