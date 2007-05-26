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
require_once(OWA_BASE_DIR.'/owa_auth.php');

/**
 * Edit User View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersEditView extends owa_view {
	
	function owa_usersEditView($params) {
		
		$this->owa_view($params);
		$this->priviledge_level = 'admin';
		
		return;
	}
	
	function construct($data) {
		
		//page title
		$this->t->set('page_title', 'Edit A User');
		$this->body->set('headline', 'Edit A User');
		// load body template
		$this->body->set_template('users_addoredit.tpl');
		$auth = &owa_auth::get_instance();
		$this->body->set('roles', $auth->roles);	
		$this->body->set('action', 'base.usersEdit');
		
		//Check to see if user is passed by constructor or else fetch the object.
		if ($data['user']):
			$this->body->set('user', $data['user']);
			$this->body->set('error_msg', $this->getMsg(3002));
		else:
			$u = owa_coreAPI::entityFactory('base.user');
			$u->getByColumn('user_id', $data['user_id']);
			$this->body->set('user', $u->_getProperties());
		endif;
		
		return;
	}
	
	
}

/**
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersEditController extends owa_controller {
	
	function owa_usersEditController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'admin';
	}
	
	function action() {
		
		// This needs form validation in a bad way.
		
		$u = owa_coreAPI::entityFactory('base.user');
		$u->getByColumn('user_id', $this->params['user_id']);
		$u->set('email_address', $this->params['email_address']);
		$u->set('real_name', $this->params['real_name']);
		$u->set('role', $this->params['role']);
		$u->update();
		
		$data['view_method'] = 'redirect';
		$data['view'] = 'base.options';
		$data['subview'] = 'base.users';
		$data['status_code'] = 3003;
		//assign original form data so the user does not have to re-enter the data
		
		
		//$data['user'] = $this->params;
		
		
		return $data;
	}
	
}


?>