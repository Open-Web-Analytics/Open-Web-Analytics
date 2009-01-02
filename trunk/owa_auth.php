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
require_once(OWA_BASE_DIR.'/eventQueue.php');

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
	 * Status of Authentication
	 *
	 * @var boolean
	 */
	var $auth_status = false;
	
	var $_is_user = false;
	
	var $_priviledge_level;
	
	var $_is_priviledged = false;
	
	var $params;
	
	var $check_for_credentials = false;
	
	/**
	 * Abstract class Constructor
	 *
	 * @return owa_auth
	 */
	function owa_auth() {
		
		return owa_auth::__construct();
		
	}
	
	function __construct() {
		
		parent::__construct();
		$this->eq = &eventQueue::get_instance();
		$this->params = &owa_requestContainer::getInstance();
		//sets credentials based on whatever is passed in on params
		$this->_setCredentials($this->params['u'], $this->params['p'], $this->params['pk']);
		
	}
		
	/**
	 * Used by controllers to check if the user exists and if they are priviledged.
	 *
	 * @param string $necessary_role
	 */
	function authenticateUser() {
		
		$data = array();
		
		// carve out for url passkey authentication
		if(!empty($this->credentials['passkey'])):
			$status = $this->authenticateUserByUrlPasskey($this->credentials['user_name'], $this->credentials['passkey']);
			
			if ($status == true):
				$data['auth_status'] = true;
				return $data;
			else:
				$data = $this->_setNotAuthenticatedView();
				$data['auth_status'] = false;
				return $data;	
			endif;
		endif;
			
		// if the user has no credentials then redirect them to the login page.
		//if($this->check_for_credentails == true):
			if ((empty($this->credentials['user_id'])) || (empty($this->credentials['password']))):
				// show login page
				$data = $this->_setNotAuthenticatedView();
				$data['auth_status'] = false;
				return $data;	
			endif;
		//endif;
	
		// lookup user if not already done.	
		if ($this->_is_user == false):
		
			// check to see if the current user has already been authenticated by something upstream
			$cu = owa_coreAPI::getCurrentUser();
			if (!$cu->isAuthenticated()):
				// check to see if they are a user.
				$this->isUser();
			endif;
			
		endif;
				
		if ($this->_is_user == true):
			$data['auth_status'] = true;
					
		else:
			// Show not a user page
			// if they are not a user then redirect to login error page
			$data = $this->_setNotUserView();
			$data['auth_status'] = false;
		endif;
		
		$this->e->debug('Auth Status: '.$data['auth_status']);
		
		return $data;
		
	}
	
	/**
	 * Creates the concrete auth class
	 *
	 * @return object
	 */
	function &get_instance($plugin = '') {
		
		static $auth_modules;
		$auth_mdules = array();
		
		if (empty($auth_modules['plugin'])):
			
			$c = &owa_coreAPI::configSingleton();
			$plugin = $c->get('base', 'authentication');
			
		endif;
		
		// this needs to not be a singleton
		$auth_modules[$plugin] = &owa_lib::singleton(OWA_PLUGIN_DIR.'auth'.DIRECTORY_SEPARATOR, 'owa_auth_', $plugin);
		
		return $auth_modules[$plugin];
	}
	
	/**
	 * Looks up user by temporary Passkey Column in db
	 *
	 * @param unknown_type $key
	 * @return unknown
	 */
	function authenticateUserTempPasskey($key) {
		
		$this->u = owa_coreAPI::entityFactory('base.user');
		$this->u->getByColumn('temp_passkey', $key);
		
		$id = $this->u->get('id');
		if (!empty($id)):
			return true;
		else:
			$this->showResetPasswordErrorPage();
		endif;
		
	}
	
	/**
	 * Authenticates user by a passkey
	 *
	 * @param unknown_type $key
	 * @return unknown
	 */
	function authenticateUserByUrlPasskey($user_name, $passkey) {
		
		$this->getUser();
		
		$key =$this->generateUrlPasskey($this->u->get('user_id'), $this->u->get('password'));
		
		if ($key == $passkey):
			return true;
		else:
			return false;
		endif;
		
	}
	
	/**
	 * abstract method for Checking to see if the user credentials match a real user object in the DB
	 *
	 * @return boolean
	 */
	function isUser() {
		
		return false;
	}
	
	/**
	 * Sets a temporary Passkey for a user
	 *
	 * @param string $email_address
	 * @return boolean
	 */
	function setTempPasskey($email_address) {
		
		$this->u = owa_coreAPI::entityFactory('base.user');
		$this->u->getByColumn('email_address', $email_address);
		
		$id = $u->get('id');

		if (!empty($id)):
		
			$this->eq->log(array('email_address' => $this->u->email_address), 'user.set_temp_passkey');
			return true;
		else:
			return false;
		endif;
		
	}
	
	function generateTempPasskey($seed) {
		
		return md5($seed.time().rand());
	}
	
	function generateUrlPasskey($user_name, $password) {
		
		return md5($user_name . $password);
		
	}
	
	/**
	 * Sets the initial Passkey for a new user
	 *
	 * @param string $user_id
	 * @return boolean
	 * @deprecated 
	 */
	function setInitialPasskey($user_id) {
		
		return $this->eq->log(array('user_id' => $user_id), 'user.set_initial_passkey');
		
	}
	
	function _setCredentials($user_id = '', $password = '', $passkey = '') {
		
		$this->credentials['user_id'] = $user_id;
		$this->credentials['password'] = $password;
		$this->credentials['passkey'] = $passkey;
		
		return;
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
		
		$this->_setCredentials($user_id, $this->encryptPassword($password));
		
		$is_user = $this->isUser();
		
		$data = array();
		
		if ($is_user == true):
			$this->e->debug('setting user credential cookies');
			$this->saveCredentials();
			$data['auth_status'] = true;
			
		else:
			$data['auth_status'] = false;
		endif;
		
		return $data;
	}
	
	/**
	 * Saves login credentails to persistant browser cookies
	 *
	 */
	function saveCredentials() {
		
		setcookie($this->config['ns'].'u', $this->u->get('user_id'), time()+3600*24*365*30, '/', $this->config['cookie_domain']);
		setcookie($this->config['ns'].'p', $this->u->get('password'), time()+3600*24*365*30, '/', $this->config['cookie_domain']);
		
		return;
	}
	
	/**
	 * Removes credentials
	 *
	 * @return boolean
	 */
	function deleteCredentials() {
		
		return setcookie($this->config['ns'].'p', '', time()-3600*24*365*30, '/', $this->config['cookie_domain']);

	}
	
	/**
	 * Simple Password Encryption Scheme
	 *
	 * @param string $password
	 * @return string
	 */
	function encryptPassword($password) {
		
		return md5(strtolower($password).strlen($password));
	}
	
}

?>