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
	
	var $id; // SERIAL,
	var $user_id; // varchar(255),
	var $password; // VARCHAR(255),
	var $role; // VARCHAR(255),
	var $real_name; // VARCHAR(255),
	var $email_address; // VARCHAR(255),
	var $temp_passkey; // VARCHAR(255),
	var $creation_date; // BIGINT,
	var $last_update_date; // BIGINT,
	
	
	function owa_user() {
		
		$this->owa_entity();
		
		$this->id->auto_incement = true;
		
		return;
			
	}
	
	
	
}



?>