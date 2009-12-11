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
 * 004 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.1
 */


class owa_base_004_update extends owa_update {

	function up() {
		
		// create admin user for embedded installs.
		// embedded installs did not create admin users until this release (v1.2.1) 
		$cu = owa_coreAPI::getCurrentUser();
		$this->createAdminUser($cu->getUserData('email_address'));
				
		$ds = owa_coreAPI::entityFactory('base.domstream');
		$ret = $ds->createTable();
		
		if ($ret == true) {
			$this->e->notice('Domstream entity table created');
			return true;
		} else {
			$this->e->notice('Domstream entity table creation failed');
			return false;
		}		
	}
	
	function down() {
	
		return false;
	}
	
	function createAdminUser($email_address) {
		
		//create user entity
		$u = owa_coreAPI::entityFactory('base.user');
		// check to see if an admin user already exists
		$u->getByColumn('role', 'admin');
		$id_check = $u->get('id');		
		// if not then proceed
		if (empty($id_check)) {
	
			//Check to see if user name already exists
			$u->getByColumn('user_id', 'admin');
	
			$id = $u->get('id');
	
			// Set user object Params
			if (empty($id)) {
				
				$password = $u->generateRandomPassword();
				$u->set('user_id', 'admin');
				$u->set('role', 'admin');
				$u->set('real_name', '');
				$u->set('email_address', $email_address);
				$u->set('password', owa_lib::encryptPassword($password));
				$u->set('creation_date', time());
				$u->set('last_update_date', time());
				$ret = $u->create();

				owa_coreAPI::debug("Admin user created successfully.");
				
				return $password;
				
			} else {				
				owa_coreAPI::debug($this->getMsg(3306));
			}
		} else {
			owa_coreAPI::debug("Admin user already exists.");
		}

	}

}

?>