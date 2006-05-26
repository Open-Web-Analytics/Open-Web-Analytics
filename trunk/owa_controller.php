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

require_once 'owa_settings_class.php';
require_once 'owa_comment_class.php';
require_once 'owa_request_class.php';
require_once 'owa_lib.php';
require_once 'owa_error.php';

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
	 * Debug
	 *
	 * @var string
	 */
	var $debug;
	
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
		
		$this->debug = &owa_error::get_msgs();
		$this->config = &owa_settings::get_settings();
		$this->e = &owa_error::get_instance();
		return;
	}
	
	/**
	 * Normal request control logic
	 *
	 * @param array $app_params
	 */
	function process_request($app_params) {
		
		// Log first request just in case it was left over from prior page view.
		if (!empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			
			$fh = new owa_request;
			$this->log_first_request($fh);
			
		endif;
		
		// Create a new request object
		$r = new owa_request;
		
		// Apply application specific data to the request
		$this->e->debug(sprintf('Calling Application provided the following request params for request %d:<BR>%s',
						$r->properties['request_id'],
						print_r($app_params, true)));
		$r->apply_app_specific($app_params);
		
		// Deterine if the request is from a known robot/crawler/spider
			if (get_cfg_var('browscap')):
				$this->e->debug('using php built in get_browser function to determin browser type');
				$browser = get_browser(); //If available, use PHP native function
			else:
				$this->e->debug('Using get_browser_local to determine browser type');
				require_once(OWA_INCLUDE_DIR . 'php-local-browscap.php');
				$browser = get_browser_local();
				if ($browser->crawler == true):
					$r->is_robot = true;
				endif;
				
				// Regex check for robots
				$r->last_chance_robot_detect($r->properties['ua']);
			endif;
			
		// Log requests from known robots or else dump the request
			if ($r->is_robot == true):
				if ($this->config['log_robots'] == true):
					$r->transform_request();
					$r->state = 'robot_request';
					$r->log_request();	
					return;
				else:
					return;
				endif;
			endif;
		
		// Log requests from feed readers
		if ($this->config['log_feedreaders'] == true):
			if (!empty($r->properties['is_feedreader'])):	
				$r->transform_request();
				$r->state = 'feed_request';
				$r->log_request();
				return;
			endif;	
		endif;	
		
		$this->process($r);
		
		return;
	}
	
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
		$r->log_request();
		$this->e->debug(sprintf('Request %d logged to event queue',
								$r->properties['request_id']));
		
		// Hook to kick off the async event processor
  	 	if ($this->config['async_db'] == true):
    		; // fork process to process async event log.
		endif;
		
		// Print debug to screen
		if ($this->config['error_handler'] == 'development'):
			
			if($r->properties['user_name'] == 'admin'):
				print_r($this->debug);
			endif;
		endif;
		
		return;			
					
	}
	
	function process_first_request() {
		
		// Create a new request object
		$r = new owa_request;
		
		$this->log_first_request($r);

		return;
	}
	
	function log_first_request($r) {
		
		//Load request properties from first_hit cookie if it exists
		if (!empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$r->load_first_hit_properties($_COOKIE[$this->config['ns'].$this->config['first_hit_param']]);
		endif;
		
		// Log the request
		$r->state = 'new_request';
		$r->log_request();
		$this->e->debug(sprintf('First hit Request %d logged to event queue',
								$r->properties['request_id']));
		
		return;
	}
	
	/**
	 * Control logic for producing graphs
	 *
	 * @param array $params
	 */
	function get_graph($params) {
	
		require_once 'owa_api.php';
	
		$g_api = owa_api::get_instance('graph');
		
		$g_api->get($params);
	
			
		return;
		
	}
	
	function process_comment() {
		
		$comment = new owa_comment;
		$comment->state = 'new_comment';
		$comment->log();
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

}

?>
