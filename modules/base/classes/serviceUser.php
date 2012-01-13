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
	/**
	 * @var owa_user
	 */
	public $user;
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
	/**
	 * gets allowed capabilities for the user role
	 * @param unknown_type $role
	 */
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
	
	/**
	 * Checks if user is capable to do something
	 * @param string $cap
	 * @param integer $currentSiteId optionel - only needed if cap is a  capabilities That Require SiteAccess. You need to pass site_id (not id) field
	 */
	function isCapable($cap, $siteId = null) {
		owa_coreAPI::debug("check cap ".$cap);
		//global admin can always everything:
		if ($this->user->isOWAAdmin() || empty($cap)) {
			return true;
		}
		if (!in_array($cap, $this->capabilities)) {
			return false;	
		}
		
		$capabilitiesThatRequireSiteAccess = owa_coreAPI::getSetting('base', 'capabilitiesThatRequireSiteAccess');
		if (is_array($capabilitiesThatRequireSiteAccess) && in_array($cap, $capabilitiesThatRequireSiteAccess)) {
			if (is_null($siteId)) {
				throw new InvalidArgumentException('Capability "'.$cap.'" that should be checked requires a sited - but nothing given');
			}
			$site = owa_coreAPI::entityFactory('base.site');			
			$site->load($siteId,'site_id');
			if (!$site->isUserAssigned($this->user->get('id'))) {
				return false;
			}
		}
		return true;
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