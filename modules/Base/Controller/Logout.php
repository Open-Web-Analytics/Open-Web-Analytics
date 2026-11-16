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
 * Logout Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Logout extends \OWA\Core\Controller {

    /**
     * Signs the user out of every browser session, on every device, and
     * clears this browser's cookie.
     */
    function action() {

        $auth = \OWA\Core\Auth::get_instance();
        $cu   = \OWA\Core\CoreAPI::getCurrentUser();

        if ( $cu->isAuthenticated() && $auth->getAuthMethod() === 'cookies' ) {

            $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
            $user->load( $cu->getUserData( 'user_id' ), 'user_id' );

            if ( $user->wasPersisted() ) {

                $user->endSessions();
                $user->update();
            }
        }

        $auth->deleteCredentials();
        $this->setRedirectAction('base.loginForm');
    }
}

?>
