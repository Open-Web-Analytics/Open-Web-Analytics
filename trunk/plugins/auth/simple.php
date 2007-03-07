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

require_once(OWA_BASE_DIR.'/owa_coreAPI.php');

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
	
	function owa_auth_simple() {
		
		$this->owa_auth();
		$this->check_for_credentials = true;
		
		return;
	}
	
	function getUser() {
		
		// fetch user object from the db
		$this->u = owa_coreAPI::entityFactory('base.user');
		$this->u->getByColumn('user_id', $this->credentials['user_id']);
		
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
	 * Checks to see if the user credentials match a real user object in the DB
	 *
	 * @return boolean
	 */
	function isUser() {
		
		// fetches user object from DB
		$this->getUser();
		
		// sets priviledge level
		$this->_priviledge_level = $this->getLevel($this->u->get('role'));
		
		if ($this->credentials['user_id'] == $this->u->get('user_id')):
			
			if ($this->credentials['password'] === $this->u->get('password')):
				$this->_is_user = true;				
				return true;
			else:
				$this->_is_user = false;
				return false;
			endif;
		else:
			$this->_is_user = false;
			return false;
		endif;
	}
	
	function _setNotPriviledgedView() {
		$data['view_method'] = 'delegate';
		$data['view'] = 'base.error';
		$data['error_msg'] = $this->getMsg(2003);
		$data['go'] = urlencode(owa_lib::get_current_url());
		return $data;
	}
	
	function _setNotUserView() {
		
		$data['view_method'] = 'delegate';
		$data['view'] = 'base.login';
		$data['go'] = urlencode(owa_lib::get_current_url());
		$data['error_msg'] = $this->getMsg(2002);
		return $data;
	}
	
	function _setNotAuthenticatedView() {
		
		$data['view_method'] = 'delegate';
		$data['view'] = 'base.login';
		$data['go'] = urlencode(owa_lib::get_current_url());
		$data['error_msg'] = $this->getMsg(2004);
		return $data;
	}
	
}


?>