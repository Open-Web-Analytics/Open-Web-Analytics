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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_MODULE_DIR.'processEvent.php');

/**
 * Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processFeedRequestController extends owa_processEventController {
	
	function owa_processFeedRequestController($params) {
		
		return owa_processFeedRequestController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// Feed subscription tracking code
		if (!$this->event->get('feed_subscription_id')) {
			$this->event->set('feed_subscription_id', $this->getParam(owa_coreAPI::getSetting('base', 'feed_subscription_param')));
		}
		
		//Check for what kind of page request this is			
		$this->event->set('is_feedreader',true);
		$this->event->set('is_browser', false);
		$this->event->set('feed_reader_guid', $this->event->setEnvGUID());
		
		//update last-request time cookie
		$this->event->setSiteSessionState(owa_coreAPI::getSetting('base', 'last_request_param'), $this->event->get('sec'));
		
		return;
		
	}
	
	
	
}


?>