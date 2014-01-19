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
	
	function __construct($params) {
			
		return parent::__construct($params);
	}
	
	function action() {
		
		// Control logic
		
		// Do not log if the first_hit cookie is still present.
        $fh_state_name = owa_coreAPI::getSetting('base', 'first_hit_param');
		$fh = owa_coreAPI::getStateParam($fh_state_name);
        
        if (!empty($fh)) {
        	$this->e->debug('Clearing left over first first hit cookie.');
			owa_coreAPI::clearState($fh_state_name);
			$this->e->debug('Left over first first hit cookie found...aborting request as likely a robot.');
			$this->event->set('do_not_log', true);
			return;
		}
		
		// set variety of new session properties.
		if ($this->event->get('is_new_session')) {
			
		}	
	}
	
	function post() {
				
		if ( owa_coreAPI::getSetting('base', 'delay_first_hit') ) {	
			
			// If not, then make sure that there is an inbound visitor_id
			if ( ! $this->event->get( 'visitor_id' ) ) {
				// Log request properties to a cookie for processing by a second request and return
				owa_coreAPI::debug('Logging this request to first hit cookie.');
				return $this->log_first_hit();
			}
		}
		
		owa_coreAPI::debug('Logging '.$this->event->getEventType().' to event queue...');
		
		return $this->addToEventQueue();
	}
}

?>