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
 * Password Reset Request Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class PasswordResetRequest extends \OWA\Core\Controller {

    public function validate()
    {
        $this->addValidation('email_address', $this->getParam('email_address'), 'emailAddress', ['stopOnError' => true]);

        /*
         * There is deliberately NO check that the address belongs to a user.
         *
         * This form is unauthenticated, and an entityExists validation here
         * answered "A user with that email address does not exist" for an
         * unknown address and "an e-mail has been sent to X" for a known one --
         * which let anyone confirm whether an address is registered, one guess
         * at a time.
         *
         * An unknown address now takes the same path and gets the same reply;
         * UsersResetPassword simply finds no user and sends nothing.
         */
    }

    function action() {

        // Log password reset request to event queue
        $ed = \OWA\Core\CoreAPI::getEventDispatch();

        $event = $ed->makeEvent( 'base.reset_password' );
        $event->set('email_address', $this->getParam( 'email_address' ) );
        $ed->notify( $event );

        // return view
        $this->setView('base.passwordResetForm');
        $email_address = trim((string) $this->getParam('email_address'));
        $msg = $this->getMsg(2000, ['message' => [$email_address]]);
        $this->set('status_msg', $msg);
    }

    function errorAction() {

        $this->setView('base.passwordResetForm');
        $email_address = trim((string) $this->getParam('email_address'));
        $this->set('error_msg', $this->getMsg(2001, ['message' => [$email_address]]));
    }
}

?>