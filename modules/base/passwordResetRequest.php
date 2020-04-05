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

require_once(OWA_BASE_DIR.'/owa_controller.php');

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

class owa_passwordResetRequestController extends owa_controller {

    public function validate()
    {
        $this->addValidation('email_address', $this->getParam('email_address'), 'emailAddress', ['stopOnError' => true]);

        $useEmailAddressEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'email_address',
            'errorMsg'  => $this->getMsg(3010)
        ];

        $this->addValidation('email_address', trim($this->getParam('email_address')), 'entityExists', $useEmailAddressEntityConf);
    }

    function action() {

        // Log password reset request to event queue
        $ed = owa_coreAPI::getEventDispatch();

        $event = $ed->makeEvent( 'base.reset_password' );
        $event->set('email_address', $this->getParam( 'email_address' ) );
        $ed->notify( $event );

        // return view
        $this->setView('base.passwordResetForm');
        $email_address = trim($this->getParam('email_address'));
        $msg = $this->getMsg(2000, ['message' => $email_address]);
        $this->set('status_msg', $msg);
    }

    function errorAction() {

        $this->setView('base.passwordResetForm');
        $email_address = trim($this->getParam('email_address'));
        $this->set('error_msg', $this->getMsg(2001, ['message' => $email_address]));
    }
}

?>