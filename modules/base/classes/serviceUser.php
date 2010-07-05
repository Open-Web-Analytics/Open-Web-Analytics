<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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
 * Service User Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_serviceUser extends owa_base {

	var $user;
	var $capabilities = array();
	var $preferences = array();
	var $is_authenticated;
	
	function __construct() {
		
		//parent::__construct();
		$this->user = owa_coreApi::entityFactory('base.user');
	}
	
	function load($user_id) {
		
		$this->user->load($user_id, 'user_id');
		$this->loadRelatedUserData();
		return;
	}
	
	function loadRelatedUserData() {
		
		$this->capabilities = $this->getCapabilities($this->user->get('role'));
		$this->preferences = $this->getPreferences($this->user->get('user_id'));
		return;
	}
		
	function getCapabilities($role) {
		
		$caps = owa_coreAPI::getSetting('base', 'capabilities');
		
		if (array_key_exists($role, $caps)) {
			return $caps[$role];
		} else {
			return array();
		}
		
	}
	
	function getPreferences($user_id) {
		
		return false;
	}
	
	function getRole() {
		
		return $this->user->get('role');
	}
	
	function setRole($value) {
		
		$this->user->set('role', $value);
		$this->capabilities = $this->getCapabilities($value);
		
	}
	
	function setUserData($name, $value) {
		
		$this->user->set($name, $value);
		return;
	}
	
	function getUserData($name) {
		
		return $this->user->get($name);
	}
	
	function isCapable($cap) {
		//owa_coreAPI::debug(print_r($this->user->getProperties(), true));
		owa_coreAPI::debug("cap ".$cap);
		// just in case there is no cap passed
		if (!empty($cap)) {
			//adding @ here as is_array throws warning that an empty array is not the right data type!
			if (in_array($cap, $this->capabilities)) {
				return true;
			} else {
				return false;
			}
				
		} else {
			
			return true;
		}
		
	}
	
	// mark the user as authenticated and populate their capabilities	
	function setAuthStatus($bool) {
		
		$this->is_authenticated = true;
		
		return;
	}	
	
	function isAuthenticated() {
		
		return $this->is_authenticated;
	}
	
	function loadNewUserByObject($obj) {
		$this->user = $obj;
		//$this->current_user->loadNewUserByObject($obj);
		$this->loadRelatedUserData();
		return;
	}
	
	function loadNewUserById($id) {
	
		// get a user object
		// load it
		// $this->loadNewUserByObject($obj);
		return;
		
	}
	
}



?>