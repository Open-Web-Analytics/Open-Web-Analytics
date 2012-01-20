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
	public $assignedSites = array();
	private $isInitialized = false;
	
	function __construct() {
		//parent::__construct();
		$this->user = owa_coreApi::entityFactory('base.user');
	}
	
	function load($user_id) {
		if (empty($user_id)) {
			throw new Exception('No valid userid given!');
		}
		$this->user->load($user_id, 'user_id');			
		$this->isInitialized = false;
		$this->initInternalProperties();
	}
	
	function loadNewUserByObject($obj) {
		$this->user = $obj;
		$this->isInitialized = false;
		$this->initInternalProperties();
		return;
	}
	
	private function initInternalProperties() {
		$this->loadRelatedUserData();
		$this->loadAssignedSites();
		$this->isInitialized = true;
	}
	
	function loadRelatedUserData() {		
		$this->capabilities = $this->getCapabilities($this->user->get('role'));
		$this->preferences = $this->getPreferences($this->user->get('user_id'));
		
	}
	/**
	 * gets allowed capabilities for the user role
	 * @param unknown_type $role
	 */
	function getCapabilities($role) {		
		return owa_coreAPI::getCapabilities( $role );	
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
			owa_coreAPI::debug('no capability passed or user is owaadmin, therefor user is capable.');
			return true;
		}
		if (!in_array($cap, $this->capabilities)) {
			owa_coreAPI::debug('capability passed does not exist. user is not capable');
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
	
	
	/**
	 * Loads internal $this->assignedSites member
	 */
	private function loadAssignedSites() {
		if ( ! $this->user->get( 'id' ) ) {
		 	throw new Exception('no user data loaded!');
		}
				
		$result = array();
		
		if ( $this->isOWAAdmin() ) {
			$relations = owa_coreAPI::getSitesList();
			
			foreach ($relations as $siteRow) {
				$site = owa_coreAPI::entityFactory('base.site');
				$site->load($siteRow['id']);
				$result[$siteRow['site_id']] = $site;
			}
			
		} else {
		
			$db = owa_coreAPI::dbSingleton();		
			$db->selectFrom( 'owa_site_user' );
			$db->selectColumn( '*' );
			$db->where( 'user_id', $this->user->get('id') );
			$relations = $db->getAllRows();
			
			if (is_array($relations)) {		
				foreach ($relations as $row) {
					$siteEntity = owa_coreApi::entityFactory('base.site');
					$siteEntity->load($row['site_id']);
					$result[ $siteEntity->get('site_id') ] = $siteEntity;
				}
			}
		}
		
		$this->assignedSites = $result;
	}
	
	public function getAssignedSites() {				
		if ( !$this->isInitialized) {
			//throw new Exception('serviceUser not loaded and initialized');
			// can always count on user_id being set
			$this->load($this->user->get('user_id') );
		}
		
		return $this->assignedSites;
	}
	

	public function isOWAAdmin() {
		
		return $this->user->isOWAAdmin();
	}
}



?>