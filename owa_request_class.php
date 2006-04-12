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

require_once 'wa_settings_class.php';
require_once 'owa_lib.php';
require_once 'eventQueue.php';
require_once 'ini_db.php';

/**
 * Request
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_request {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Dubug
	 *
	 * @var string $debug
	 */
	var $debug;
	
	/**
	 * Database access object
	 *
	 * @var object $db
	 */
	var $db;
	
	/**
	 * Request properties
	 *
	 * @var array $properties
	 */
	var $properties = array();
	
	/**
	 * Event Queue
	 *
	 * @var object $eq
	 */
	var $eq;
	
	/**
	 * First hit flag
	 * 
	 * Used to tell if this request was loaded from the first hit cookie. 
	 *
	 * @var boolean
	 */
	var $first_hit = false;
	
	/**
	 * State of request
	 *
	 * @var string $state
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
	 * Constructor
	 *
	 * @return owa_request
	 * @access public
	 */
	function owa_request() {
	
		$this->config = &wa_settings::get_settings();
		$this->debug = &owa_lib::get_debugmsgs();
		$this->eq = &eventQueue::get_instance();
	
		// Create GUID for this request
		$this->properties['request_id'] = $this->set_guid();

		// Retriece inbound vistor and session values	
		$this->properties['inbound_visitor_id'] = $_COOKIE[$this->config['ns'].$this->config['visitor_param']];
		$this->properties['inbound_session_id'] = $_COOKIE[$this->config['ns'].$this->config['session_param']];
		
		// Record time of last request
		$this->properties['last_req'] = $_COOKIE[$this->config['ns'].$this->config['last_request_param']];
		
		// Record HTTP request variables
		$this->properties['referer'] = $_SERVER['HTTP_REFERER'];
		$this->properties['referer_id'] = $this->set_string_guid($this->properties['referer']);
		$this->properties['uri'] = $_SERVER['REQUEST_URI'];
		$this->properties['ip_address'] = $this->get_ip();
		$this->properties['ua'] = $_SERVER['HTTP_USER_AGENT'];
		$this->properties['site'] = $_SERVER['SERVER_NAME'];
		
		// Determine Browser type
		$this->determine_browser_type();
		
		//epoc time
		list($msec, $sec) = explode(" ", microtime());
		$this->properties['sec'] = $sec;
		$this->properties['msec'] = $msec;
		
		//determine time of request
		$this->properties['timestamp'] = time();
		$this->properties['year'] = date("Y", $this->properties['timestamp']);
		$this->properties['month'] = date("M", $this->properties['timestamp']);
		$this->properties['day'] = date("d", $this->properties['timestamp']);
		$this->properties['dayofweek'] = date("D", $this->properties['timestamp']);
		$this->properties['dayofyear'] = date("z", $this->properties['timestamp']);
		$this->properties['weekofyear'] = date("W", $this->properties['timestamp']);
		$this->properties['hour'] = date("G", $this->properties['timestamp']);
		$this->properties['minute'] = date("i", $this->properties['timestamp']);
		$this->properties['second'] = date("s", $this->properties['timestamp']);
		
		//set default site id. Can be overwriten by caller if needed.
		$this->properties['site_id'] = $this->config['site_id'];
		
		// Calc time sinse the last request
		$this->time_sinse_lastreq = $this->time_sinse_last_request();
		
		// Assume request is made by a browser. Can be overwriten by caller later on.
		$this->properties['is_browser'] = true;
		
		return;
	
	}
	
	/**
	 * Applies calling application specific properties to request
	 *
	 * @access 	public
	 * @param 	array $app_params
	 */
	function apply_app_specific($app_params) {
	
		foreach ($app_params as $key => $value) {
		
			$this->properties[$key] = $value;
		}
			
		if 	($this->properties['is_feedreader'] == true):
			$this->properties['is_browser'] = false;	
		endif;
	
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
	 * Transform current request. Assign IDs
	 *
	 * @access 	public
	 */
	function transform_request() {
			
		// is this new visitor?
	
		if (empty($this->properties['inbound_visitor_id'])):
			$this->set_new_visitor();
		else:
			$this->properties['visitor_id'] = $this->properties['inbound_visitor_id'];
			$this->properties['is_repeat_visitor'] = true;
		endif;
			
		// Make ua id
		$this->properties['ua_id'] = $this->set_string_guid($this->properties['ua']);
		
		// Make os id
		$this->properties['os'] = $this->determine_os($this->properties['ua']);
		$this->properties['os_id'] = $this->set_string_guid($this->properties['os']);
	
		// Make document id
		$this->properties['document_id'] = $this->set_string_guid($this->properties['uri']);	
		
		// Resolve host name
		if ($this->config['resolve_hosts'] = true):
			$this->resolve_host();
		endif;
		
		//update last-request time cookie
		setcookie($this->config['ns'].$this->config['last_request_param'], $this->properties['sec'], time()+3600*24*365*30, "/", $this->properties['site']);
		
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
        setcookie($this->config['ns'].$this->config['session_param'], $this->properties['session_id'], $this->properties['sec']+3600*24*365*30, "/", $this->properties['site']);
	
		return;
	
	}
	
	/**
	 * Send request to event queue for logging
	 * 
	 * @access 	public
	 */
	function log_request() {
	
		$this->eq->log($this, $this->state);				
					
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
        setcookie($this->config['ns'].$this->config['visitor_param'], $this->properties['visitor_id'] , $this->properties['sec']+3600*24*365*10, "/", $this->properties['site']);
		
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
	
        return ($this->properties['sec'] - $this->properties['last_req']);
	
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
	
		$browser_def= $this->determine_ua_type($this->properties['ua']);
			
		$this->properties['browser_type'] =  $browser_def['name']; 
			
		$brow = print_r($browser_def, true);
 
		// need this?
		$this->debug = $this->debug.(sprintf(
		  '<table class="debug" border="1" width="100%%"><tr><td valign="top" width="">%s</td><td valign="top">%s</td></tr>',
	
		  'browscap',
		   $brow
		  
		));
  
		return;
	}
	
	/**
	 * Lookup browser type
	 *
	 * @param 	string $ua
	 * @return 	unknown
	 * @access 	private
	 */
	function determine_ua_type($ua) {
	
		$ua_defs = array(
		
		'CFNetwork' 			=> array('name' => 'OS X Network Fetch', 'type' =>'feedreader'),
		'Akregator'				=> array('name' => 'Akregator', 'type' => 'feedreader'),
		'Aggrevator'			=> array('name' => 'Aggrevator', 'type' => 'feedreader'),
		'AllTheNews'			=> array('name' => 'AllTheNews', 'type' => 'feedreader'),
		'AmphetaDesk'			=> array('name' => 'AmphetaDesk', 'type' => 'feedreader'),
		'Awasu'					=> array('name' => 'Awasu', 'type' => 'feedreader'),
		'BigBlogZoo'			=> array('name' => 'BigBlogZoo', 'type' => 'feedreader'),
		'BottomFeeder'			=> array('name' => 'BottomFeeder', 'type' => 'feedreader'),
		'Desktop Sidebar'		=> array('name' => 'Desktop Sidebar', 'type' => 'feedreader'),
		'FeedDemon'				=> array('name' => 'FeedDemon', 'type' => 'feedreader'),	
		'FeedOnFeeds'			=> array('name' => 'FeedOnFeeds', 'type' => 'feedreader'),
		'Google Desktop'		=> array('name' => 'Google Desktop', 'type' => 'feedreader'),
		'GreatNews'				=> array('name' => 'GreatNews', 'type' => 'feedreader'),
		'Liferea'				=> array('name' => 'Liferea', 'type' => 'feedreader'),
		'NewsFire'				=> array('name' => 'NewsFire', 'type' => 'feedreader'),
		'NewzCrawler'			=> array('name' => 'NewzCrawler', 'type' => 'feedreader'),
		'JetBrains Omea Reader' => array('name' => 'JetBrains Omea Reader', 'type' =>'feedreader'),
		'NewsGator' 			=> array('name' => 'NewsGator', 'type' =>'feedreader'),
		'Onfolio' 				=> array('name' => 'Onfolio', 'type' =>'feedreader'),
		'Pluck Soap Client' 	=> array('name' => 'Pluck', 'type' =>'feedreader'),
		'PulpFiction' 			=> array('name' => 'PulpFiction', 'type' =>'feedreader'),
		'RssBandit' 			=> array('name' => 'RssBandit', 'type' =>'feedreader'),
		'RSSOwl' 				=> array('name' => 'RSSOwl', 'type' =>'feedreader'),
		'RssReader' 			=> array('name' => 'RssReader', 'type' =>'feedreader'),
		'Shrook' 				=> array('name' => 'Shrook', 'type' =>'feedreader'),
		'Snarfer' 				=> array('name' => 'Snarfer', 'type' =>'feedreader'),
		'Straw' 				=> array('name' => 'Straw', 'type' =>'feedreader'),
		'Syndirella' 			=> array('name' => 'Syndirella', 'type' =>'feedreader'),
		'Tickershock' 			=> array('name' => 'Tickershock', 'type' =>'feedreader'),
		'Tristana' 				=> array('name' => 'Tristana', 'type' =>'feedreader'),
		'NewsGatorOnline' 		=> array('name' => 'NewsGatorOnline', 'type' =>'feedreader'),
		'NIF' 					=> array('name' => 'NewsIsFree', 'type' =>'feedreader'),
		'PluckFeedCrawler' 		=> array('name' => 'PluckFeedCrawler', 'type' =>'feedreader'),
		'Rojo' 					=> array('name' => 'Rojo', 'type' =>'feedreader'),
		'Technoratibot' 		=> array('name' => 'Technorati', 'type' =>'feedreader'),
		'TrillianPro' 			=> array('name' => 'Trillian Pro', 'type' =>'feedreader'),
		'Feedster'				=> array('name' => 'feedster', 'type' =>'feedreader'),
		'FeedRover'				=> array('name' => 'feedrover', 'type' =>'feedreader'),
		'Bloglines'				=> array('name' => 'Bloglines', 'type' =>'feedreader'),
		'NetNewsWire'			=> array('name' => 'NetNewsWire', 'type' =>'feedreader'),   
		'FeedDemon'				=> array('name' => 'FeedDemon', 'type' =>'feedreader'), 
		'Syndic8'				=> array('name' => 'Syndic8', 'type' =>'robot'), 
		'PubSub'				=> array('name' => 'PubSub', 'type' =>'robot'), 
		'MagpieRSS'				=> array('name' => 'MagpieRSS', 'type' =>'vreader'),  
		'SharpReader'			=> array('name' => 'SharpReader', 'type' =>'feedreader'),           
		'YahooFeedSeeker'		=> array('name' => 'My Yahoo!', 'type' =>'feedreader'),   
		'Radio Userland'		=> array('name' => 'Radio Userland', 'type' =>'feedreader'),   
		'NewsMonster'			=> array('name' => 'NewsMonster', 'type' =>'feedreader'),  
		'Safari'				=> array('name' => 'Safari', 'type' =>'webbrowser'),
		'MSIE'					=> array('name' => 'IE', 'type' =>'webbrowser'),
		'Firefox'				=> array('name' => 'FireFox', 'type' =>'webbrowser'),
		'Opera'					=> array('name' => 'Opera', 'type' =>'webbrowser')
		   
		);
		
		foreach($ua_defs as $k => $v)	{
		
			 $pos = strpos(strtolower($ua), strtolower($k));
			 			
			 if ($pos === false):
				
				$browser = array('Unknown', 'Unknown');
					
			 else:
		 	
				$browser = $v;
				return $browser;
			 endif;
		}
	
		return $browser;
			
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
	
	
	
}

?>
