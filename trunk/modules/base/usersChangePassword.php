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
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');

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
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersChangePasswordController extends owa_controller {
	
	function __construct($params) {
		
		parent::__construct($params);
		
		// Add validations to the run
		$v1 = owa_coreAPI::validationFactory('stringMatch');
		$v1->setValues(array($this->getParam('password'), $this->getParam('password2')));
		$v1->setErrorMessage("Your passwords must match.");
		$this->setValidation('password_match', $v1);
		
		$v2 = owa_coreAPI::validationFactory('stringLength');
		$v2->setValues($this->getParam('password'));
		$v2->setConfig('operator', '>=');
		$v2->setConfig('length', 6);
		$v2->setErrorMessage("Your password must be at least 6 characters in length.");
		$this->setValidation('password_length', $v2);

		return;
	}
	
	function action() {
		
		$auth = &owa_auth::get_instance();
		$status = $auth->authenticateUserTempPasskey($this->params['k']);
			
		// log to event queue
		if ($status === true) {
			$ed = owa_coreAPI::getEventDispatch();
			$new_password = array('key' => $this->params['k'], 'password' => $auth->encryptPassword($this->params['password']), 'ip' => $_SERVER['REMOTE_ADDR']);
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
		$this->set('k', $this->getParam('k'));
	}
}

?>