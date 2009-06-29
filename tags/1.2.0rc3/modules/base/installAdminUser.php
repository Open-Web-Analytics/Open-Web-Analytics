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

require_once(OWA_BASE_CLASS_DIR.'installController.php');

/**
 * Install Default Admin User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installAdminUserController extends owa_installController {
	
	function owa_installAdminUserController($params) {
				
		return owa_installAdminUserController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// Control logic
		
		$u = owa_coreAPI::entityFactory('base.user');
		$auth = &owa_auth::get_instance();
		
		// check to see if an admin user already exists
		$u->getByColumn('role', 'admin');
		$id_check = $u->get('id');
				
		// if not then proceed
		if (empty($id_check)):
	
			//Check to see if user name already exists
			$u->getByColumn('user_id', 'admin');
	
			$id = $u->get('id');
	
			// Set user object Params
			if (empty($id)):
			
				$userManager = owa_coreApi::supportClassFactory('base', 'userManager');				
				
				
				$user_params = array( 'user_id' 		=> 'admin',
									  'real_name' 		=> $this->params['real_name'],
								      'role'			=> 'admin',
							    	  'email_address' 	=> $this->params['email_address']); 
							          
				$temp_passkey = $userManager->createNewUser($user_params);
				
				$this->set('u', 'admin');
				$this->set('k', $temp_passkey);
				$this->setRedirectAction('base.installFinish');
				$this->set('status_code', 3304);
			
			else:					
				$this->setView('base.install');
				$this->setSubview('base.installAdminUserEntry');
				$this->set('status_msg', $this->getMsg(3306));
			endif;
		
		// otherwise return the already installed view
		else:
			$this->setView('base.install');
			$this->setSubview('base.installStart');
		endif;
		
		return;
	}
	
}


?>
