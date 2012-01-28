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
 * User Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_user extends owa_entity {
	
	const ADMIN_USER_ID = 'admin';
	const ADMIN_USER_ROLE = 'admin';
	
	function __construct() {
	
		$this->setTableName('user');
		$this->setCachable();
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_SERIAL);
		$this->properties['id']->setAutoIncrement();
		$this->properties['user_id'] = new owa_dbColumn;
		$this->properties['user_id']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['user_id']->setPrimaryKey();
		$this->properties['password'] = new owa_dbColumn;
		$this->properties['password']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['role'] = new owa_dbColumn;
		$this->properties['role']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['real_name'] = new owa_dbColumn;
		$this->properties['real_name']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['email_address'] = new owa_dbColumn;
		$this->properties['email_address']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['temp_passkey'] = new owa_dbColumn;
		$this->properties['temp_passkey']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['creation_date'] = new owa_dbColumn;
		$this->properties['creation_date']->setDataType(OWA_DTD_BIGINT);
		$this->properties['last_update_date'] = new owa_dbColumn;
		$this->properties['last_update_date']->setDataType(OWA_DTD_BIGINT);
		
		$apiKey = new owa_dbColumn;
		$apiKey->setName('api_key');
		$apiKey->setDataType(OWA_DTD_VARCHAR255);
		$this->setProperty($apiKey);

	}
	
	function createNewUser($user_id, $role, $password = '', $email_address = '', $real_name = '') {
	
		if (!$password) {
			$password = $this->generateRandomPassword();
		}
		
		$this->set('user_id', $user_id);
		$this->set('role', $role);
		$this->set('real_name', $real_name);
		$this->set('email_address', $email_address);
		$this->set('temp_passkey', $this->generateTempPasskey($user_id));
		$this->set('password', owa_lib::encryptPassword($password));
		$this->set('creation_date', time());
		$this->set('last_update_date', time());
		$this->set('api_key', $this->generateTempPasskey($user_id));
		$ret = $this->create();
		
		return $ret;
	}
	
	function generateTempPasskey($seed) {
		
		return md5($seed.time().rand());
	}
	
	function generateRandomPassword() {	
		return substr(owa_lib::encryptPassword(microtime()),0,6);
	}
	
	/**
	 * @return boolean
	 */
	public function isOWAAdmin() {
		if ( $this->get('user_id') == self::ADMIN_USER_ID ) {
			return true; 
		} else {
			return false;
		}
	}
	
	/**
	 * @return boolean
	 */
	public function isAdmin() {
		if ( $this->get('role') == self::ADMIN_USER_ROLE ) {
			return true; 
		} else {
			return false;
		}
	}
}

?>