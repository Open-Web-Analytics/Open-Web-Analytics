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

require_once 'owa_event_class.php';
require_once 'owa_settings_class.php';
require_once 'owa_request_class.php';
require_once 'owa_click.php';
require_once 'owa_lib.php';
require_once 'owa_error.php';
require_once 'owa_browscap.php';

/**
 * owa Controler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Error Handler
	 *
	 * @var object
	 */
	var $e;

	/**
	 * Constructor
	 *
	 * @return owa
	 */
	function owa() {
		
		$this->config = &owa_settings::get_settings();
		$this->e = &owa_error::get_instance();
		
		return;
	}
	
	/**
	 * Main Page Request Controller
	 *
	 * @param array $app_params
	 */
	function process_request($app_params) {
		
		// Do not log if the first_hit cookie is still present.
		if (!empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			return;
			
		endif;
		
		// Create a new request object
		$r = new owa_request;
		
		// Apply application specific data to the request
		
		$r->_setProperties($app_params);
		
		$this->e->debug(sprintf('Calling Application provided the following request params for request %d: %s, %s',
						$r->properties['request_id'],
						print_r($app_params, true),
						$r->properties['ua']));
		
		// Deterine if the request is from a known robot/crawler/spider
	
		$bcap = new owa_browscap($r->properties['ua']);
		
		if ($bcap->robotCheck == true):
			$r->is_robot = true;
		else:
			// If no match in the supplemental browscap db, do a last check for robots strings.
			$r->last_chance_robot_detect($r->properties['ua']);
		endif;
		
		// Log requests from known robots or else dump the request
			if ($r->is_robot == true):
				if ($this->config['log_robots'] == true):
					$r->properties['is_browser'] = false;
					$r->transform_request();
					$r->state = 'robot_request';
					$r->log();	
					return;
				else:
					return;
				endif;
			endif;
		
		// Log requests from feed readers
			if ($r->properties['is_feedreader'] == true):
				if ($this->config['log_feedreaders'] == true):
					$r->properties['is_browser'] = false;
					$r->properties['feed_reader_guid'] = $r->setEnvGUID();
					$r->transform_request();
					$r->state = 'feed_request';
					$r->log();
					return;
				else:
					return;
				endif;	
			endif;	
		
		$this->process($r);
		
		return;
	}
	
	/**
	 * Second stage of page request processing Logic.
	 *
	 * @param object $r
	 */
	function process($r) {
		
		// assign visitor id
		$r->assign_visitor();
		// Process the request data
		$r->transform_request();
	
		// Sessionize
		if ($this->config['log_sessions'] == true):
			$r->sessionize();
			$this->e->debug('Sessionization complete.');
		endif;
		
		// Log first hit to cookie if no visitor cookie is already set
		if ($this->config['delay_first_hit'] == true):	
			// If not, then make sure that there is an inbound visitor_id
			if (empty($r->properties['inbound_visitor_id'])):
				// Log request properties to a cookie for processing by a second request and return
				$this->e->debug('Logging this request to first hit cookie.');
				$r->log_first_hit();
				return;
			endif;
		
		endif;

		// Log the request
		$r->state = 'new_request';

		$r->log();

		return;			
					
	}
	
	/**
	 * Special first hit http request Controller
	 * 
	 * This controller is used by callers who delay the first page request of new users
	 * to be processed by a second http request on the same page.
	 *
	 */
	function process_first_request() {
		
		// Create a new request object
		$r = new owa_request;
		
		$this->log_first_request($r);

		return;
	}
	
	/**
	 * Logs first hit requests to event queue
	 *
	 * @param object $r
	 */
	function log_first_request($r) {
		
		//Load request properties from first_hit cookie if it exists
		if (!empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$r->load_first_hit_properties($_COOKIE[$this->config['ns'].$this->config['first_hit_param']]);
		endif;
		
		// Log the request
		$r->state = 'new_request';
		$r->log();
		$this->e->debug(sprintf('First hit Request %d logged to event queue',
								$r->properties['request_id']));
		
		return;
	}
	
	/**
	 * Graph Controller
	 *
	 * @param array $params
	 */
	function getGraph($params) {
	
		require_once 'owa_api.php';
	
		$g_api = owa_api::get_instance('graph');
		
		$g_api->get($params);
	
			
		return;
		
	}
	
	/**
	 * Fetch a vaue from the current configuration
	 *
	 * @param string $value
	 * @return unknown
	 */
	function get_config_value($value) {
		
		return $this->config[$value];
	}
	
	/**
	 * Alternative API for logging events direcly to the event queue
	 *
	 * @param array $app_params
	 * @param unknown_type $event_type
	 */
	function logEvent($event_type, $app_params = '') {
		
		// This should become a factory method call based on event type.
		
		switch ($event_type) {
			
			case "click":
				$event = new owa_click;
				break;
				
			default:
				$event = new owa_event;		
			
		}
		
		if (!empty($app_params)):
			$event->_setProperties($app_params);
		endif;
		
		$event->state = $event_type;
		
		$event->process();
		
		$event->log();
		
		return;
	}

}

?>
