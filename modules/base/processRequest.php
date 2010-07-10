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
        	//$this->e->debug('Clearing left over first first hit cookie.');
			//owa_coreAPI::clearState($fh_state_name);
			$this->e->debug('Left over first first hit cookie found...aborting request as likely a robot.');
			$this->event->set('do_not_log', true);
			return;
		}
		
		//mark even state as first_page_request.
		//$this->state = 'first_page_request';
		if (!$this->event->get('inbound_visitor_id')) {
			$this->event->setEventType('base.first_page_request');
		}
		
		// assign visitor cookie
		// TODO: Move this logic to the controller
		if ($this->event->get('inbound_visitor_id')) {
			$this->set('visitor_id', $this->event->get('inbound_visitor_id'));
		} else {
			$this->setNewVisitor();
		}	
		
		// sessionize
		// TODO: Move this logic to the controller
		$this->event->sessionize($this->event->get('inbound_session_id'));	
		
		// set variety of 
		if ($this->event->get('is_new_session')) {
			
			// if this is not the first sessio nthen calc days sisne last session
			if ($this->event->get('last_req')) {
				$this->event->set('days_sinse_prior_session', round(($this->event->get('timestamp') - $this->event->get('last_req'))/(3600*24)));
			}
			
			// if check for first session timestamp (fsts) value in vistor cookie
			if (owa_coreAPI::getStateParam('v', 'fsts')) {
				$fsts = owa_coreAPI::getStateParam('v', 'fsts'); 
			} else {
				// else use last session as as proxy, better than nothing
				$fsts = $this->event->get('last_req');
			}
			
			// calc days sinse first session
			if ($fsts) {
				$this->event->set('days_sinse_first_session', round(($this->event->get('timestamp') - $fsts)/(3600*24)));	
			} else {
				// this means that first session timestamp was not set in the cookie even though it's not a new user...so we set it. 
				// This can happen with users prior to 1.3.0. when this value was introduced into the cookie.
				$this->event->set('days_sinse_first_session', 0);
				
				if ($this->event->get('inbound_visitor_id')) {
					owa_coreAPI::setState('v', 'fsts', $this->event->get('timestamp'), 'cookie', true);
				}
			}
			
			// increment visit count in cookie //
			owa_coreAPI::setState('v', 'nps', $this->event->get('num_prior_sessions') + 1, 'cookie', true);
		}
			
		// set last request time state
		$this->setSiteSessionState($this->event->get('site_id'), owa_coreAPI::getSetting('base', 'last_request_param'), $this->event->get('timestamp'));
			
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
		
		owa_coreAPI::debug('Logging '.$this->event->getEventType().' to event queue...');
		
		return $this->addToEventQueue();
	
	}
}

?>