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

require_once(OWA_BASE_DIR.'/owa_base.php');
require_once(OWA_BASE_DIR.'/eventQueue.php');

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
		//$this->e->debug('hello from event class');
		return;
	}
	
	/**
	 * Sets time related event properties
	 *
	 * @param integer $timestamp
	 */
	function setTime($timestamp = '') {
		
		$this->properties['timestamp'] = $timestamp;
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
		
		$this->properties['cookie_domain'] = $domain;
		
		return;
		
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
	function setIp($HTTP_X_FORWARDED_FOR, $HTTP_CLIENT_IP, $REMOTE_ADDR) {
	
		// check for a non-unknown proxy address
		if (!empty($HTTP_X_FORWARDED_FOR) && strpos(strtolower($HTTP_X_FORWARDED_FOR), 'unknown') === false):
			
			// if more than one use the last one
			if (strpos($HTTP_X_FORWARDED_FOR, ',') === false):
				$this->properties['ip_address'] = $HTTP_X_FORWARDED_FOR;
			else:
				$ips = array_reverse(explode(",", $HTTP_X_FORWARDED_FOR));
				$this->properties['ip_address'] = $ips[0];
			endif;
		
		// or else just use the remote address	
		else:
		
			if ($HTTP_CLIENT_IP):
		    	$this->properties['ip_address'] = $HTTP_CLIENT_IP;
		  	else:
		    	$this->properties['ip_address'] = $REMOTE_ADDR;
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
	
		return crc32(getmypid().$this->properties['site_id'].$this->properties['sec'].$this->properties['msec'].rand());
	
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
			array_push($filters, $this->config['ns'].$this->config['source_param']);
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
		
     	$this->e->debug('striped url: '.$url);
     	
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
	
		/**
	 * Determine the operating system of the browser making the request
	 *
	 * @param string $user_agent
	 * @return string
	 */
	function determine_os($user_agent) {
	
			$matches = array(
				'Win.*NT 5\.0'=>'Windows 2000',
				'Win.*NT 5.1'=>'Windows XP',
				'Win.*(Vista|XP|2000|ME|NT|9.?)'=>'Windows $1',
				'Windows .*(3\.11|NT)'=>'Windows $1',
				'Win32'=>'Windows [prior to 1995]',
				'Linux 2\.(.?)\.'=>'Linux 2.$1.x',
				'Linux'=>'Linux [unknown version]',
				'FreeBSD .*-CURRENT$'=>'FreeBSD -CURRENT',
				'FreeBSD (.?)\.'=>'FreeBSD $1.x',
				'NetBSD 1\.(.?)\.'=>'NetBSD 1.$1.x',
				'(Free|Net|Open)BSD'=>'$1BSD [unknown]',
				'HP-UX B\.(10|11)\.'=>'HP-UX B.$1.x',
				'IRIX(64)? 6\.'=>'IRIX 6.x',
				'SunOS 4\.1'=>'SunOS 4.1.x',
				'SunOS 5\.([4-6])'=>'Solaris 2.$1.x',
				'SunOS 5\.([78])'=>'Solaris $1.x',
				'Mac_PowerPC'=>'Mac OS [PowerPC]',
				'Mac OS X'=>'Mac OS X',
				'X11'=>'UNIX [unknown]',
				'Unix'=>'UNIX [unknown]',
				'BeOS'=>'BeOS [unknown]',
				'QNX'=>'QNX [unknown]',
			);
			$uas = array_map(create_function('$a', 'return "#.*$a.*#";'), array_keys($matches));
			
			return preg_replace($uas, array_values($matches), $user_agent);
		
	}
	
	function determine_os_new($user_agent) {
		
		$db = new ini_db(OWA_CONF_DIR.'os.ini', $sections = true);
		$string = $db->fetch_replace($user_agent);
		
		return $string;
	}
	
	function setOs($os) {
		
		if (!empty($os)):
			$this->properties['os'] = $os;
		else:
			$this->properties['os'] = $this->determine_os($this->properties['HTTP_USER_AGENT']);
		endif;		
	}
	
	function setState($store_name, $name, $value, $per_site = false, $persistant = true) {
		
		static $state;
		
		if (empty($state[$store_name])):
			$params = &owa_requestContainer::getInstance();
			
			//print 'hello from setstate:'. print_r($params[$store_name.'_'.$this->config['site_id']], true);
			if ($per_site == true):
			
				$state[$store_name] = owa_lib::assocFromString($params[$store_name.'_'.$this->config['site_id']]);
			
			else:
				$state[$store_name] = owa_lib::assocFromString($params[$store_name]);
			endif;
			
		endif;
		
	
		if (!empty($name)):
			$state[$store_name][$name] = $value;
		else:
			$state[$store_name] = $value;
		endif;
		
		if (is_array($state[$store_name])):
			$state_value = owa_lib::implode_assoc('=>', '|||', $state[$store_name]);
		else:
			$state_value = $state[$store_name];
		endif;
		
		$this->e->debug(sprintf('Setting state: Store_name=%s, Name=%s, Value=%s, State_storage_value= %s',$store_name, $name,$value,print_r($state_value, true)));
		
		// Set cookie name
		if ($per_site == true):
			$state_name = sprintf('%s%s_%s', $this->config['ns'], $store_name, $this->config['site_id']);
		else:
			$state_name = sprintf('%s%s', $this->config['ns'], $store_name);
		endif;
		
		// set compact privacy header
		header(sprintf('P3P: CP="%s"', $this->config['p3p_policy']));
		
		return setcookie($state_name, $state_value, time()+3600*24*365*30, "/", $this->config['cookie_domain']);
		
	
	}
	
	function clearState($store_name) {
		
		return setcookie($this->config['ns'].$store_name.'_'.$this->config['site_id'], '', time()-3600*24*365*30, "/", $this->config['cookie_domain']);
	
	}


/**
	 * Assigns visitor IDs
	 *
	 */
	function assign_visitor($inbound_visitor_id) {
		
		// is this new visitor?
	
		if (empty($inbound_visitor_id)):
			$this->set_new_visitor();
		else:
			$this->properties['visitor_id'] = $inbound_visitor_id;
			$this->properties['is_repeat_visitor'] = true;
		endif;
		
		return;
	}

	
	
	/**
	 * Creates new visitor
	 * 
	 * @access 	public
	 *
	 */
	function set_new_visitor() {
	
		// Create guid
        $this->properties['visitor_id'] = $this->set_guid();
		
        // Set visitor cookie
        
        if ($this->config['per_site_visitors'] == true):
        
        	$this->setState($this->config['site_session_param'], $this->config['visitor_param'], $this->properties['visitor_id'], true);
        else:
        	$this->setState($this->config['visitor_param'], '', $this->properties['visitor_id']);
        endif;
		
		$this->properties['is_new_visitor'] = true;
		
		return;
	
	}
	
/**
	 * Make Session IDs
	 *
	 */
	function sessionize($inbound_session_id) {
		
			// check for inbound session id
			if (!empty($inbound_session_id)):
				 
				 if (!empty($this->properties['last_req'])):
							
					if ($this->time_since_lastreq < $this->config['session_length']):
						$this->properties['session_id'] = $inbound_session_id;		
						
					else:
					//prev session expired, because no hits in half hour.
						$this->create_new_session($this->properties['visitor_id']);
					endif;
				else:
				//session_id, but no last_req value. whats up with that?  who cares. just make new session.
					$this->create_new_session($this->properties['visitor_id']);
				endif;
			else:
			//no session yet. make one.
				$this->create_new_session($this->properties['visitor_id']);
			endif;
						
		return;
	}
	
	/**
	 * Creates new session id 
	 *
	 * @param 	integer $visitor_id
	 * @access 	public
	 */
	function create_new_session($visitor_id) {
	
		//generate new session ID 
	    $this->properties['session_id'] = $this->set_guid();
	
		//mark entry page flag on current request
		$this->properties['is_entry_page'] = true;
		
		//mark new session flag on current request
		$this->properties['is_new_session'] = true;
		
		//mark even state as first_page_request.
		$this->state = 'first_page_request';
		$this->properties['event_type'] = 'base.first_page_request';
		
		//Set the session cookie
		$this->setState($this->config['site_session_param'], $this->config['session_param'], $this->properties['session_id'], true);
		
                
	
		return;
	
	}


	
}

?>