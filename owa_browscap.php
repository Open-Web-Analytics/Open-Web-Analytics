<?

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

require_once(OWA_BASE_DIR.'/owa_settings_class.php');

/**
 * Browscap Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_browscap {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * main browscap_db maintained by Gary Keith's 
	 * Browser Capabilities project.
	 *
	 * @var array
	 */
	var $browscap_db;
	
	/**
	 * Supplemental browscap db maintianed by OWA.
	 *
	 * @var array
	 */
	var $browscap_supplimental_db;
	
	/**
	 * Browscap Record for current User agent
	 *
	 * @var unknown_type
	 */
	var $browscap;
	
	/**
	 * Supplemental Browscap Record for current User agent
	 *
	 * @var unknown_type
	 */
	var $browscap_supplemental;
	
	/**
	 * Error Handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Current user Agent
	 *
	 * @var string
	 */
	var $ua;
	
	function owa_browscap($ua = '') {

		//Load Config
		$this->config = &owa_settings::get_settings();
		
		//Load error handler
		$this->e = &owa_error::get_instance();
		
		// set user agent
		$this->ua = $ua;
		$this->e->debug('UA: '.$this->ua);
		
		// Load main browscap
		$this->browscap_db = $this->load($this->config['browscap.ini']);
		
		//load supplemental browscap
		$this->browscap_supplemental_db = $this->load($this->config['browscap_supplemental.ini']);
		
		//lookup robot in main browscap db
		$this->browscap = $this->lookup($this->ua, $this->browscap_db);
		$this->e->debug('Browser Type:'. $this->browscap->browser);
		
		//lookup robot in supplemental browscap db
		$this->browscap_supplemental = $this->lookup($this->ua, $this->browscap_supplemental_db);
		$this->e->debug('Browser Type (supplemental):'. $this->browscap_supplemental->browser);
		
		return;
	}
	
	function robotCheck() {
		
		$main = $this->checkForCrawler_main();
		$supplemental = $this->checkForCrawler_supplemental();
		
		if ($main == true || $supplemental == true):
			return true;
		else:
			return false;
		endif;
	}
	
	function lookup($user_agent, $db) {
		
		$cap=null;
		
		foreach ($db as $key=>$value) {
			  if (($key!='*')&&(!array_key_exists('parent',$value))) continue;
			  $keyEreg='^'.str_replace(
			   array('\\','.','?','*','^','$','[',']','|','(',')','+','{','}','%'),
			   array('\\\\','\\.','.','.*','\\^','\\$','\\[','\\]','\\|','\\(','\\)','\\+','\\{','\\}','\\%'),
			   $key).'$';
			  if (preg_match('%'.$keyEreg.'%i',$user_agent))
			  {
			   $cap=array('browser_name_regex'=>strtolower($keyEreg),'browser_name_pattern'=>$key)+$value;
			   $maxDeep=8;
			   while (array_key_exists('parent',$value)&&(--$maxDeep>0))
			    $cap+=($value=$db[$value['parent']]);
			   break;
			  }
		 }
		 
		return ((object)$cap);
	
	}
	
	function checkForCrawler_main() {
		
		if ($this->browscap->browser != 'Default Browser'):
			// If browscap has the UA listed as a crawler set is_robot, except for RSS feed readers
			if ($this->browscap->parent != 'RSS Feeds'):
				if ($this->browscap->crawler == true):
					return true;
				else:
					return false;	
				endif;
			else:
				return false;
			endif;
		else:
			return false;
		endif;
	}
	
	function checkForCrawler_supplemental() {
		
		// If browscap has the UA listed as a crawler return true
		if ($this->browscap_supplemental->crawler == true):
			return true;
		else:
			return false;	
		endif;
		
	}
	
	function load($file) {
	
		return parse_ini_file($file, true);
		
	}
	
	
}



?>