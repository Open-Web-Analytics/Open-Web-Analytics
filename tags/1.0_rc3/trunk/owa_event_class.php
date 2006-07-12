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
 * Abstract OWA Event Class
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
	 * Database access object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * State
	 *
	 * @var string
	 */
	var $state;
	
	/**
	 * Event guid
	 * 
	 * @var string
	 */
	var $guid;
	
	/**
	 * Constructor
	 * @access public
	 */
	function owa_event() {
		
		$this->guid = $this->set_guid();
		$this->properties['guid'] = $this->guid;
		
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
		
		$this->properties['ip_address'] = $this->get_ip();
		$this->properties['ua'] = $_SERVER['HTTP_USER_AGENT'];
		$this->properties['site'] = $_SERVER['SERVER_NAME'];
		
		
		return;
	}
	
	/**
	 * Controller logic Stub for concrete classes
	 *
	 */
	function process() {
		
		return;
	}
	
	/**
	 * Logs event to event queue
	 *
	 */
	function log() {

		$this->eq->log($this->properties, $this->state);
		$this->e->debug('Logged '.$this->state.' to event queue...');
		return;
	}
	
	/**
	 * Applies calling application specific properties to request
	 *
	 * @access 	private
	 * @param 	array $properties
	 */
	function _setProperties($properties = null) {
	
		if(!empty($properties)):
			foreach ($properties as $key => $value) {
				if (!empty($value)):
					$this->properties[$key] = $value;
				endif;
			}
		endif;
		
		return;	
	}
	
	/**
	 * Get IP address from request
	 *
	 * @return string
	 * @access private
	 */
	function get_ip() {
	
		if ($_SERVER["HTTP_X_FORWARDED_FOR"]):
			if ($_SERVER["HTTP_CLIENT_IP"]):
		   		$proxy = $_SERVER["HTTP_CLIENT_IP"];
		  	else:
		    	$proxy = $_SERVER["REMOTE_ADDR"];
		  	endif;
			
			$ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
		else:
			if ($_SERVER["HTTP_CLIENT_IP"]):
		    	$ip = $_SERVER["HTTP_CLIENT_IP"];
		  	else:
		    	$ip = $_SERVER["REMOTE_ADDR"];
			endif;
		endif;
		
		return $ip;
	
	}
	
	/**
	 * Create guid from process id
	 *
	 * @return	integer
	 * @access 	private
	 */
	function set_guid() {
	
		return crc32(posix_getpid().$this->properties['sec'].$this->properties['msec'].rand());
	
	}
	
	/**
	 * Create guid from string
	 *
	 * @param 	string $string
	 * @return 	integer
	 * @access 	private
	 */
	function set_string_guid($string) {
	
		return crc32(strtolower($string));
	
	}
	
	/**
	 * Resolve host
	 * 
	 * @access private
	 */
	function resolve_host() {
	
		if (!empty($_SERVER['REMOTE_HOST'])):
		
			$ip = $_SERVER['REMOTE_HOST'];
		
		else:
		
			$ip = $this->properties['ip_address'];
		
		endif;
		
		$fullhost = @gethostbyaddr($ip);
			
		if ($fullhost != $ip):
	
			$host_array = explode('.', $fullhost);
			$host_array = array_reverse($host_array);
			
			$host = $host_array[2].".".$host_array[1].".".$host_array[0];
				
		else:
			$host = $fullhost;					
		endif;
			
			$this->properties['host'] = $host;
			$this->properties['host_id'] = $this->set_string_guid($host);
			
		return;
	}	
	
	/**
	 * Makes the id for the uri of the request
	 *
	 * @return integer
	 */
	function make_document_id($url) {
		
		if ($this->config['clean_query_string'] == true):
		
			if (!empty($this->config['query_string_filters'])):
				$filters = str_replace(' ', '', $this->config['query_string_filters']);
				$filters = explode(',', $this->config['query_string_filters']);
			else:
				$filters = array();
			endif;
			
			// Add OWA specific params to filter list
			$filters[] = $this->config['source_param'];
			$filters[] = $this->config['ns'].$this->config['feed_subscriber_id'];
			
			foreach ($filters as $filter) {
	          $url = preg_replace(
	            '#\?' .
	            $filter .
	            '=.*$|&' .
	            $filter .
	            '=.*$|' .
	            $filter .
	            '=.*&#msiU',
	            '',
	            $url
	          );
	          //print $this->properties['uri'];
	        }
		
	    endif;
     	
        return $this->set_string_guid($url);
		
	}
	
	/**
	 * Attempts to make a unique ID out of http request variables
	 *
	 * @return integer
	 */
	function setEnvGUID() {
		
		return crc32($this->properties['ua'].$this->properties['ip_address']);
		
	}
}

?>