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
require_once 'owa_lib.php';
require_once 'eventQueue.php';

/**
 * Comment
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_event {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Event Properties
	 *
	 * @var array
	 */
	var $properties = array();
	
	/**
	 * Event Queue
	 *
	 * @var object
	 */
	var $eq;
	
	/**
	 * Error handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * State
	 *
	 * @var string
	 */
	var $state;
	
	/**
	 * Constructor
	 * @access public
	 */
	function owa_event() {
		
		$this->config = &owa_settings::get_settings();
		$this->e = &owa_error::get_instance();
		$this->eq = &eventQueue::get_instance();
		
		// Retrieve inbound visitor and session values	
		$this->properties['inbound_visitor_id'] = $_COOKIE[$this->config['ns'].$this->config['visitor_param']];
		$this->properties['inbound_session_id'] = $_COOKIE[$this->config['ns'].$this->config['session_param']];
		
		// Record time of last request
		$this->properties['last_req'] = $_COOKIE[$this->config['ns'].$this->config['last_request_param']];
		
			//epoc time
		list($msec, $sec) = explode(" ", microtime());
		$this->properties['sec'] = $sec;
		$this->properties['msec'] = $msec;
		
		//determine time of request
		$this->properties['timestamp'] = time();
		$this->properties['year'] = date("Y", $this->properties['timestamp']);
		$this->properties['month'] = date("n", $this->properties['timestamp']);
		$this->properties['day'] = date("d", $this->properties['timestamp']);
		$this->properties['dayofweek'] = date("D", $this->properties['timestamp']);
		$this->properties['dayofyear'] = date("z", $this->properties['timestamp']);
		$this->properties['weekofyear'] = date("W", $this->properties['timestamp']);
		$this->properties['hour'] = date("G", $this->properties['timestamp']);
		$this->properties['minute'] = date("i", $this->properties['timestamp']);
		$this->properties['second'] = date("s", $this->properties['timestamp']);
		
		//set default site id. Can be overwriten by caller if needed.
		$this->properties['site_id'] = $this->config['site_id'];
		
		return;
	}
	
	/**
	 * Logs event to event queue
	 *
	 */
	function log() {

		$this->eq->log($this->properties, $this->state);
		
		return;
	}
	
	/**
	 * Applies calling application specific properties to request
	 *
	 * @access 	private
	 * @param 	array $properties
	 */
	function _setProperties($properties) {
	
		if(!empty($properties)):
			foreach ($properties as $key => $value) {
			
				$this->properties[$key] = $value;
			}
		endif;
		
		return;	
	}
}

?>