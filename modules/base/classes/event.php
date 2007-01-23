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

require_once OWA_BASE_DIR.'/owa_base.php';
require_once OWA_BASE_DIR.'/eventQueue.php';

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

class owa_event extends owa_base {
	
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
	 * State
	 *
	 * @var string
	 */
	var $state;
	
	/**
	 * Time since last request.
	 * 
	 * Used to tell if a new session should be created.
	 *
	 * @var integer $time_since_lastreq
	 */
	var $time_since_lastreq;
	
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
		
		$this->owa_base();
		
		// Load event queue
		$this->eq = &eventQueue::get_instance();
		
		// Set GUID for event
		$this->guid = $this->set_guid();
		$this->properties['guid'] = $this->guid;
		
		// Assume browser untill told otherwise
		$this->properties['is_browser'] = true;
		
		return;
	}
	
	/**
	 * Sets time related event properties
	 *
	 * @param integer $timestamp
	 */
	function setTime($timestamp = '') {
		
		$this->properties['timestamp'] = $this->properties['REQUEST_TIME'];
		$this->properties['year'] = date("Y", $this->properties['timestamp']);
		$this->properties['month'] = date("n", $this->properties['timestamp']);
		$this->properties['day'] = date("d", $this->properties['timestamp']);
		$this->properties['dayofweek'] = date("D", $this->properties['timestamp']);
		$this->properties['dayofyear'] = date("z", $this->properties['timestamp']);
		$this->properties['weekofyear'] = date("W", $this->properties['timestamp']);
		$this->properties['hour'] = date("G", $this->properties['timestamp']);
		$this->properties['minute'] = date("i", $this->properties['timestamp']);
		$this->properties['second'] = date("s", $this->properties['timestamp']);
		
		//epoc time
		list($msec, $sec) = explode(" ", microtime());
		$this->properties['sec'] = $sec;
		$this->properties['msec'] = $msec;
		
		// Calc time sinse the last request
		$this->time_since_lastreq = $this->timeSinceLastRequest();
		
	}
	
	function setCookieDomain($domain) {
		
		$cookie_domain = $domain;
		
		return $cookie_domain;
		
	}
	
	/**
	 * Determines the time since the last request from this borwser
	 * 
	 * @access private
	 * @return integer
	 */
	function timeSinceLastRequest() {
	
        return ($this->properties['timestamp'] - $this->properties['last_req']);
	
	}
	
	function setBrowser() {
		
		$this->properties['browser_type'] = $this->properties['browscap_Browser'];
		
		$this->properties['browser'] = $this->properties['browscap_Browser'] . ' ' . $this->properties['browscap_Version'];
		
	}
	
	/**
	 * Logs event to event queue
	 *
	 */
	function log() {
		
		$this->e->debug('Logging '.$this->state.' to event queue...');
		return $this->eq->log($this->properties, $this->state);
	
	}
	
	function logEvent($event_type, $properties) {
		
		$this->e->debug('Logging '.$event_type.' to event queue...');
		return $this->eq->log($properties, $event_type);
		
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
			
			// Map standard params to standard event property names
			$this->properties['inbound_visitor_id'] = $properties[$this->config['visitor_param']];
			$this->properties['inbound_session_id'] = $properties[$this->config['session_param']];
			$this->properties['last_req'] = $properties[$this->config['last_request_param']];

		endif;
		
		return;	
	}
	
	function cleanQueryStrings() {
		
		$properties = array('page_url', 'page_uri', 'target_url');
		
		foreach ($properties as $key) {

			if (!empty($this->properties[$key])):
				$this->properties[$key] = $this->stripDocumentUrl($this->properties[$key]);
			endif;
				
		}
		
		return;
	}
	
	
	/**
	 * Get IP address from request
	 *
	 * @return string
	 * @access private
	 */
	function setIp() {
	
		if ($this->properties["HTTP_X_FORWARDED_FOR"]):
			if ($this->properties["HTTP_CLIENT_IP"]):
		   		$proxy = $this->properties["HTTP_CLIENT_IP"];
		  	else:
		    	$proxy = $this->properties["REMOTE_ADDR"];
		  	endif;
			
			$this->properties['ip_address'] = $this->properties["HTTP_X_FORWARDED_FOR"];
		else:
			if ($this->properties["HTTP_CLIENT_IP"]):
		    	$this->properties['ip_address'] = $this->properties["HTTP_CLIENT_IP"];
		  	else:
		    	$this->properties['ip_address'] = $this->properties["REMOTE_ADDR"];
			endif;
		endif;
		
		return;
	
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
	 * Resolve hostname from IP address
	 * 
	 * @access public
	 */
	function setHost($remote_host) {
	
		// See if host is already resolved
		if (!empty($remote_host)):
			// Use pre-resolved host if available
			$fullhost = $remote_host;
		else:
			// Do the host lookup
			if ($this->config['resolve_hosts'] = true):
				$fullhost = gethostbyaddr($this->properties['ip_address']);
			endif;
		endif;
		
		if (!empty($fullhost)):
		
			// Sometimes gethostbyaddr returns 'unknown' or the IP address if it can't resolve the host
			if ($fullhost != $this->properties['ip_address']):
		
				$host_array = explode('.', $fullhost);
				
				// resort so top level domain is first in array
				$host_array = array_reverse($host_array);
				
				// array of tlds. this should probably be in the config array not here.
				$tlds = array('com', 'net', 'org', 'gov', 'mil');
				
				if (in_array($host_array[0], $tlds)):
					$host = $host_array[1].".".$host_array[0];
				else:
					$host = $host_array[2].".".$host_array[1].".".$host_array[0];
				endif;
					
			elseif ($fullhost == 'unknown'):
				// Show the IP it's better than nothing. Should probably mark a dirty flag in the db
				// when this happens so one can go back and try again later.
				$host = $this->properties['ip_address'];
				$fullhost = $this->properties['ip_address'];
			else:	
				$host = $fullhost;					
			endif;
				
			$this->properties['host'] = $host;
			$this->properties['full_host'] = $fullhost;
		
		endif;
				
		return;
	}	
	
	/**
	 * Strip a URL of certain GET params
	 *
	 * @return string
	 */
	function stripDocumentUrl($url) {
		
			if (!empty($this->config['query_string_filters'])):
				$filters = str_replace(' ', '', $this->config['query_string_filters']);
				$filters = explode(',', $filters);
			else:
				$filters = array();
			endif;
			
			// OWA specific params to filter
			array_push($filters, $this->config['source_param']);
			array_push($filters, $this->config['ns'].$this->config['feed_subscription_id']);
			
			//print_r($filters);
			
			foreach ($filters as $filter => $value) {
				
	          $url = preg_replace(
	            '#\?' .
	            $value .
	            '=.*$|&' .
	            $value .
	            '=.*$|' .
	            $value .
	            '=.*&#msiU',
	            '',
	            $url
	          );
	          
	        }
		
     	//print $url;
     	
     	return $url;
		
	}
	
	/**
	 * Attempts to make a unique ID out of http request variables.
	 * This should only be used when storing state in a cookie is impossible.
	 *
	 * @return integer
	 */
	function setEnvGUID() {
		
		return crc32($this->properties['ua'].$this->properties['ip_address']);
		
	}
}

?>