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

class owa_auth_none extends owa_auth {
	
	function owa_auth_none($role) {
		
		$this->owa_auth();
		
		return;
	}
	
	/**
	 * Used to auth a new browser that has no cookies set
	 *
	 * @param string $user_id
	 * @param string $password
	 * @return boolean
	 */
	function authenticateNewBrowser($user_id, $password) {
		
		return;
	}
	
	
	/**
	 * Used by controllers to check if the user exists and if they are priviledged.
	 *
	 * @param string $necessary_role
	 */
	function authenticateUser($necessary_role) {
		
		return;
		
	}
	
	
	
}


?>