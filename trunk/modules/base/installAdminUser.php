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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installAdminUserView extends owa_view {
	
	function owa_installAdminUserView() {
		
		$this->owa_view();
		
		//Load config from db
		$this->c->load();
		// Secure access to this controller if the installer has already been run
		if ($this->c->get('base', 'install_complete') != true):	
			$this->priviledge_level = 'guest';
		else:
			$this->priviledge_level = 'admin';
		endif;

				
		return;
	}
	
	function construct($data) {
		
		
		// Set Page title
		$this->t->set('page_title', 'Setup Default Admin User');
		
		// Set Page headline
		$this->body->set('headline', 'Setup Default Admin user');
		
		$this->body->set('action', 'base.installAdminUser');
		
		// load body template
		$this->body->set_template('install_default_user.tpl');
		
		return;
	}
	
	
}

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

class owa_installAdminUserController extends owa_controller {
	
	function owa_installAdminUserController($params) {
		$this->owa_controller($params);
		
		//Load config from db
		$this->c->load();
		// Secure access to this controller if the installer has already been run
		if ($this->c->get('base', 'install_complete') != true):	
			$this->priviledge_level = 'guest';
		else:
			$this->priviledge_level = 'admin';
		endif;
		
		return;
	}
	
	function action() {
		
		// Control logic
		
		$u = owa_coreAPI::entityFactory('base.user');
		$auth = &owa_auth::get_instance();
		
		// check to see if an admin user already exists without relying on loading config from DB
		$u->getByColumn('role', 'admin');
		$id_check = $u->get('id');
		
		
		$config = owa_coreAPI::entityFactory('base.configuration');
		$config->getByPk('id', $this->config['configuration_id']);
		$settings = unserialize($config->get('settings'));
		//print_r($settings);
		
		if ($settings['base']['install_complete'] != true):
		// if not then proceed
			if (empty($id_check)):
		
				//Check to see if user name already exists
				$u->getByColumn('user_id', $this->params['user_id']);
		
				// data
				$data = array();
		
				$id = $u->get('id');
		
				// Set user object Params
				if (empty($id)):
				
					$userManager = owa_coreApi::supportClassFactory('base', 'userManager');				
					
					
					$user_params = array( 'user_id' 		=> $this->params['user_id'],
										  'real_name' 		=> $this->params['real_name'],
									      'role'			=> $this->params['role'],
								    	  'email_address' 	=> $this->params['email_address']); 
								          
					$temp_passkey = $userManager->createNewUser($user_params);
					
					// return view
					$data['view_method'] = 'redirect';
					
					$data['u'] = $this->params['user_id'];
					$data['k'] = $temp_passkey;
					$data['action'] = 'base.installFinish';
					$data['status_code'] = 3304;
				
				else:
				$data = $this->params;
				$data['view_method'] = 'delegate';
				$data['view'] = 'base.install';
				$data['subview'] = 'base.installAdminUser';
				$data['status_msg'] = $this->getMsg(3306);
				endif;
			
			// otherwise return the already installed view
			else:
				$data['view_method'] = 'delegate';
				$data['view'] = 'base.install';
				$data['subview'] = 'base.installStart';
			endif;
		
		else:
			$data['view_method'] = 'delegate';
			$data['view'] = 'base.install';
			$data['subview'] = 'base.installStart';
		endif;
		
		return $data;
	}
	
}


?>
