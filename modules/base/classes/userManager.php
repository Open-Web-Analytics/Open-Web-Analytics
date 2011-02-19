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
						
		return parent::__construct();
	}
	
	function createNewUser($user_params) {

		if ( isset( $user_params['password'] ) ) {
			$password = $user_params['password'];
		} else {
			$password = '';
		}
		
		// save new user to db		
		$u = owa_coreAPI::entityFactory('base.user');
		$ret = $u->createNewUser( 
				$user_params['user_id'], 
				$user_params['role'], 
				$password, 
				$user_params['email_address'], 
				$user_params['real_name']
		);
		
		if ( $ret ) {
			return $u->get('temp_passkey');
		} else {
			return false;
		}
	
	}
	
	function deleteUser($user_id) {
	
		$u = owa_coreAPI::entityFactory('base.user');

		$ret = $u->delete($user_id, 'user_id');
		
		if ( $ret ) {
			return true;
		} else {
			return false;
		}
	}
}

?>