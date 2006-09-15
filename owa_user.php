<?

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

require_once('owa_base.php');

/**
 * OWA management user object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_user extends owa_base {
	
	/**
	 * GUID for each user object
	 *
	 * @var int
	 */
	var $user_id;
	
	/**
	 * encrypted password
	 *
	 * @var string
	 */
	var $password;
	
	/**
	 * Priviledge Role
	 *
	 * @var string
	 */
	var $role;
	
	/**
	 * Display name
	 *
	 * @var string
	 */
	var $real_name;
	
	/**
	 * Email address
	 *
	 * @var string
	 */
	var $email_address;
	
	/**
	 * authentication key generated when user forgets their password. 
	 * Used in forgot password email.
	 *
	 * @var string
	 */
	var $temp_passkey;
	
	/**
	 * Date the user was created
	 *
	 * @var int
	 */
	var $creation_date;
	
	/**
	 * Date the user object was last updated
	 *
	 * @var int
	 */
	var $last_update_date;
	
	/**
	 * Database access object
	 *
	 * @var object
	 */
	var $db;
	
	function owa_user() {
		
		$this->owa_base();
		$this->db = &owa_db::get_instance();
		
		return;
	}
	
	/**
	 * Base select sql statement
	 *
	 * @param string $constraint
	 * @return string
	 */
	function selectUser($constraint) {
		
		return sprintf(" SELECT
							user_id,
							password,
							role,
							real_name,
							email_address,
							temp_passkey,
							creation_date,
							last_update_date
						FROM
							%s 
						 %s",
							$this->config['ns'].$this->config['users_table'],
							$constraint);
		
	}
	
	/**
	 * DOA method for looking up a user by their user_id
	 *
	 * @param int $user_id
	 * @return object
	 */
	function getUserByPK($user_id) {
		
		$constraint = sprintf("WHERE user_id = '%s'", $user_id);
		return $this->getUser($constraint);
	}
	
	/**
	 * DOA method for looking up user by temp passkey
	 *
	 * @param string $key
	 * @return object
	 */
	function getUserByTempPasskey($key) {
		
		$constraint = sprintf("WHERE temp_passkey = '%s'", $key);
		return $this->getUser($constraint);
		
	}
	
	/**
	 * DOA method for looking up user by email address
	 *
	 * @param string $email_address
	 * @return object
	 */
	function getUserByEmail($email_address) {
		
		$constraint = sprintf("WHERE email_address = '%s'", $email_address);
		return $this->getUser($constraint);
	}
	
	/**
	 * Base DOA method for retrieving a single user from the DB.
	 *
	 * @param string $constraint
	 * @return object
	 */
	function getUser($constraint) {
		
		$user = $this->db->get_row($this->selectUser($constraint));
																			
		if ($user):
	
			$this->_setAttributes($user);
			return true;
		else:
			return false;
		endif;
		
	}
	
	/**
	 * DOA Method for returnign an array of all users
	 *
	 * @return unknown
	 */
	function getAllUsers() {
		
		return $user = $this->db->get_results($this->selectUser(''));
		
	}
	
	
	/**
	 * Sets user object attributes
	 *
	 * @param unknown_type $array
	 */
	function _setAttributes($array) {
		
		foreach ($array as $n => $v) {
				
				$this->$n = $v;
		
			}
		
		return;
	}
	
	/**
	 * Saves user object to the DB
	 *
	 * @return boolean
	 */
	function save() {
		
		$check = $this->db->get_row(sprintf("SELECT
										user_id
									FROM
										%s
									WHERE
										user_id = '%s'",
									$this->config['ns'].$this->config['users_table'],
									$this->user_id
									));
		
		if (empty($check)):
		
			return $this->db->query(sprintf("INSERT INTO %s (
										user_id, 
										password, 
										role, 
										real_name, 
										email_address, 
										temp_passkey,
										creation_date, 
										last_update_date)
									  VALUES
									  	('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')",
										$this->config['ns'].$this->config['users_table'],
									  	$this->user_id,
									  	$this->password,
									  	$this->role,
									  	$this->real_name,
									  	$this->email_address,
									  	$this->temp_passkey,
									  	time(),
									  	time()));
		else:
			return "primary_key_exists";
		endif;
	
	}
	
	/**
	 * Updates already existing user object
	 *
	 * @return boolean
	 */
	function update() {
		
		return $this->db->query(sprintf("UPDATE 
									%s 
								SET
									user_id = '%s', 
									password = '%s', 
									role = '%s', 
									real_name = '%s', 
									email_address = '%s', 
									temp_passkey = '%s',
									creation_date = '%s', 
									last_update_date = '%s'
								WHERE
									user_id = '%s'",
								$this->config['ns'].$this->config['users_table'],
							  	$this->user_id,
							  	$this->password,
							  	$this->role,
							  	$this->real_name,
							  	$this->email_address,
							  	$this->temp_passkey,
							  	$this->creation_date,
							  	time(),
							 	$this->user_id));
		
	
	}
	
	function delete() {
		
		return $this->db->query(sprintf("DELETE FROM 
											%s
										WHERE
											user_id = '%s'",
								$this->config['ns'].$this->config['users_table'],
							  	$this->user_id));
		
		
		
	}
	
	
}

?>