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
	 * First hit flag
	 * 
	 * Used to tell if this request was loaded from the first hit cookie. 
	 *
	 * @var boolean
	 */
	var $first_hit = false;
	
	/**
	 * Event Properties
	 *
	 * @var array
	 */
	var $properties = array();
		
	/**
	 * State
	 *
	 * @var string
	 */
	//var $state;
	
	var $eventType;
	
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
		
		return owa_event::__construct();
	}
	
	function __construct() {
		
		// Set GUID for event
		$this->set('guid', $this->set_guid());
		//needed?
		$this->guid = $this->set_guid();
		
		// Assume browser untill told otherwise
		$this->set('is_browser',true);
		
		return;
	}
	
	function set($name, $value) {
		
		$this->properties[$name] = $value;
		return;
	}
	
	function get($name) {
		
		if(array_key_exists($name, $this->properties)) {
			//print_r($this->properties[$name]);
			return $this->properties[$name];
		} else {
			return false;
		}
	}
	
	/**
	 * Sets time related event properties
	 *
	 * @param integer $timestamp
	 */
	function setTime($timestamp = '') {
	
		if (empty($timestamp)) {
			$timestamp = time();
		}
		
		$this->set('timestamp', $timestamp);
		$this->set('year', date("Y", $timestamp));
		$this->set('month', date("n", $timestamp));
		$this->set('day', date("d", $timestamp));
		$this->set('dayofweek', date("D", $timestamp));
		$this->set('dayofyear', date("z", $timestamp));
		$this->set('weekofyear', date("W", $timestamp));
		$this->set('hour', date("G", $timestamp));
		$this->set('minute', date("i", $timestamp));
		$this->set('second', date("s", $timestamp));
		
		//epoc time
		list($msec, $sec) = explode(" ", microtime());
		$this->set('sec', $sec);
		$this->set('msec', $msec);
		
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
	
        return ($this->get('timestamp') - $this->get('last_req'));
	
	}
	
	/**
	 * Applies calling application specific properties to request
	 *
	 * @access 	private
	 * @param 	array $properties
	 */
	function setProperties($properties = null) {
	
		if(!empty($properties)):
			
			foreach ($properties as $key => $value) {
				if (!empty($value)):
					$this->properties[$key] = $value;
				endif;
				
			}
			
			

		endif;
		
		return;	
	}
	
	function replaceProperties($properties) {
		
		$this->properties = $properties;
		return;
	}
	
	
	/**
	 * Cleans query strings of various session params
	 * that are defined as a setting
	 */
	function cleanQueryStrings() {
		
		$properties = array('page_url', 'page_uri', 'target_url');
		
		foreach ($properties as $key) {

			if ($this->get($key)) {
				$this->set($key, $this->stripDocumentUrl($this->get($key)));
			}
				
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
				$this->set('ip_address', $HTTP_X_FORWARDED_FOR);
			else:
				$ips = array_reverse(explode(",", $HTTP_X_FORWARDED_FOR));
				$this->set('ip_address', $ips[0]);
			endif;
		
		// or else just use the remote address	
		else:
		
			if ($HTTP_CLIENT_IP):
		    	$this->set('ip_address', $HTTP_CLIENT_IP);
		  	else:
		    	$this->set('ip_address', $REMOTE_ADDR);
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
	
		return crc32(getmypid().time().rand());
	
	}
	
	function getSiteSpecificGuid() {
		
		return crc32(getmypid().time().rand().$this->get('site_id'));
		
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
		if (!empty($remote_host)) {
			// Use pre-resolved host if available
			$fullhost = $remote_host;
		} else {
			// Do the host lookup
			if (owa_coreAPI::getSetting('base', 'resolve_hosts')) {
				$fullhost = @gethostbyaddr($this->get('ip_address'));
			}
			
			// if still empty then use the IP address
			if (empty($fullhost)) {
				// just use IP address
				$fullhost = $this->get('ip_address');
			}
		}
		
		if (!empty($fullhost)) {
		
			// Sometimes gethostbyaddr returns 'unknown' or the IP address if it can't resolve the host
			if ($fullhost != $this->get('ip_address')) {
		
				$host_array = explode('.', $fullhost);
				
				// resort so top level domain is first in array
				$host_array = array_reverse($host_array);
				
				// array of tlds. this should probably be in the config array not here.
				$tlds = array('com', 'net', 'org', 'gov', 'mil');
				
				if (in_array($host_array[0], $tlds)) {
					$host = $host_array[1].".".$host_array[0];
				} else {
					$host = $host_array[2].".".$host_array[1].".".$host_array[0];
				}
					
			} elseif ($fullhost === 'unknown') {
				// Show the IP it's better than nothing. Should probably mark a dirty flag in the db
				// when this happens so one can go back and try again later.
				$host = $this->get('ip_address');
				$fullhost = $this->get('ip_address');
			} else {	
				$host = $fullhost;					
			}
				
			$this->set('host', $host);
			$this->set('full_host', $fullhost);
		
		}
				
		return;
	}	
	
	/**
	 * Strip a URL of certain GET params
	 *
	 * @return string
	 */
	function stripDocumentUrl($url) {
		
		if (owa_coreAPI::getSetting('base', 'query_string_filters')):
			$filters = str_replace(' ', '', owa_coreAPI::getSetting('base', 'query_string_filters'));
			$filters = explode(',', $filters);
		else:
			$filters = array();
		endif;
			
		// OWA specific params to filter
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').owa_coreAPI::getSetting('base', 'source_param'));
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').owa_coreAPI::getSetting('base', 'feed_subscription_id'));
		
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
	        
	        
	    //check for dangling '?'. this might occure if all params are stripped.
	        
	    // returns last character of string
		$test = substr($url, -1);   		
		
		// if dangling '?' is found clean up the url by removing it.
		if ($test == '?'):
			$url = substr($url, 0, -1);
		endif;	
			
     	owa_coreAPI::debug('striped url: '.$url);
     	
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
			$this->set('os', $os);
		else:
			$this->set('os', $this->determine_os($this->get('HTTP_USER_AGENT')));
		endif;		
	}
	
	function setSiteSessionState($site_id, $name, $value, $store_type = 'cookie') {
		
		$store_name = owa_coreAPI::getSetting('base', 'site_session_param').'_'.$site_id;
		return owa_coreAPI::setState($store_name, $name, $value, $store_type, true);
	}
	
	function deleteSiteSessionState($site_id, $store_type = 'cookie') {
	
		$store_name = owa_coreAPI::getSetting('base', 'site_session_param').'_'.$site_id;
		return owa_coreAPI::clearState($store_name);
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
			$this->set('visitor_id', $inbound_visitor_id);
			$this->set('is_repeat_visitor', true);
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
        $this->set('visitor_id', $this->getSiteSpecificGuid());
		
        // Set visitor state        
        if (owa_coreAPI::getSetting('base', 'per_site_visitors') === true) {
        	// TODO: not sure how this will work if the config calls for maintaining state on the server....
        	owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'site_session_param'), owa_coreAPI::getSetting('base', 'visitor_param'), $this->get('visitor_id'), 'cookie', true);
        } else {
        	// state for this must be maintained in a cookie
        	owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), '', $this->get('visitor_id'), 'cookie', true);
        }
		
		$this->set('is_new_visitor', true);
		
		
		return;
	
	}
	
/**
	 * Make Session IDs
	 *
	 */
	function sessionize($inbound_session_id) {
			
			
			
			// check for inbound session id
			if (!empty($inbound_session_id)):
				 
				 if ($this->get('last_req')):
				 
				 	// Calc time sinse the last request
				 	// NEEDED???
					$time_since_lastreq = $this->timeSinceLastRequest();
					$this->set('time_sinse_lastreq', $time_since_lastreq);
					$len = owa_coreAPI::getSetting('base', 'session_length');
					if ($time_since_lastreq < $len):
						owa_coreAPI::debug("Sessionize: last hit less than session length.");
						$this->set('session_id', $inbound_session_id);		
					else:
					//prev session expired, because no hits in half hour.
						owa_coreAPI::debug("Sessionize: prev session expired, because no hits in half hour.");
						$this->create_new_session($this->get('visitor_id'));
					endif;
				else:
				//session_id, but no last_req value. whats up with that?  who cares. just make new session.
					owa_coreAPI::debug("Sessionize: session_id, but no last_req value. whats up with that?  who cares. just make new session.");
					$this->create_new_session($this->get('visitor_id'));
				endif;
			else:
			//no session yet. make one.
				owa_coreAPI::debug("Sessionize: no session yet. make one.");
				$this->create_new_session($this->get('visitor_id'));
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
	    $this->set('session_id', $this->getSiteSpecificGuid());
	
		//mark entry page flag on current request
		$this->set('is_entry_page', true);
		
		//mark new session flag on current request
		$this->set('is_new_session', true);
				
		//Set the session cookie
		$this->setSiteSessionState($this->get('site_id'), owa_coreAPI::getSetting('base', 'session_param'), $this->get('session_id'));
		
		return;
	
	}

		
	function getProperties() {
		
		return $this->properties;
	}
	
	function getEventType() {
		
		if (!empty($this->eventType)) {
			return $this->eventType;
		} else {
			return $this->get('event_type');
		}
	}
	
	function setEventType($value) {
		$this->eventType = $value;
	}
	
	function cleanProperties() {
	
		return $this->setProperties(owa_lib::inputFilter($this->getProperties()));
	}
	
		
}

?>