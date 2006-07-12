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
require_once 'owa_lib.php';
require_once 'ini_db.php';

/**
 * Concrete Page Request Event Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_request extends owa_event {
	
	/**
	 * First hit flag
	 * 
	 * Used to tell if this request was loaded from the first hit cookie. 
	 *
	 * @var boolean
	 */
	var $first_hit = false;
	
	/**
	 * Time since last request.
	 * 
	 * Used to tell if a new session should be created.
	 *
	 * @var integer $time_since_lastreq
	 */
	var $time_since_lastreq;
	
	/**
	 * Browsecap browser info object
	 *
	 * @var object
	 */
	var $browscap;
	
	/**
	 * Constructor
	 *
	 * @return owa_request
	 * @access public
	 */
	function owa_request() {
		
		//Call to Parent Constructor
		$this->owa_event();
	
		// Set GUID for this request
		$this->properties['request_id'] = $this->guid;
		
		// Record HTTP request variables
		$this->properties['referer'] = $_SERVER['HTTP_REFERER'];
		$this->properties['referer_id'] = $this->set_string_guid($this->properties['referer']);
		//$this->properties['inbound_uri'] = $_SERVER['REQUEST_URI'];
		$this->properties['inbound_uri'] = owa_lib::get_current_url();
		$this->properties['uri'] = $this->properties['inbound_uri'];
		
		// Calc time sinse the last request
		$this->time_sinse_lastreq = $this->time_sinse_last_request();
		
		// Assume request is made by a browser. Can be overwriten by caller later on.
		$this->properties['is_browser'] = true;
		
		// Feed subscription tracking code
		$this->properties['feed_subscription_id'] = $_GET[$this->config['ns'].$this->config['feed_subscription_param']];
		
		// Traffic Source code
		$this->properties['source'] = $_GET[$this->config['source_param']];
		
		return;
	
	}
	
	/**
	 * Load request properties from delayed first hit cookie.
	 *
	 * @param 	array $properties
	 * @access 	public
	 */
	function load_first_hit_properties($properties) {
	
		$this->properties['inbound_first_hit_properties'] = $properties;
		$array = explode(",", $properties);
		
		foreach ($array as $key => $value):
		
			list($realkey, $realvalue) = split('=>', $value);
			$this->properties[$realkey] = $realvalue;
	
		endforeach;
		
		// Mark the request to avoid logging it to the first hit cookie again
		$this->first_hit = true;
		
		// Delete first_hit Cookie
		setcookie($this->config['ns'].$this->config['first_hit_param'], '', time()-3600*24*365*30, "/", $this->properties['site']);
		
		return;
	}
	
	
	/**
	 * Log request properties of the first hit from a new visitor to a special cookie.
	 * 
	 * This is used to determine if the request is made by an actual browser instead 
	 * of a robot with spoofed or unknown user agent.
	 * 
	 * @access 	public
	 */
	function log_first_hit() {
		
		$values = owa_lib::implode_assoc('=>', ',', $this->properties);
		
		setcookie($this->config['ns'].$this->config['first_hit_param'], $values, time()+3600*24*365*30, "/", $this->properties['site']);
		
		return;
	
	}
	
	/**
	 * Assigns visitor IDs
	 *
	 */
	function assign_visitor() {
		
		// is this new visitor?
	
		if (empty($this->properties['inbound_visitor_id'])):
			$this->set_new_visitor();
		else:
			$this->properties['visitor_id'] = $this->properties['inbound_visitor_id'];
			$this->properties['is_repeat_visitor'] = true;
		endif;
		
		return;
	}
	
	/**
	 * Transform current request. Assign IDs
	 *
	 * @access 	public
	 */
	function transform_request() {
			
		// Make ua id
		$this->properties['ua_id'] = $this->set_string_guid($this->properties['ua']);
		
		// Make os id
		$this->properties['os'] = $this->determine_os($this->properties['ua']);
		$this->properties['os_id'] = $this->set_string_guid($this->properties['os']);
	
		// Make document id	
		$this->properties['document_id'] = $this->make_document_id($this->properties['uri']);
		// Resolve host name
		if ($this->config['resolve_hosts'] = true):
			$this->resolve_host();
		endif;
		
		// Determine Browser type
		$this->determine_browser_type();
		
		//update last-request time cookie
		setcookie($this->config['ns'].$this->config['last_request_param'], $this->properties['sec'], time()+3600*24*365*30, "/", $this->properties['site']);
		
		return;			
		
	}
	
	function sessionize() {
		
			// check for inbound session id
			if (!empty($this->properties['inbound_session_id'])):
				 
				 if (!empty($this->properties['last_req'])):
							
					if ($this->time_sinse_lastreq < $this->config['session_length']):
						$this->properties['session_id'] = $this->properties['inbound_session_id'];			
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
		
		//Set the session cookie
        setcookie($this->config['ns'].$this->config['session_param'], $this->properties['session_id'], time()+3600*24*365*30, "/", $this->properties['site']);
	
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
        setcookie($this->config['ns'].$this->config['visitor_param'], $this->properties['visitor_id'] , time()+3600*24*365*30, "/", $this->properties['site']);
		
		$this->properties['is_new_visitor'] = true;
		
		return;
	
	}
	
	/**
	 * Determines the time sinse the last request from this borwser
	 * 
	 * @access private
	 * @return integer
	 */
	function time_sinse_last_request() {
	
        return ($this->properties['timestamp'] - $this->properties['last_req']);
	
	}
	
	/**
	 * Determine the operating system of the browser making the request
	 *
	 * @param string $user_agent
	 * @return string
	 */
	function determine_os($user_agent) {
		
		if (!empty($this->browscap->platform)):
			$this->properties['os'] = $this->browscap->platform;
			return;
		else:
	
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
		
		endif;
	}
	
	function determine_os_new($user_agent) {
		
		$db = new ini_db($this->config['os.ini'], $sections = true);
		$string = $db->fetch_replace($user_agent);
		
		return $string;
	}
	
	/**
	 * Determine the type of browser
	 * 
	 * @access 	private
	 */
	function determine_browser_type() {
		
		$this->properties['browser_type'] = $this->browscap->browser;
		
		return;
	}
	
	
	function last_chance_robot_detect($user_agent) {
		
		$db = new ini_db($this->config['robots.ini']);
		$match = $db->match($user_agent);
		
		if (!empty($match)):
			$this->e->debug(sprintf('Last chance robot detect string: %s', $match[0]));
			$this->is_robot = true;
		endif;
		
		return;
	}
	
	
	
	
}

?>
