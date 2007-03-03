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
require_once(OWA_BASE_DIR.'/owa_browscap.php');

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
		
		$this->event->state = $this->params['event'];
		
		$this->event->_setProperties($this->params['caller']);
		
		// Post Process - cleanup after all properties are set
		$this->post();
		
		return $this->event->log();
		
	}
	
	function pre() {
		
		// Map standard params to standard event property names
		$this->event->properties['inbound_visitor_id'] = $this->params[$this->config['visitor_param']];
		$this->event->properties['inbound_session_id'] = $this->params[$this->config['session_param']];
		$this->event->properties['last_req'] = $this->params[$this->config['last_request_param']];
		$this->event->properties['HTTP_USER_AGENT'] = $this->params['server']['HTTP_USER_AGENT'];
		$this->event->properties['HTTP_REFERER'] = $this->params['server']['HTTP_REFERER'];
		$this->event->properties['HTTP_HOST'] = $this->params['server']['HTTP_HOST'];
		
		// Set Ip Address
		$this->event->setIp($this->params['server']['HTTP_X_FORWARDED_FOR'], $this->params['server']['HTTP_CLIENT_IP'], $this->params['server']['REMOTE_ADDR']);
		
		// Set all time related properties
		$this->event->setTime(time());
		
		// Set host related properties
		if ($this->config['resolve_hosts'] = true):
			$this->event->setHost($this->params['server']['REMOTE_HOST']);
		endif;
		
		// Browser related properties
		$this->event->properties['browser_type'] = $this->params['browscap']['Browser'];
		$this->event->properties['browser'] = $this->params['server']['Browser'] . ' ' . $this->params['browscap']['Version'];
		
		// Set Operating System
		$this->event->setOs($this->params['browscap']['Platform']);
		
		return;
		
		
	}
	
	function post() {
		
		//Clean Query Strings
		if ($this->config['clean_query_strings'] == true):
			$this->event->cleanQueryStrings();
		endif;
		
		// set site id if not already set
		if (empty($this->params['caller']['site_id'])):
			$this->event->properties['site_id'] = $this->config['site_id'];
		endif;
		
		return;	
	
	}
	
}


?>