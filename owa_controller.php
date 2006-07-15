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
	 * Special first hit http request Controller
	 * 
	 * This controller is used by callers who delay the first page request of new users
	 * to be processed by a second http request on the same page.
	 *
	 */
	function process_first_request() {
		
		// Create a new request object
		$r = new owa_request;
		$r->state = 'new_request';
	
		//Load request properties from first_hit cookie if it exists
		if (!empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$r->load_first_hit_properties($_COOKIE[$this->config['ns'].$this->config['first_hit_param']]);
		endif;
		
		// Log the request
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
			
			case "page_request":
				$event = new owa_request;
				break;
				
			default:
				$event = new owa_event;		
			
		}
		
		if (!empty($app_params)):
			$event->_setProperties($app_params);
		endif;
		
		// Process Event		
		$event->process();
		
		// Log Event
		$event->log();
		
		// Close Db connection if one was established
		if($this->config['async_db'] == false):
		
			$db = &owa_db::get_instance();
			$db->close();
		
		endif;
		
		return;
	}

}

?>
