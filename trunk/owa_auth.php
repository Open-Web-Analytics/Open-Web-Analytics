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
require_once(OWA_BASE_DIR.'/owa_base.php');
/**
 * User Authentication Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_auth extends owa_base {
	
	/**
	 * User object
	 *
	 * @var unknown_type
	 */
	var $u;
	
	/**
	 * Array of permission roles that users can have
	 *
	 * @var array
	 */
	var $roles;
	
	/**
	 * Database Access Object
	 *
	 * @var unknown_type
	 */
	var $db;
	
	var $status_msg;
	
	/**
	 * Login credentials
	 *
	 * @var array
	 */
	var $credentials;
	
	/**
	 * Abstract class Constructor
	 *
	 * @return owa_auth
	 */
	function owa_auth() {
		
		$this->owa_base();
		$this->setRoles();
		
		return;
		
	}
	
	/**
	 * Sets the permission levels of each role.
	 *
	 */
	function setRoles() {
		
		$this->roles = array('admin' 	=> array('level' => 10, 'label' => 'Administrator'),
							 'viewer' 	=> array('level' => 2, 'label' => 'Report Viewer'),
							 'guest' 	=> array('level' => 1, 'label' => 'Guest')
		
						);
						
		return;
		
	}
	
	/**
	 * Looks up the priviledge level for a particular role
	 *
	 * @param unknown_type $role
	 * @return unknown
	 */
	function getLevel($role) {
		
		return $this->roles[$role]['level'];
	}
	
	function authenticateUser() {

		return;
	}
	
	/**
	 * Creates the concrete auth class
	 *
	 * @return object
	 */
	function &get_instance() {
		
		$config = &owa_settings::get_settings();
		return owa_lib::singleton($config['plugin_dir'].'/auth/', 
									'owa_auth_',
									$config['authentication']);
	}
	
	/**
	 * Looks up user by temporary Passkey Column in db
	 *
	 * @param unknown_type $key
	 * @return unknown
	 */
	function authenticateUserTempPasskey($key) {
		
		$this->u = new owa_user;
		$this->u->getUserByTempPasskey($key);
		
		if (!empty($this->u->user_id)):
			return true;
		else:
			$this->showResetPasswordErrorPage;
		endif;
		
	}
	
	/**
	 * Checks to see if the user credentials match a real user object in the DB
	 *
	 * @param string $user_id
	 * @param string $password
	 * @return boolean
	 */
	function isUser($user_id, $password) {
		
		// fetch user credenticals from the db
		$this->u = new owa_user;
		$this->u->getUserByPK($user_id);
		
		if (($user_id == $this->u->user_id)):
			if ($password === $this->u->password):
				return true;
			else:
				return false;
			endif;
		else:
			return false;
		endif;
	}
	
	/**
	 * Checks to see if the user has appropriate priviledges
	 *
	 * @param string $necessary_role
	 * @return boolean
	 */
	function isPriviledged($necessary_role) {
		
		// compare priviledge levels
		if ($this->getLevel($this->u->role) >= $this->getLevel($necessary_role)):
			// authenticated
			return true;;
		else:
			// not high enough priviledge level
			return false;	
		endif;
		
	}
	
	/**
	 * Sets a temporary Passkey for a user
	 *
	 * @param string $email_address
	 * @return boolean
	 */
	function setTempPasskey($email_address) {
		
		$this->u = new owa_user;
		$this->u->getUserByEmail($email_address);

		if (!empty($this->u->user_id)):
		
			$this->eq->log(array('email_address' => $this->u->email_address), 'user.set_temp_passkey');
			return true;
			//$this->showRequestNewPasswordSuccessPage();	
		else:
			return false;
			//$this->showResetPasswordErrorPage();
		endif;
		
	}
	
	/**
	 * Sets the initial Passkey for a new user
	 *
	 * @param string $user_id
	 * @return boolean
	 */
	function setInitialPasskey($user_id) {
		
		return $this->eq->log(array('user_id' => $user_id), 'user.set_initial_passkey');
		
	}
	
	/**
	 * Used to auth a new browser that has no credentials set
	 *
	 * @param string $user_id
	 * @param string $password
	 * @return boolean
	 */
	function authenticateNewBrowser($user_id, $password) {
		
		$this->e->debug("Login attempt from ". $user_id);
		
		$is_user = $this->isUser($user_id, $this->encryptPassword($password));
		
		if ($is_user == true):
			$this->saveCredentials();
			return true;
		else:
			return false;
		endif;
		
		return;
	}
	
}


?>