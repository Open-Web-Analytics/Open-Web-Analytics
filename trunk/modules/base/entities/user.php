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
	/*

	var $id = array('data_type' => OWA_DTD_SERIAL, 'auto_increment' => true); // SERIAL,
	var $user_id = array('data_type' => OWA_DTD_VARCHAR255, 'is_primary_key' => true); // varchar(255),
	var $password = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $role = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $real_name = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $email_address = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $temp_passkey = array('data_type' => OWA_DTD_VARCHAR255); // VARCHAR(255),
	var $creation_date = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	var $last_update_date = array('data_type' => OWA_DTD_BIGINT); // BIGINT,
	
	*/
	function owa_user() {
		
		return owa_user::__construct();		
	}
	
	function __consruct() {
	
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
	}
	
	
	
	
	
}



?>