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

require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Add User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersAddController extends owa_adminController {
		
	function __construct($params) {
	
		parent::__construct($params);
		
		$this->setRequiredCapability('edit_users');
		$this->setNonceRequired();
		
		// Check for user with the same email address
		// this is needed or else the change password feature will not know which account
		// to chane the password for.
		$v1 = owa_coreAPI::validationFactory('entityDoesNotExist');
		$v1->setConfig('entity', 'base.user');
		$v1->setConfig('column', 'email_address');
		$v1->setValues(trim($this->getParam('email_address')));
		$v1->setErrorMessage($this->getMsg(3009));
		$this->setValidation('email_address', $v1);
		
		// Check user name.
		$v2 = owa_coreAPI::validationFactory('entityDoesNotExist');
		$v2->setConfig('entity', 'base.user');
		$v2->setConfig('column', 'user_id');
		$v2->setValues(trim($this->getParam('user_id')));
		$v2->setErrorMessage($this->getMsg(3001));
		$this->setValidation('user_id', $v2);
	}
	
	function action() {
				
		$userManager = owa_coreApi::supportClassFactory('base', 'userManager');				
				
				
		$user_params = array( 'user_id' 		=> trim($this->params['user_id']),
							  'real_name' 		=> $this->params['real_name'],
						      'role'			=> $this->params['role'],
							  'email_address' 	=> trim($this->params['email_address'])); 
							          
		$temp_passkey = $userManager->createNewUser($user_params);
		
		// log account creation event to event queue
		$ed = owa_coreAPI::getEventDispatch();
		$ed->log(array( 'user_id' 	=> $this->params['user_id'],
						'real_name' => $this->params['real_name'],
						'role' 		=> $this->params['role'],
						'email_address' => $this->params['email_address'],
						'temp_passkey' => $temp_passkey), 
						'base.new_user_account');
		
		$this->setRedirectAction('base.users');
		$this->set('status_code', 3000);
	}
	
	function errorAction() {
		$this->setView('base.options');
		$this->setSubview('base.usersProfile');
		$this->set('error_code', 3009);
		//assign original form data so the user does not have to re-enter the data
		$this->set('profile', $this->params);	
	}
}

?>