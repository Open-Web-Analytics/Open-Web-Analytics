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

require_once(OWA_BASE_DIR.'/eventQueue.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_requestContainer.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_auth.php');

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

class owa_auth_wordpress extends owa_auth {
	
	function owa_auth_wordpress() {
		
		$this->owa_auth();
	
		return;
	}
	
	function getUser() {
		
		$this->u = owa_coreAPI::entityFactory('base.user');

		$this->u->email_address = $this->params['caller']['wordpress']['user_data']['user_email'];
		$this->u->real_name = $this->params['caller']['wordpress']['user_data']['user_identity'];
		$this->u->user_id = $this->params['caller']['wordpress']['user_data']['user_login'];
		$this->u->password = $this->params['caller']['wordpress']['user_data']['user_pass_md5'];
		
		return;
			
	}
	
	function isUser() {
		
		// fetch user object
		$this->getUser();
		
		// set priviledge level
		$this->mapPriviledgeLevel($this->params['caller']['wordpress']['user_data']['user_level']);	
		//$this->_priviledge_level = 2; //test param
		//print $this->_priviledge_level;
		
		// check to see if this is a valid user
		if (!empty($this->_priviledge_level)):
		
			// set user flag
			$this->_is_user = true;
			
			return true;
			
		else:
		
			return false;
			
		endif;
		
	}	
	
	function mapPriviledgeLevel($level) {
		
		switch($level) {
			
			case 0:
				$this->_priviledge_level = 2;
				break;
			case 1:
				$this->_priviledge_level = 2;
				break;
			case 2:
				$this->_priviledge_level = 2;
				break;
			case 3:
				$this->_priviledge_level = 2;
				break;
			case 4:
				$this->_priviledge_level = 2;
				break;
			case 5:
				$this->_priviledge_level = 2;
				break;
			case 6:
				$this->_priviledge_level = 2;
				break;
			case 7:
				$this->_priviledge_level = 2;
				break;
			case 8:
				$this->_priviledge_level = 2;
				break;
			case 9:
				$this->_priviledge_level = 2;
				break;
			case 10:
				$this->_priviledge_level = 10;
				break;
			
		}
		
		return;
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
		$data['view'] = 'base.error';
		$data['go'] = urlencode(owa_lib::get_current_url());
		$data['error_msg'] = $this->getMsg(2003);
		return $data;
	}
	
	function _setNotAuthenticatedView() {
		
		$data['view_method'] = 'delegate';
		$data['view'] = 'base.error';
		$data['go'] = urlencode(owa_lib::get_current_url());
		$data['error_msg'] = $this->getMsg(2004);
		return $data;
	}
}


?>