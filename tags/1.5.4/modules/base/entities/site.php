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
 * Site Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_site extends owa_entity {
	
	private static $cachedAssignedUsers = array();
	
	function __construct() {
		
		$this->setTableName('site');
		$this->setCachable();
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		$this->properties['site_id'] = new owa_dbColumn;
		$this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['domain'] = new owa_dbColumn;
		$this->properties['domain']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['name'] = new owa_dbColumn;
		$this->properties['name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['description'] = new owa_dbColumn;
		$this->properties['description']->setDataType(OWA_DTD_TEXT);
		$this->properties['site_family'] = new owa_dbColumn;
		$this->properties['site_family']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['settings'] = new owa_dbColumn;
		$this->properties['settings']->setDataType(OWA_DTD_BLOB);
	}
	
	function generateSiteId($domain) {
		
		return md5($domain);
	}
	
	function settingsGetFilter($value) {
		if ($value) {
			return unserialize($value);
		}
	}
	
	function settingsSetFilter($value) {
		owa_coreAPI::debug('hello rom setFilter');
		$value = serialize($value);
		owa_coreAPI::debug($value);
		return $value;
	}
	
	/**
	 * Retrieves a specific setting from the settings
	 * property for this site
	 *
	 * @param string $name the name of the setting
	 * @return mixed
	 */
	public function getSiteSetting($name) {
		
		$settings = $this->get('settings');

		if ( ! empty( $settings ) ) {
		
			if ( array_key_exists( $name, $settings ) ) {
			
				return $settings[$name];
			}
		}	
	}
	
	public function getDomainName() {
		
		$domain = $this->get('domain');
		
		if ( $domain && strpos( $domain, '://' ) ) {
			list( $protocol, $domain ) = explode( '://', $domain );
			
			return rtrim( trim( $domain ), '/' );
		}
	}

	/**
	 * Updates the allowed Sites for the current loaded user
	 * @param array $siteIds
	 */
	public function updateAssignedUserIds(array $userIds) {
		 if (!$this->get('id')) {
		 	throw new Exception('no site data loaded!');
		 }
		 $db = owa_coreAPI::dbSingleton();	
		 $db->deleteFrom('owa_site_user');
		 $db->where( 'site_id', $this->get('id') );
		 $ret = $db->executeQuery();
		 
		 foreach ($userIds as $id) {
		 	$relation = owa_coreAPI::entityFactory('base.site_user');
			$relation->set( 'user_id', intval ($id ) );			
			$relation->set( 'site_id', $this->get('id') );
			$relation->save();
		 }
		 
		 unset ( self::$cachedAssignedUsers[$this->get('id')] );

	}
	
	
	/**
	 * Checks if user is allowed to access the site.
	 * @param integer $userId
	 * @return boolean
	 */
	public function isUserAssigned($userId) {		
		$users = $this->getAssignedUsers();	
		foreach ($users as $user) {
			if ($userId == $user->get('id')) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Returns collection of owa_user entities that are allowed for current user
	 * @return owa_user[]
	 */
	public function getAssignedUsers() {		
		if (!$this->get('id')) {
		 	throw new Exception('no site data loaded!');
		}
		if (!isset(self::$cachedAssignedUsers[$this->get('id')])) {
			$db = owa_coreAPI::dbSingleton();		
			$db->selectFrom( 'owa_site_user' );
			$db->selectColumn( '*' );
			$db->where( 'site_id', $this->get('id') );
			$relations = $db->getAllRows();
			$result = array();
			if (is_array($relations)) {		
				foreach ($relations as $row) {
					$userEntity = owa_coreApi::entityFactory('base.user');
					$userEntity->load($row['user_id']);
					$result[] = $userEntity;
				}
			}
			self::$cachedAssignedUsers[$this->get('id')] = $result;
		}
		
		return self::$cachedAssignedUsers[$this->get('id')];		
	}
	
}

?>