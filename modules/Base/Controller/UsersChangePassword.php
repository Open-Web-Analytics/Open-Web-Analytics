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

class UsersChangePassword extends \OWA\Core\Controller {

    public function validate()
    {
        $this->addValidation('password_match', [$this->getParam('password'), $this->getParam('password2')], 'stringMatch', ['errorMsg' => 'Your passwords must match.']);
        $this->addValidation('password_required', $this->getParam('password'), 'required');

        $passwordLengthConf = [
            'operator'  => '>=',
            'length'    => 6,
            'errorMsg'  => 'Your password must be at least 6 characters in length.',
        ];

        // 'stringLength' (not 'required') is the validator that actually reads
        // the operator/length config above; typed as 'required' the length rule
        // silently degraded to a bare non-empty check and short passwords passed.
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', $passwordLengthConf);
    }

    function action() {
		
		// needed for old style embedded install migration
		if ( $this->getParam('is_embedded') ) {
			
			\OWA\Core\CoreAPI::setSetting('base', 'is_embedded', true);
		}
		
		
        $auth = \OWA\Core\Auth::get_instance();
        $status = $auth->authenticateUserTempPasskey($this->getParam('k'));

        // log to event queue
        if ($status === true) {
            $ed = \OWA\Core\CoreAPI::getEventDispatch();
            $new_password = array('key' => $this->getParam('k'), 'password' => $this->getParam('password'), 'ip' => $_SERVER['REMOTE_ADDR'], 'user_id' => $auth->u->get('user_id'));
            $ed->log($new_password, 'base.set_password');
            $auth->deleteCredentials();
            $this->setRedirectAction('base.loginForm');
            $this->set('status_code', 3006);
        } else {
            $this->setRedirectAction('base.loginForm');
            $this->set('error_code', 2011); // can't find key in the db
        }
    }

    function errorAction() {

        $this->setView('base.usersPasswordEntry');

        /*
         * 'key', not 'k'.
         *
         * The view reads 'key' -- UsersPasswordEntry::action() sets it under
         * that name, and View::get() answers FALSE for a name nobody set. So
         * setting 'k' here rendered the hidden field as value="", and the
         * passkey was gone from the form the moment any validation failed.
         *
         * The effect was a reset that could not be completed: mistype the
         * confirmation once, get "Your passwords must match", correct it, and
         * the next submit carried no key at all -- authenticateUserTempPasskey
         * refused it and the user was redirected to the login form with "can't
         * find key in the db". Only a brand new reset email, typed correctly
         * first time, could get through.
         */
        $this->set('key', $this->getParam('k'));
    }
}

?>