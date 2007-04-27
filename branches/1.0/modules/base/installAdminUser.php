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
		$this->priviledge_level = 'guest';
		
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
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function action() {
		
		// Control logic
		
		$u = owa_coreAPI::entityFactory('base.user');
		$auth = &owa_auth::get_instance();
		
		//Check to see if user name already exists
		$u->getByColumn('user_id', $this->params['user_id']);
		
		// data
		$data = array();
		
		$id = $u->get('id');
		
		// Set user object Params
		if (empty($id)):
		
			//Generate Initial Passkey and new account email
			$auth->setInitialPasskey($this->params['user_id']);
			
			// log account creation event to event queue
			$eq = &eventQueue::get_instance();
			$eq->log(array( 'user_id' 		=> $this->params['user_id'],
							'real_name' 	=> $this->params['real_name'],
							'role' 			=> $this->params['role'],
							'email_address' => $this->params['email_address']), 
							'base.new_user_account');
			
			// return view
			$data['view_method'] = 'redirect';
			$data['view'] = 'base.install';
			$data['subview'] = 'base.installDefaultSiteProfile';
			$data['status_code'] = 3304;
			
		else:
			$data = $this->params;
			$data['view_method'] = 'delegate';
			$data['view'] = 'base.install';
			$data['subview'] = 'base.installAdminUser';
			$data['status_msg'] = $this->getMsg(3306);
		endif;
		
		return $data;
	}
	
}


?>
