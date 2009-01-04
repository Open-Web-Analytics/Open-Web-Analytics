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
require_once(OWA_BASE_DIR.'/eventQueue.php');

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
	
	function owa_usersAddController($params) {
		
		return owa_usersAddController::__construct($params);
	}
	
	function __construct($params) {
	
		$this->setRequiredCapability('edit_users');
		return parent::__construct($params);
	}
	
	function action() {
		
		$u = owa_coreApi::entityFactory('base.user');
		
		//Check to see if user name already exists
		$u->getByColumn('user_id', $this->params['user_id']);
			
		$id = $u->get('id');
		
		// Set user object Params
		if (empty($id)):
		
			$userManager = owa_coreApi::supportClassFactory('base', 'userManager');				
					
					
			$user_params = array( 'user_id' 		=> $this->params['user_id'],
								  'real_name' 		=> $this->params['real_name'],
							      'role'			=> $this->params['role'],
								  'email_address' 	=> $this->params['email_address']); 
								          
			$temp_passkey = $userManager->createNewUser($user_params);
			
			// log account creation event to event queue
			$eq = &eventQueue::get_instance();
			$eq->log(array( 'user_id' 	=> $this->params['user_id'],
							'real_name' => $this->params['real_name'],
							'role' 		=> $this->params['role'],
							'email_address' => $this->params['email_address'],
							'temp_passkey' => $temp_passkey), 
							'base.new_user_account');
			
			
			$this->setRedirectAction('base.users');
			$this->set('status_code', 3000);
			
		//Send user and back to form to pick a new user name.
		else:
			
			$this->setView('base.options');
			$this->setSubview('base.usersProfile');
			$this->set('error_code', 3001);
			//assign original form data so the user does not have to re-enter the data
			$this->set('user', $this->params);
		endif;
		
		return;
	}
	
}


?>