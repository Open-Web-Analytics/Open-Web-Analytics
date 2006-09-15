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
		$this->credentials['user_id'] = owa_lib::inputFilter($_COOKIE[$this->config['ns'].'u']);
		$this->credentials['password'] = owa_lib::inputFilter($_COOKIE[$this->config['ns'].'p']);
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
	 * Used by controllers to check if the user exists and if they are priviledged.
	 *
	 * @param string $necessary_role
	 */
	function authenticateUser($necessary_role) {
		
		if (empty($this->credentials['user_id']) || (empty($this->credentials['password']))):
			$this->showLoginPage();
		endif;
		
		$is_user = $this->isUser($this->credentials['user_id'], $this->credentials['password']);
		
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
		owa_lib::redirectBrowser($url);
		return;
		
	}
	
	/**
	 * Shown when the user does not enough priviledges
	 *
	 */
	function showPriviledgeErrorPage() {
		
		$url = $this->config['public_url'].'/login.php?page=not_priviledged';
		owa_lib::redirectBrowser($url);
		return;
		
	}
	
	/**
	 * Shown when login credentials are not correct
	 *
	 */
	function showLoginErrorPage() {
		
		$url = $this->config['public_url'].'/login.php?page=bad_pass&go='.urlencode(owa_lib::get_current_url());
		owa_lib::redirectBrowser($url);
		return;
		
	}
	
	/**
	 * Shown after the temp passkey is found in the database
	 *
	 */
	function showResetPasswordPage() {
		
		$url = $this->config['public_url'].'/login.php?page=reset_password';
		owa_lib::redirectBrowser($url);
		return;
	}
	
	/**
	 * Shown when the temp passkey is not found in the DB
	 *
	 */
	function showResetPasswordErrorPage() {
		$url = $this->config['public_url'].'/login.php?page=reset_password_error';
		owa_lib::redirectBrowser($url);
		return;
	}
	
	/**
	 * Shown when the temp passkey has been set nd mailed.
	 *
	 */
	function showRequestNewPasswordSuccessPage() {
		$url = $this->config['public_url'].'/login.php?page=request_password_success';
		owa_lib::redirectBrowser($url);
		return;
	}
	
	
	/**
	 * Saves login credentails to persistant browser cookies
	 *
	 */
	function saveCredentials() {
		
		setcookie($this->config['ns'].'u', $this->u->user_id, time()+3600*24*365*30, '/', $_SERVER['SERVER_NAME']);
		setcookie($this->config['ns'].'p', $this->u->password, time()+3600*24*365*30, '/', $_SERVER['SERVER_NAME']);
		
		return;
	}
	
	
}


?>