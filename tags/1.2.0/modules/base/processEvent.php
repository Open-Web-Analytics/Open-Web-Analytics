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
	
	function owa_processEventController($params) {
	
		return owa_processEventController::__construct($params);
	}
	
	function __construct($params) {
	
		$this->event = owa_coreAPI::supportClassFactory('base', 'event');
		return parent::__construct($params);
	
	}
	
	/**
	 * Main Constrol Logic
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
		
		$this->event->setProperties($this->params);
		
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
		$state = $this->loadSiteSessionState($this->getParam('site_id'));
		
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
		
		// set referer
		////needed in case javascript logger sets the referer variable but is blank
		if (isset($this->params['referer'])) {
			//TODO: STANDARDIZE NAME to avoid doing this map
			$this->event->set('HTTP_REFERER', $this->getParam('referer'));
		} else {
			$this->event->set('HTTP_REFERER', owa_coreAPI::getServerParam('HTTP_REFERER'));
		}
		
		
		// set host
		if (!$this->event->get('HTTP_HOST')) {
			$this->event->set('HTTP_HOST', owa_coreAPI::getServerParam('HTTP_HOST'));
		}	
		
		// set page type to unknown if not already set by caller
		if (!$this->event->get('page_type')) {
			$this->event->set('page_type', $this->getMsg(3600));
		}
		
		// Set the uri or else construct it from environmental vars
		if (!$this->event->get('page_url')) {
			$this->event->set('page_url', owa_lib::get_current_url());
		}
		
		$this->event->set('inbound_page_url', $this->event->get('page_url'));
		
		// Set Ip Address
		//if (empty($this->event->properties['ip_address'])):
			$this->event->setIp(owa_coreAPI::getServerParam('HTTP_X_FORWARDED_FOR'), owa_coreAPI::getServerParam('HTTP_CLIENT_IP'), owa_coreAPI::getServerParam('REMOTE_ADDR'));
		//endif;
		
		// Set host related properties
		if (!$this->event->get('REMOTE_HOST')) {
			$this->event->setHost(owa_coreAPI::getServerParam('REMOTE_HOST'));
		} else {
			$this->event->setHost($this->event->get('REMOTE_HOST'));
		}
		
		// Browser related properties
		
		$bcap = owa_coreAPI::supportClassFactory('base', 'browscap', $this->event->get('HTTP_USER_AGENT'));
		$this->event->set('browser_type', $bcap->get('Browser'));
		$this->event->set('browser', $bcap->get('Browser') . ' ' . $bcap->get('Version'));
		
		// Set Operating System
		$this->event->setOs($bcap->get('Platform'));
		
		//Check for what kind of page request this is
		if ($bcap->get('Crawler')) {
			$this->event->set('is_robot', true);
			$this->event->set('is_browser', false);

		}	
		
		// set user name and email
		$cu = owa_coreAPI::getCurrentUser();
		//print_r($cu);
		$this->event->set('user_name', $cu->user->get('user_id'));
		$this->event->set('user_email', $cu->user->get('email_address'));
		
		//Clean Query Strings - keep this at end of pre
		if (owa_coreAPI::getSetting('base', 'clean_query_string')) {
			$this->event->cleanQueryStrings();
		}
		
		
		// set last request time state
		$this->setSiteSessionState($this->event->get('site_id'), owa_coreAPI::getSetting('base', 'last_request_param'), $this->event->get('sec'));

		
		return;
		
		
	}
	
	function post() {
			
		owa_coreAPI::debug('Logging '.'base.'.$this->state.' to event queue...');
		
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
		$properties = $this->event->getProperties();
		$eq = &eventQueue::get_instance();
		$eq->log($properties, $this->event->get('event_type'));
		return owa_coreAPI::debug('Logged '.$this->event->get('event_type').' to event queue with properties: '.print_r($properties, true));

	}

	
}


?>