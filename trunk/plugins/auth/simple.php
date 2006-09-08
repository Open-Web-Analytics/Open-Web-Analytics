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

require_once(OWA_BASE_DIR.'/owa_user.php');
require_once(OWA_BASE_DIR.'/eventQueue.php');

/**
 * Simple Auth Plugin
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_auth_simple extends owa_auth {
	
	function owa_auth_simple($role) {
		
		$this->owa_auth();
		$this->eq = &eventQueue::get_instance();
		
		return;
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
	
	/**
	 * Used to auth a new browser that has no cookies set
	 *
	 * @param string $user_id
	 * @param string $password
	 * @return boolean
	 */
	function authenticateNewBrowser($user_id, $password) {
		
		$this->e->debug("Login attempt from ". $user_id);
		
		$is_user = $this->isUser($user_id, $this->encryptPassword($password));
		
		if ($is_user == true):
			$this->setCookies();
			return true;
		else:
			return false;
		endif;
		
		return;
	}
	
	/**
	 * Checks to see if the user credentials match a real user object in the DB
	 *
	 * @param string $user_id
	 * @param string $password
	 * @return boolean
	 */
	function isUser($user_id, $password) {
		
		// md5 password
		
		// fetch user credenticals from the db
		$this->u = new owa_user;
		$this->u->getUserByPK($user_id);
		
		//$this->e->debug('Password-hash: '.$password);
		//$this->e->debug('Password-db  : '.$this->u->password);
		
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
	 * Used by controllers to check if the user exists and if they are priviledged.
	 *
	 * @param string $necessary_role
	 */
	function authenticateUser($necessary_role) {
		
		if (!empty($_COOKIE[$this->config['ns'].'u']) && (!empty($_COOKIE[$this->config['ns'].'p']))):
			$user_id = $_COOKIE[$this->config['ns'].'u'];
			$password = $_COOKIE[$this->config['ns'].'p'];
		else:
			$this->showLoginPage();
		endif;
		
		$is_user = $this->isUser($user_id, $password);
		
		if ($is_user == true):
			$priviledged = $this->isPriviledged($necessary_role);
				if ($priviledged == true):
					return;
				else:
					$this->showPriviledgeErrorPage();
				endif;
		else:
			$this->showLoginErrorPage();
		endif;
		
		return;
		
	}
	
	/**
	 * Send user to the Login page Controller
	 *
	 * @param array $params
	 */
	
	function showLoginPage($params = array()) {
		
		$url = $this->config['public_url'].'/login.php?page=login&go='.urlencode(owa_lib::get_current_url());
		$this->redirectToUrl($url);
		return;
		
	}
	
	/**
	 * Shown when the user does not enough priviledges
	 *
	 */
	function showPriviledgeErrorPage() {
		
		$url = $this->config['public_url'].'/login.php?page=not_priviledged';
		$this->redirectToUrl($url);
		return;
		
	}
	
	function showLoginErrorPage() {
		
		$url = $this->config['public_url'].'/login.php?page=bad_pass&go='.urlencode(owa_lib::get_current_url());
		$this->redirectToUrl($url);
		return;
		
	}
	
	/**
	 * Shown after the temp passkey is found in the database
	 *
	 */
	function showResetPasswordPage() {
		
		$url = $this->config['public_url'].'/login.php?page=reset_password';
		$this->redirectToUrl($url);
		return;
	}
	
	/**
	 * Shown when the temp passkey is not found in the DB
	 *
	 */
	function showResetPasswordErrorPage() {
		$url = $this->config['public_url'].'/login.php?page=reset_password_error';
		$this->redirectToUrl($url);
		return;
	}
	
	/**
	 * Shown when the temp passkey has been set nd mailed.
	 *
	 */
	function showRequestNewPasswordSuccessPage() {
		$url = $this->config['public_url'].'/login.php?page=request_password_success';
		$this->redirectToUrl($url);
		return;
	}
	
	function redirectToUrl($url) {
		
		header ('Location: '.$url);
		header ('HTTP/1.0 301 Moved Permanently');
		
		return;
	}
	
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
		
		return;
		
	}
	
	
}


?>