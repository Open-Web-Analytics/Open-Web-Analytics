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

require_once(WA_BASE_DIR.'/ini_db.php');

/**
 * User Agent Event handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_ua extends owa_observer {

	/**
	 * Browser type
	 *
	 * @var string
	 */
    var $browser_type;
    
    /**
     * Message Object
     *
     * @var unknown_type
     */
	var $obj;
	
	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Operating System
	 *
	 * @var unknown_type
	 */
	var $os;
	
	/**
	 * Debug
	 *
	 * @var string
	 */
	var $debug;
	
	/**
	 * Constructor
	 *
	 * @param string $priority
	 * @param array $conf
	 * @return Log_observer_referer
	 * @access public
	 */
    function Log_observer_ua($priority, $conf) {
				
        // Call the base class constructor
        $this->Log_observer($priority);

        // Configure the observer to handle certain events types
		$this->_event_type = array('new_session');
	
		$this->config = &wa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->debug = &owa_lib::get_debugmsgs();
		
		return;
    }

    /**
     * Event Notification
     *
     * @param unknown_type $event
     */
    function notify($event) {
		
    	$this->obj = $event['message'];
		$this->process_ua();

		return;
    }
	
    /**
     * Process the request for the referer
     *
     * @access private
     */
	function process_ua() {

		$this->determine_browser_type($this->obj->properties['ua']);
		$this->os = $this->determine_os($this->obj->properties['ua']);
			
		//write to DB
		$this->save();
		
		return;
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
	
	/**
	 * Determine the type of browser
	 * 
	 * @access 	private
	 */
	function determine_browser_type($ua) {
	
		$browser_def= $this->determine_ua_type($ua);
			
		$this->browser_type =  $browser_def['name']; 
			
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
		'Google Desktop'  		=> array('name' => 'Google Desktop', 'type' =>'feedreader'),
		'NewsGator' 			=> array('name' => 'NewsGator', 'type' =>'feedreader'),
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
		'RssReader'				=> array('name' => 'RssReader', 'type' =>'feedreader'),      
		'YahooFeedSeeker'		=> array('name' => 'My Yahoo!', 'type' =>'feedreader'),   
		'Radio Userland'		=> array('name' => 'Radio Userland', 'type' =>'feedreader'),   
		'NewsMonster'			=> array('name' => 'NewsMonster', 'type' =>'feedreader'),  
		'Safari'				=> array('name' => 'Safari', 'type' =>'webbrowser'),
		'MSIE'					=> array('name' => 'IE', 'type' =>'webbrowser'),
		'Firefox'				=> array('name' => 'FireFox', 'type' =>'webbrowser'),
		'Opera'					=> array('name' => 'Opera', 'type' =>'webbrowser'),
		   
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
	 * Save row to the database
	 * 
	 * @access private
	 */
	function save() {
		
		@$this->db->query(sprintf(
		/*	"INSERT into %s (
				id, 
				ua, 
				browser_type)
			SELECT 
				distinct('%s'), '%s', '%s'
			FROM
				%s
			WHERE
				1 NOT IN (
					SELECT
						1 
					FROM
						%s
					WHERE 
						id = '%s')",
			$this->config['ns'].$this->config['ua_table'],
			$this->obj->properties['ua_id'],
			urlencode($this->obj->properties['ua']),
			$this->browser_type,
			$this->config['ns'].$this->config['ua_table'],
			$this->config['ns'].$this->config['ua_table'],
			$this->obj->properties['ua_id']
		*/
		
				"INSERT into %s (
				id, 
				ua, 
				browser_type)
			values 
				('%s', '%s', '%s')",
			$this->config['ns'].$this->config['ua_table'],
			$this->obj->properties['ua_id'],
			urlencode($this->obj->properties['ua']),
			$this->browser_type
			
			)
		);	
		
		return;
	}
	
}

?>
