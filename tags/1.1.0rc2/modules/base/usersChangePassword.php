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
require_once(OWA_BASE_DIR.'/eventQueue.php');

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
	
	function owa_usersChangePasswordController($params) {
		$this->owa_controller($params);
		return;
		
	}
	
	function action() {
		
	
		//check that password and password 2 match
		$validation_error = $this->validatePassword();
		
		if (empty($validation_error)):
				$auth = &owa_auth::get_instance();
				$status = $auth->authenticateUserTempPasskey($this->params['k']);
					
				// log to event queue
				if ($status == true):
					$eq = & eventQueue::get_instance();
					$new_password = array('key' => $this->params['k'], 'password' => $auth->encryptPassword($this->params['password']), 'ip' => $_SERVER['REMOTE_ADDR']);
					$eq->log($new_password, 'base.set_password');
					$auth->deleteCredentials();	
					$data['view'] = 'base.login';
					$data['view_method'] = 'delegate';
					$data['status_msg'] = $this->getMsg(3006);
					
				else:
					print 'could not find this users temp passkey in the db';
				endif;
		else:
			$data['error_msg'] = $validation_error;
			$data['view'] = 'base.usersChangePassword';
			$data['view_method'] = 'delegate';

		endif;	
				
		return $data;
	}
	
	function validatePassword() {
	
		//check that passwords match
		if ($this->params['password'] != $this->params['password2']):
			$error_msg = $this->getMsg(3007);
			
		// check that passwords are a min length
		elseif (strlen($this->params['password']) < $this->config['password_length']):
			$error_msg = $this->getMsg(3008, $this->config['password_length']);
			
		endif;
		
		if (!empty($error_msg)):
			return $error_msg;
		else:
			return;
		endif;
	}
	
	
}

/**
 * Change Password View
 * 
 * Presents a simple form to the user asking them to enter a new password.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersChangePasswordView extends owa_view {
	
	function owa_usersChangePasswordView() {
		
		$this->owa_view();
		return;
	}
	
	function construct($data) {
		
		$this->body->set_template('users_change_password.tpl');
		$this->body->set('headline', $this->getMsg(3005));
		$this->body->set('key', $data['k']);
		
		return;
		
	}
	
	
}


?>