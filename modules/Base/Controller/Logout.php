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
     *
     * Only with a nonce for base.logout, which every Log out link OWA draws
     * carries. Without one -- a link from anywhere else -- the user is asked
     * first, by a form that carries one. Nobody signed in goes to the login
     * form.
     */
    public function action() {

        $auth = \OWA\Core\Auth::get_instance();
        $cu   = \OWA\Core\CoreAPI::getCurrentUser();

        if ( ! $cu->isAuthenticated() ) {

            $auth->deleteCredentials();
            $this->setRedirectAction('base.loginForm');

            return;
        }

        if ( ! $this->verifyNonce( $this->getParam('nonce'), 'base.logout' ) ) {

            $this->setView( 'base.logoutConfirm', [
                'template' => 'logout_confirm.php',
                'wrapper'  => 'wrapper_public.php',
                'title'    => \OWA\Core\CoreAPI::t( 'Log out' ),
                'vars'     => [ 'back_url' ],
            ] );
            // The referring page, when it is one of this install's.
            $this->set('back_url', (string) $this->getReferringPage());

            return;
        }

        if ( $auth->getAuthMethod() === 'cookies' ) {

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
