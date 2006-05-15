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

require_once('owa_caller.php');
/**
 * Wordpress Plugin class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

/**
  * Wordpress plugin class
  * 
  * @author      Peter Adams <peter@openwebanalytics.com>
  * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
  * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
  * @category    owa
  * @package     owa
  * @version		$Revision$	      
  * @since		owa 1.0.0
  */
class owa_wp extends owa_caller {
	
	/**
	 * Constructor
	 *
	 * @return owa_wp
	 */
	function owa_wp($config = null) {
		$this->owa_caller($config);
		$this->e = &owa_error::get_instance();
		return;
	}
	
	function add_link_tracking($link) {
	
		if (!empty($_GET[$this->config['feed_subscription_id']])):
			return $link."&amp;"."from=feed"."&amp;".$this->config['feed_subscription_id']."=".$_GET[$this->config['feed_subscription_id']];
		else:
			return $link;
		endif;
	
	}
	
	function add_feed_tracking($binfo) {
		
		$guid = crc32(posix_getpid().microtime());
		
		return $binfo."&".$this->config['ns'].$this->config['feed_subscription_id']."=".$guid;
	}
	
	function add_tag() {
		
		if (empty($_COOKIE[$this->config['ns'].$this->config['visitor_param']]) && empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$bug  = "<script language=\"JavaScript\" type=\"text/javascript\">";
			$bug .= "document.write('<img src=\"".OWA_BASE_URL."?owa_action=".$this->config['first_hit_param']."\">');</script>";
			$bug .= "<noscript><img src=\"".OWA_BASE_URL."?owa_action=".$this->config['first_hit_param']."\"></noscript>";		
			echo $bug;
		endif;
		
		return;
	}
	
	function init_action() {
		
		if (isset($_GET['owa_action'])):
			$this->e->debug('Received special OWA request. OWA action = '.$_GET['owa_action']);
		endif;
			
		switch ($_GET['owa_action']) {
			
			case "first_hit":
				$this->first_request_handler();	
				exit;
				break;		
			case "graph":
				$this->graph_request_handler();
				exit;
				break;
				
		}
		
		return;
		
	}
	
	function first_request_handler() {
		
		if (isset($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$owa = new owa;
			$owa->process_first_request();
		endif;
			
		header('Content-type: image/gif');
		header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');
		header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
				
		printf(
		  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
		  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
		);
			
		return;
	}
	
	function graph_request_handler() {
		
		$params = array(
				'api_call' 		=> $_GET['graph'],
				'period'			=> $_GET['period']
			
			);
			
		$owa = new owa;
		$owa->get_graph($params);
		return;
	}
	
	function save_config($form_data) {
		
		//create the new config array
		$new_config = array();
			
		// needed for following DB queries just in case the various 
		// implementations of the GUI does not allow you to set this.
		$new_config['site_id'] = $this->config['site_id'];
			
		foreach ($form_data as $key => $value) {
				
			if ($key != 'wa_update_options'):
				// update current config
				$this->config[$key] = $value;
				//add to config going to the db
				$new_config[$key] = $value;
				
			endif;
		}
		
		owa_settings::save($new_config);
		$this->e->info("Configuration changes saved to database.");
		
		return;
	}
	
	function reset_config() {
			
		$config = $this->config->get_default_config();
		$this->config->save($config);
		return;
				
	}
	
	function load_config_from_db() {
		
		$config_from_db = owa_settings::fetch($this->config['site_id']);
		
		if (!empty($config_from_db)):
			
			foreach ($config_from_db as $key => $value) {
			
				$this->config[$key] = $value;
			
			}
					
		endif;
		
		return;
	}
		
	
}

?>