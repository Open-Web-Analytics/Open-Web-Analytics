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

include_once('owa_env.php');
require_once 'owa_settings_class.php';
require_once 'owa_controller.php';
require_once 'owa_installer.php';

/**
 * Abstract Caller class used to build application specific invocation classes
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_caller {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Error handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Instance of Request/Event Controller
	 *
	 * @var object
	 */
	var $controller;
	
	/**
	 * Constructor
	 *
	 * @param array $config
	 * @return owa_caller
	 */
	function owa_caller($config) {
		
		$this->config = &owa_settings::get_settings();
		
		$this->apply_caller_config($config);
		
		if ($this->config['fetch_config_from_db'] == true):
			$this->load_config_from_db();
		endif;
		
		$this->controller = new owa;
	
		return;
	
	}
	
	/**
	 * Applies caller specific configuration params on top of 
	 * those specified on the global OWA config file.
	 *
	 * @param array $config
	 */
	function apply_caller_config($config) {
		
		if (!empty($config)):
		
			foreach ($config as $key => $value) {
				
				$this->config[$key] = $value;
				
			}

		endif;
					
		return;

	}
	
	/**
	 * Fetches instance specific configuration params from the database
	 * 
	 */
	function load_config_from_db() {
		
		$config_from_db = owa_settings::fetch($this->config['site_id']);
		
		if (!empty($config_from_db)):
			
			foreach ($config_from_db as $key => $value) {
			
				$this->config[$key] = $value;
			
			}
					
		endif;
		
		return;
	}
	
	/**
	 * Logs a Page Request
	 *
	 * @param array $app_params	This is an array of application specific request params
	 */
	function log($app_params) {
		
		return $this->controller->process_request($app_params);
		
	}
	
	/**
	 * Logs any event to the event queue
	 *
	 * @param array $app_params
	 * @param string $event_type
	 * @return boolean
	 */
	function logEvent($event_type, $app_params) {
		
		return $this->controller->logEvent($event_type, $app_params);
		
	}
	
	function install($type) {
		
		$this->config['fetch_config_from_db'] = false;
	    $installer = &owa_installer::get_instance();	   
	    $install_check = $installer->plugins[$type]->check_for_schema();
	    
	    if ($install_check == false):
		    //Install owa schema
	    	$status = $installer->plugins[$type]->install(); 
	    else:
	    	// owa already installed
	    	$status = false;
	    endif;
	    
	    return $status;
		
	}
	
	function reset_config() {
			
		$config = $this->config->get_default_config();
		$this->config->save($config);
		return;
				
	}
	
	function options_page() {
	
		require_once(OWA_BASE_DIR.'/owa_template.php');
	
		//Setup templates
		$options_page = & new owa_template;
		$options_page->set_template($options_page->config['report_wrapper']);
		$body = & new owa_template; 
		$body->set_template('options.tpl');// This is the inner template
		$body->set('config', $this->config);
		$body->set('page_title', 'OWA Options');
		$options_page->set('content', $body);
		// Make Page
		echo $options_page->fetch();
		
		return;
	}
	
	function first_request_handler() {
		
		header('Content-type: image/gif');
		header('P3P: CP="'.$this->config['p3p_policy'].'"');
		header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
		
		
		$this->e->debug('Received special OWA request. OWA action = first_hit');
		
		if (!empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
			$this->controller->process_first_request();
		endif;
			
		
				
		printf(
		  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
		  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
		);
			
		return;
	}
	
	
	function getGraph($app_params = '') {
		
		if(empty($app_params)):
			$app_params = owa_lib::getRestparams();
		endif;
				
		return $this->controller->getGraph($app_params);
		
	}
	
	/**
	 * Saves Configuration values to the database
	 *
	 * @param array $form_data
	 */
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
	
	function add_tag() {
		
		//if (empty($_COOKIE[$this->config['ns'].$this->config['visitor_param']]) && empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']])):
		if (empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']]) && (empty($_COOKIE[$this->config['ns'].$this->config['visitor_param']]))):	
			$bug  = "<script language=\"JavaScript\" type=\"text/javascript\">";
			$bug .= "document.write('<img src=\"".$this->config['action_url']."?owa_action=".$this->config['first_hit_param']."\">');</script>";
			//$bug .= "<noscript><img src=\"".$this->config['action_url']."?owa_action=".$this->config['first_hit_param']."\"></noscript>";		
			echo $bug;
		endif;
		
		return;

	}
	
	function place_log_bug() {
		
		$base_url  = "http";
		
		if($_SERVER['HTTPS']=='on'):
			$base_url .= 's';
		endif;
				
		$base_url .= '://'.$_SERVER['SERVER_NAME'];
		
		if($_SERVER['SERVER_PORT'] != 80):
			$base_url .= ':'.$_SERVER['SERVER_PORT'];
		endif;
		
		$base_url .= $this->config['public_url'].'/page.php?';
		
		$bug = 'var owa_url = \'' . $base_url . '\';';
		
		$bug .= file_get_contents(OWA_INCLUDE_DIR.'/webbug.js');
		
		echo $bug;
		
		return;
		
	}
	
	function makeTag($site_id) {
		
		$tag  = '<SCRIPT language=\"JavaScript\">';
		$tag .= sprintf('var owa_site_id = %s', $site_id);
		$tag  = '</SCRIPT>';
 		$tag  = sprintf('<SCRIPT TYPE=\"text/javascript\" SRC=\"%s/public/wb.php"></SCRIPT>', 
 						$this->config['public_url']);
 		
 		return $tag;
		
	}
}

?>
