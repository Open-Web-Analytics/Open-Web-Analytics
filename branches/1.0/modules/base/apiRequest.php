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
	
	function getRequiredCapability() {
	
		$s = owa_coreAPI::serviceSingleton();
			// lookup method class
		$do = $s->getApiMethodClass($this->getParam('do'));

		if ($do) {
		
			// check for capability
			if (array_key_exists('required_capability', $do)) {
				return $do['required_capability'];
			}
		}
	}
	
	function doAction() {
		
					
		/* CHECK USER FOR CAPABILITIES */
		if ( ! $this->checkCapabilityAndAuthenticateUser( $this->getRequiredCapability() ) ) {
		
			return $this->data;
		}
		
		/* PERFORM PRE ACTION */
		// often used by abstract descendant controllers to set various things
		$this->pre();
		/* PERFORM MAIN ACTION */
	   	return $this->finishActionCall($this->action());			
	}
	
	function action() {
		
		$map = owa_coreAPI::getRequest()->getAllOwaParams();
		echo owa_coreAPI::executeApiCommand($map);
	}
	
	function notAuthenticatedAction() {
		
		$this->setErrorMsg('Authentication failed.');
		$this->setView('base.apiError');
	}
	
	function authenticatedButNotCapableAction($additionalMessage = '') {
		$this->setErrorMsg('Thus user is not capable to perform this api method.');
		$this->setView('base.apiError');
	}
}

/**
 * API Error View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2012 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.5.0
 */

class owa_apiErrorView extends owa_view {

	function render() {
		
		$this->t->set_template('wrapper_blank.tpl');
		$this->body->set_template('apiError.php');
		$this->body->set( 'error_msg', $this->get( 'error_msg' ) );
	}
}

?>