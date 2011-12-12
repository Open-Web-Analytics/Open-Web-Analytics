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

require_once(OWA_BASE_DIR.'/ini_db.php');

/**
 * Browscap Class
 * 
 * Used to load and lookup user agents in a local Browscap file
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_browscap extends owa_base {
	
	
	/**
	 * main browscap_db maintained by Gary Keith's 
	 * Browser Capabilities project.
	 *
	 * @var array
	 */
	var $browscap_db;
	
	/**
	 * Browscap Record for current User agent
	 *
	 * @var unknown_type
	 */
	var $browser;
	
	/**
	 * Current user Agent
	 *
	 * @var string
	 */
	var $ua;
	var $cache;
	var $cacheExpiration;
	
	function __construct($ua = '') {
		
		parent::__construct();
		// set user agent
		$this->ua = $ua;
		
		// init cache
		$this->cache = owa_coreAPI::cacheSingleton(); 
		$this->cacheExpiration = owa_coreAPI::getSetting('base', 'default_cache_expiration_period');
		$this->cache->setCollectionExpirationPeriod('browscap', $this->cacheExpiration);
		//lookup robot in main browscap db
		$this->browser = $this->lookup($this->ua);
		$this->e->debug('Browser Name : '. $this->browser->Browser);
		
	}
	
	function robotCheck() {
		// must use == due to wacky type issues with phpBrowsecap ini file
		if ($this->browser->Crawler == "true" || $this->browser->Crawler == "1") {
			return true;
		} elseif ($this->browser->Browser === "Default Browser") {
			return $this->robotRegexCheck();
		}
		
		return false;
	}
	
	function lookup($user_agent) {
		
		if (owa_coreAPI::getSetting('base','cache_objects') === true) {
			owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
			$cache_obj = $this->cache->get('browscap', $this->ua);
		}
		
		if (!empty($cache_obj)) {
			owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
			return $cache_obj;
					
		} else {
			owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
						
			// Load browscap file into memory
			$user_browscap_file = OWA_DATA_DIR.'browscap/php_browscap.ini';
			// check to see if a user downloaded version of the file exists
			if ( file_exists( $user_browscap_file ) ) {
				$this->browscap_db = $this->load( $user_browscap_file );	
			} else {
				$this->browscap_db = $this->load( $this->config['browscap.ini'] );
			}
		
			$cap = null;
			
			foreach ($this->browscap_db as $key=>$value) {
				  if (($key!='*')&&(!array_key_exists('Parent',$value))) continue;
				  $keyEreg='^'.str_replace(
				   array('\\','.','?','*','^','$','[',']','|','(',')','+','{','}','%'),
				   array('\\\\','\\.','.','.*','\\^','\\$','\\[','\\]','\\|','\\(','\\)','\\+','\\{','\\}','\\%'),
				   $key).'$';
				  if (preg_match('%'.$keyEreg.'%i',$user_agent))
				  {
				   $cap=array('browser_name_regex'=>strtolower($keyEreg),'browser_name_pattern'=>$key)+$value;
				   $maxDeep=8;
				   while (array_key_exists('Parent',$value)&&(--$maxDeep>0))
					$cap += ($value = $this->browscap_db[$value['Parent']]);
				   break;
				  }
			 }
			 
			if ( ! empty( $cap ) ) {
				
				if ( $this->config['cache_objects'] == true ) {
					if ( $cap['Browser'] != 'Default Browser' ) {
						$this->cache->set( 'browscap', $this->ua, (object)$cap, $this->cacheExpiration );
					}
				}
			}

			return ( (object)$cap );
		}
	}
	
	function load($file) {
	
		if(defined('INI_SCANNER_RAW')) {
        	return parse_ini_file($file, true, INI_SCANNER_RAW);
    	} else {
        	return parse_ini_file($file, true);
     	}

	}
	
	function robotRegexCheck() {
		
		$db = new ini_db(OWA_CONF_DIR.'robots.ini');
		owa_coreAPI::debug('Checking for robot strings...');
		$match = $db->contains($this->ua);
		
		if (!empty($match)):
			owa_coreAPI::debug('Robot detect string found.');
			$this->browser->Crawler = true;
			return true;
		else:
			return false;
		endif;
	
	}
	
	function get($name) {
	
		return $this->browser->$name;
	}
	
}

?>