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

/**
 * User Manager Class
 * 
 * handels the common tasks associated with creating and manipulating user accounts
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */




class owa_userManager extends owa_base {
	
	function __construct() {
		
		$this->owa_base();
				
		return;
	}
	
	function owa_userManager() {
	
		return $this->__construct();
	}
	
	function createNewUser($user_params) {
	
		// save new user to db
		$auth = &owa_auth::get_instance();
		$temp_passkey = $auth->generateTempPasskey($this->params['user_id']);
		$u = owa_coreAPI::entityFactory('base.user');
		$u->set('user_id', $user_params['user_id']);
		$u->set('role', $user_params['role']);
		$u->set('real_name', $user_params['real_name']);
		$u->set('email_address', $user_params['email_address']);
		$u->set('temp_passkey', $temp_passkey);
		$u->set('creation_date', time());
		$u->set('last_update_date', time());
		$ret = $u->create();
		
		if ($ret == true):
			return $temp_passkey;
		else:
			return false;
		endif;
	
	}
	
	function deleteUser($user_id) {
	
		$u = owa_coreAPI::entityFactory('base.user');

		$ret = $u->delete($user_id, 'user_id');
		
		if ($ret == true):
			return true;
		else:
			return false;
		endif;
	
	}
	
}

?>