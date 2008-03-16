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
	
		$this->owa_controller($params);
		
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	/**
	 * Main Constrol Logic
	 *
	 * @return unknown
	 */
	function action() {
		
		// Setup generic event model
		
		$this->event = owa_coreAPI::supportClassFactory('base', 'event');
		
		// Pre process - default and standard properties
		$this->pre();
		
		$this->event->state = $this->params['caller']['event'];
		
		$this->event->_setProperties($this->params['caller']);
		
		$this->event->sessionize($this->event->properties['inbound_session_id']);
		
		// Post Process - cleanup after all properties are set
		$this->post();
		
		return $this->event->log();
		
	}
	
	/**
	 * Must be called before all other event property setting functions
	 */
	function pre() {
		
		// set site id if not already set . Needed for GUID generation of event
		if (empty($this->params['caller']['site_id'])):
			$this->event->properties['site_id'] = $this->config['site_id'];
		else:
			$this->event->properties['site_id'] = $this->params['caller']['site_id'];
		endif;
		
		// re-Set GUID for event so that it is truely unique taking into account the site_id that was just set
		$this->event->guid = $this->event->set_guid();
		$this->event->properties['guid'] = $this->event->guid;
		
		// extract site specific state from session store
		$state = $this->loadSiteState($this->params[$this->config['site_session_param'].'_'.$this->config['site_id']]);
		
		// Map standard params to standard event property names
		$this->event->properties['inbound_session_id'] = $state[$this->config['session_param']];
		$this->event->properties['last_req'] = $state[$this->config['last_request_param']];
		
		if ($this->config['per_site_visitors'] == true):
			$this->event->properties['inbound_visitor_id'] = $state[$this->config['visitor_param']];
		else:
			$this->event->properties['inbound_visitor_id'] = $this->params[$this->config['visitor_param']];
		endif;

		
		
		
		$this->event->properties['HTTP_USER_AGENT'] = $this->params['server']['HTTP_USER_AGENT'];
		
		//needed in case javascript logger sets the referer variable but is blank
		if (isset($this->params['referer'])):
			$this->event->properties['HTTP_REFERER'] = $this->params['caller']['referer'];
		else:
			$this->event->properties['HTTP_REFERER'] = $this->params['server']['HTTP_REFERER'];
		endif;
			
		$this->event->properties['HTTP_HOST'] = $this->params['server']['HTTP_HOST'];
		
		// Set Ip Address
		//if (empty($this->event->properties['ip_address'])):
			$this->event->setIp($this->params['server']['HTTP_X_FORWARDED_FOR'], $this->params['server']['HTTP_CLIENT_IP'], $this->params['server']['REMOTE_ADDR']);
		//endif;
		
		// Set all time related properties
		$this->event->setTime(time());
		
		// Set host related properties
		if ($this->config['resolve_hosts'] = true):
			$this->event->setHost($this->params['server']['REMOTE_HOST']);
		endif;
		
		// Browser related properties
		$this->event->properties['browser_type'] = $this->params['browscap']['Browser'];
		$this->event->properties['browser'] = $this->params['browscap']['Browser'] . ' ' . $this->params['browscap']['Version'];
		
		// Set Operating System
		$this->event->setOs($this->params['browscap']['Platform']);
		
		return;
		
		
	}
	
	function post() {
		
		//Clean Query Strings
		if ($this->config['clean_query_string'] == true):
			$this->event->cleanQueryStrings();
		endif;
		
		$this->event->assign_visitor($this->event->properties['inbound_visitor_id']);
		
				
		return;	
	
	}
	
	function loadSiteState($string_state) {
		
		
		if (!empty($string_state)):
			$state = owa_lib::assocFromString($string_state);		
		endif;
		
		$this->e->debug('state: '.print_r($state, true));
			
			
		return $state;
	
	}
	
}


?>