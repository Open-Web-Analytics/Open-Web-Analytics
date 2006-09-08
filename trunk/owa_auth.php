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
		
		return $this->roles['role']['level'];
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
	
	function setCookies() {
		
		setcookie($this->config['ns'].'u', $this->u->user_id, time()+3600*24*365*30, '/', $_SERVER['SERVER_NAME']);
		setcookie($this->config['ns'].'p', $this->u->password, time()+3600*24*365*30, '/', $_SERVER['SERVER_NAME']);
		
		return;
	}
	
}


?>