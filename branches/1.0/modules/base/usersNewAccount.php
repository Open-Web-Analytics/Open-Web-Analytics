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
 * New user Account Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersNewAccountController extends owa_controller {
	
	function owa_usersNewAccountController($params) {
		$this->owa_controller($params);
		
	}
	
	function action() {
		
		// save new user to db
		$auth = &owa_auth::get_instance();
		$u = owa_coreAPI::entityFactory('base.user');
		$u->set('user_id', $this->params['user_id']);
		$u->set('role', $this->params['role']);
		$u->set('real_name', $this->params['real_name']);
		$u->set('email_address', $this->params['email_address']);
		$u->set('temp_passkey', $auth->generateTempPasskey($this->params['user_id']));
		$u->set('creation_date', time());
		$u->set('last_update_date', time());
		$u->create();
		
   		// return email view
		$data['user_id']= $this->params['user_id'];
		$data['email_address']= $this->params['email_address'];
		$data['temp_passkey'] = $u->get('temp_passkey');
		$data['subject'] = 'OWA User Account Setup';
		$data['view'] = 'base.usersNewAccount';
		$data['view_method'] = 'email';
		
		return $data;
	}
	
}

/**
 * New Account Notification View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_usersNewAccountView extends owa_view {
	
	function owa_usersNewAccountView() {
		
		$this->owa_view();
		return;
	}
	
	function construct($data) {
		
		$this->t->set_template('wrapper_email.tpl');
		$this->body->set_template('users_new_account_email.tpl');
		$this->body->set('user_id', $data['user_id']);
		$this->body->set('key', $data['temp_passkey']);
			
		return;
		
	}
	
	
}


?>