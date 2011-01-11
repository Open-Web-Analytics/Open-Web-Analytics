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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * API Request Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_apiRequestController extends owa_controller {
		
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		$s = owa_coreAPI::serviceSingleton();
			// lookup method class
		$do = $s->getApiMethodClass($this->getParam('do'));
		
		if ($do) {
		
		// check credentials
		/* PERFORM AUTHENTICATION */
			if (array_key_exists('required_capability', $do)) {
			
				/* CHECK USER FOR CAPABILITIES */
				if ( ! owa_coreAPI::isCurrentUserCapable( $do['required_capability'] ) ) {
					// doesn't look like the currentuser has the necessary priviledges
					owa_coreAPI::debug('User does not have capability required by this controller.');
					// auth user
					$auth = &owa_auth::get_instance();
					$status = $auth->authenticateUser();
					// if auth was not successful then return login view.
					if ($status['auth_status'] != true) {
						return 'This method requires authentication.';
					} else {
						//check for needed capability again now that they are authenticated
						if (!owa_coreAPI::isCurrentUserCapable($do['required_capability'])) {
							return 'Your user does not have privileges to access this method.';	
						}
					}
				}
			}
		
			//perform
			$map = owa_coreAPI::getRequest()->getAllOwaParams();
			echo owa_coreAPI::executeApiCommand($map);		
		}
	}
}

?>