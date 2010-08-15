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

/**
 * Generic Event Processor Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processEventController extends owa_controller {
	
	var $event;
	var $eq;
	
	function owa_processEventController($params) {
	
		return owa_processEventController::__construct($params);
	}
	
	function __construct($params) {
		
		$event = $params['event']; 
		if (!empty($event)) {
			$this->event = $params['event'];
			
		} else {
			owa_coreAPI::debug("No event object was passed to controller.");
			$this->event = owa_coreAPI::supportClassFactory('base', 'event');
		}
		
		$this->eq = eventQueue::get_instance();
		
		return parent::__construct($params);
	
	}
	
	/**
	 * Main Control Logic
	 *
	 * @return unknown
	 */
	function action() {
			
		return;
		
	}
	
	/**
	 * Must be called before all other event property setting functions
	 */
	function pre() {
		
		//$this->event->setProperties($this->params);
		
		// set site id if not already set. 
		if (!$this->event->get('site_id')) {
			$this->event->set('site_id', owa_coreAPI::getSetting('base', 'site_id'));	
		}
		
		// Set all time related properties
		$this->event->setTime(owa_coreAPI::getServerParam('REQUEST_TIME'));
		
		// re-Set GUID for event so that it is truely unique taking into account the site_id that was just set
		//$this->event->guid = $this->event->set_guid();
		//$this->event->properties['guid'] = $this->event->guid;
		
		// extract site specific state from session store
		$state = $this->loadSiteSessionState($this->event->get('site_id'));
		
		// TODO:Map standard params to standard event property names so we can do a merge of the entire site session state store
		$this->event->set('inbound_session_id', $state[owa_coreAPI::getSetting('base', 'session_param')]);
		$this->event->set('last_req', $state[owa_coreAPI::getSetting('base', 'last_request_param')]);
		
		// set inbound visitor id
		if (owa_coreAPI::getSetting('base', 'per_site_visitors')) {
			$this->event->set('inbound_visitor_id', $state[owa_coreAPI::getSetting('base', 'visitor_param')]);
		} else {
			$this->event->set('inbound_visitor_id', owa_coreAPI::getStateParam(owa_coreAPI::getSetting('base', 'visitor_param')));
		} 
		
		//set user agent
		if (!$this->event->get('HTTP_USER_AGENT')) {
			$this->event->set('HTTP_USER_AGENT', owa_coreAPI::getServerParam('HTTP_USER_AGENT'));
		} 
		
		$this->event->set('HTTP_USER_AGENT', $this->eq->filter('user_agent', $this->event->get('HTTP_USER_AGENT')));
		
		// set referer
		////needed in case javascript logger sets the referer variable but is blank
		if ($this->event->get('referer')) {
			//TODO: STANDARDIZE NAME to avoid doing this map
			$referer = $this->event->get('referer');
		} else {
			$referer = owa_coreAPI::getServerParam('HTTP_REFERER');
		}
		
		$this->event->set('HTTP_REFERER', $this->eq->filter('http_referer', $referer));
		
		
		// set host
		if (!$this->event->get('HTTP_HOST')) {
			$this->event->set('HTTP_HOST', owa_coreAPI::getServerParam('HTTP_HOST'));
		}
		
		$this->event->set('HTTP_HOST', $this->eq->filter('http_host', $this->event->get('HTTP_HOST')));
		
		// set page type to unknown if not already set by caller
		if (!$this->event->get('page_type')) {
			$this->event->set('page_type', $this->getMsg(3600));
			
		} 
		
		$this->event->set('page_type', $this->eq->filter('page_type', $this->event->get('page_type')));
		
		// Set the page url or else construct it from environmental vars
		if (!$this->event->get('page_url')) {
			$this->event->set('page_url', owa_lib::get_current_url());
		}
		
		$this->event->set('page_url', $this->eq->filter('page_url', $this->event->get('page_url')));
		// needed?
		$this->event->set('inbound_page_url', $this->event->get('page_url'));
		
		// Filter the target url of clicks
		if ($this->event->get('target_url')) {
			$this->event->set('target_url', $this->eq->filter('target_url', $this->event->get('target_url')));
		}
		
		// Set Ip Address
		if (!$this->event->get('ip_address')) {
			$this->event->set('ip_address', owa_coreAPI::getServerParam('REMOTE_ADDR'));
		}
		
		$this->event->set('ip_address', $this->eq->filter('ip_address', $this->event->get('ip_address')));
		
		// Set host related properties
		if (!$this->event->get('REMOTE_HOST')) {
			$this->event->set('REMOTE_HOST', owa_coreAPI::getServerParam('REMOTE_HOST'));
		}
		
		$this->event->set('full_host', $this->eq->filter('full_host', $this->event->get('REMOTE_HOST'), $this->event->get('ip_address')));
		$this->event->set('host', $this->eq->filter('host', $this->event->get('full_host'), $this->event->get('ip_address')));
		
		// Browser related properties
		$service = owa_coreAPI::serviceSingleton();
		$bcap = $service->getBrowscap();
		
		$this->event->set('browser_type', $this->eq->filter('browser_type', $bcap->get('Browser')));
		$this->event->set('browser', $this->eq->filter('browser', $bcap->get('Browser') . ' ' . $bcap->get('Version')));
		
		// Set Operating System
		$this->event->set('os', $this->eq->filter('operating_system', $bcap->get('Platform'), $this->event->get('HTTP_USER_AGENT')));
		
		//Check for what kind of page request this is
		if ($bcap->get('Crawler')) {
			$this->event->set('is_robot', true);
			$this->event->set('is_browser', false);

		}	
		
		// record and filter visitor personally identifiable info (PII)		
		if (owa_coreAPI::getSetting('base', 'log_visitor_pii')) {
			// set user name and email
			$cu = owa_coreAPI::getCurrentUser();
			//print_r($cu);
			$this->event->set('user_name', $this->eq->filter('user_name', $cu->user->get('user_id')));
			$this->event->set('user_email', $this->eq->filter('user_email', $cu->user->get('email_address')));
		}
		
	}
	
	function post() {
			
		return $this->addToEventQueue();
	
	}
	
	function loadSiteSessionState($site_id) {
		
		$state_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $site_id);		
		return owa_coreAPI::getStateParam($state_name);

	}
	
	function setSiteSessionState($site_id, $name, $value) {
		
		$state_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $site_id);		
		return owa_coreAPI::setState($state_name, $name, $value, 'cookie', true);
	}
	
	/**
	 * Log request properties of the first hit from a new visitor to a special cookie.
	 * 
	 * This is used to determine if the request is made by an actual browser instead 
	 * of a robot with spoofed or unknown user agent.
	 * 
	 * @access 	public
	 */
	function log_first_hit() {
			
		$state_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'first_hit_param'), $this->event->get('site_id'));
		$this->event->set('event_type', 'base.first_page_request');
		return owa_coreAPI::setState($state_name, '', $this->event->getProperties(), 'cookie', true);	
	}
	
	function addToEventQueue() {
		
		$this->eq->log($this->event, $this->event->getEventType());
		return owa_coreAPI::debug('Logged '.$this->event->getEventType().' to event queue with properties: '.print_r($this->event->getProperties(), true));

	}
	
}


?>