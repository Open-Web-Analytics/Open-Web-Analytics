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

class owa_processRequestController extends owa_processEventController {
	
	function owa_processRequestController($params) {
		
		return owa_processRequestController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// Control logic
		
		// Do not log if the first_hit cookie is still present.
        $fh_state_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'first_hit_param'), $this->getParam('site_id'));
		$fh = owa_coreAPI::getStateParam($fh_state_name);
        
        if (!empty($fh)):
        	$this->e->debug('Aborting request processing due to finding first hit cookie.');
			return;
		endif;
		
		// assign visitor cookie
		$this->event->assign_visitor($this->event->get('inbound_visitor_id'));	
		// sessionize
		$this->event->sessionize($this->event->get('inbound_session_id'));
						
		return;
		
	}
	
	function post() {
				
		if (owa_coreAPI::getSetting('base', 'delay_first_hit')) {	
			if ($this->event->first_hit != true) {
				// If not, then make sure that there is an inbound visitor_id
				if (!$this->event->get('inbound_visitor_id')) {
					// Log request properties to a cookie for processing by a second request and return
					owa_coreAPI::debug('Logging this request to first hit cookie.');
					return $this->log_first_hit();
				}
			}
		}
		
		owa_coreAPI::debug('Logging '.'base.'.$this->state.' to event queue...');
		
		return $this->addToEventQueue();
	
	}
	
	
	
}


?>