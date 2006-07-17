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
		
		$config_from_db = &owa_settings::fetch($this->config['configuration_id']);
		
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
		
		return $this->controller->logEvent('page_request', $app_params);
		
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
		require_once(OWA_BASE_DIR.'/owa_news.php');
		
		//Fetch latest OWA news
		$rss = new owa_news;
		$news = $rss->Get($rss->config['owa_rss_url']);

		//Setup templates
		$options_page = & new owa_template;
		$options_page->set_template($options_page->config['report_wrapper']);
		$options_page->set('news', $news);
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
		
		$this->e->debug('Handling special first_hit request...');
		
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
	 * Logs event whose properties are specified on the URL
	 *
	 * @param unknown_type $event_type
	 * @return unknown
	 */
	function logEventRest($event_type) {
		
		$app_params = owa_lib::getRestparams();
	
		return $this->controller->logEvent($event_type, $app_params);
		
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
		$new_config['configuration_id'] = $this->config['configuration_id'];
			
		foreach ($form_data as $key => $value) {
				
			if ($key != 'wa_update_options'):
				// update current config
				$this->config[$key] = $value;
				//add to config going to the db
				$new_config[$key] = $value;
				
			endif;
		}
		
		owa_settings::save($new_config);
		$this->e->notice("Configuration changes saved to database.");
		
		return;
	}
	
	/**
	 * Returns All Page Tags
	 *
	 * @param boolean $echo
	 * @return string
	 */
	function placePageTags($echo = true) {
		
		$tags = $this->firstHitTag($echo);
		
		if ($this->config['log_dom_clicks'] == true):
			$tags .= $this->clickTag($echo);
		endif;
		
		if ($echo === false):
			return $tags;
		else:
			;
		endif;
			
		return;
		
	}
	
	
	/**
	 * Generates First hit javascript tag
	 *
	 * @param boolean $echo
	 * @return string
	 */
	function firstHitTag($echo = true) {
		
		if (empty($_COOKIE[$this->config['ns'].$this->config['first_hit_param']]) && empty($_COOKIE[$this->config['ns'].$this->config['visitor_param']])):	
		
			$bug  = "<script language=\"JavaScript\" type=\"text/javascript\">";
			$bug .= "document.write('<img src=\"".$this->config['action_url']."?owa_action=".$this->config['first_hit_param']."\">');</script>";
			//$bug .= "<noscript><img src=\"".$this->config['action_url']."?owa_action=".$this->config['first_hit_param']."\"></noscript>";		
			if ($echo === false):
				return $bug;
			else:
				echo $bug;
			endif;
		endif;
		
		return;

	}
	
	/**
	 * Echos the request logger javascript library
	 *
	 */
	function place_log_bug() {
		
		$url = $this->config['public_url'].'/page.php?';
		
		$bug = 'var owa_url = \'' . $url . '\';';
		
		$bug .= file_get_contents(OWA_INCLUDE_DIR.'/webbug.js');
		
		echo $bug;
		
		return;
		
	}
	
	function clickTag($echo = true) {
		
 		$tag = sprintf('<SCRIPT TYPE="text/javascript" SRC="%s?owa_action=click_bug&random=%s"></SCRIPT><DIV ID="owa_click_bug"></DIV>', 
 						$this->config['action_url'],
 						rand());
 						
 		if ($echo === false):
			return $tag;
		else:
			echo $tag;
		endif;
		
		return;

	}
	
	function place_click_bug() {
		
		$url = $this->config['action_url'].'?owa_action=log_event&event=click&';
		
		$js = file_get_contents(OWA_INCLUDE_DIR.'/clickbug.js');
		
		$bug = sprintf($js, $url, $url); 	
		
		echo $bug;
		
		return;
		
	}
	
	function requestTag($site_id) {
		
		$tag  = '<SCRIPT language="JavaScript">'."\n";
		$tag .= "\t".sprintf('var owa_site_id = %s', $site_id)."\n";
		$tag .= '</SCRIPT>'."\n\n";
 		$tag .= sprintf('<SCRIPT TYPE="text/javascript" SRC="%s/wb.php"></SCRIPT>', 
 						$this->config['public_url']);
 		
 		return $tag;
		
	}
	
	
	/**
	 * Handler for special action requests
	 * 
	 * This is sometimes called on every request by certain frameworks so nothing sounds be outside the 
	 * switch statement.
	 *
	 */
	function actionRequestHandler() {
	
		switch ($_GET['owa_action']) {
			
			// This handles requests to log the delayed request contained in first_hit cookie  for new users.
		    case $this->config['first_hit_param']:
		    	$this->e->debug('Special action request received: first_hit');
				$this->first_request_handler();		
				exit;
				
			// This handles requests for graphs	
			case $this->config['graph_param']:
				$this->e->debug('Special action request received: graph');
				$this->getGraph();
				exit;
				
			// This handles requests for the click tracking javascript library	
			case "click_bug":
				// This is the handler for javascript request for the logging web bug.
				$this->e->debug('Special action request received: '.$_GET['owa_action']); 
				$this->place_click_bug();
				exit;
				
			// This handles requests o log an event via http 	
			case "log_event":
				$this->e->debug('Special action request received: '.$_GET['owa_action']);
				ignore_user_abort(true);
				$this->logEventRest($_GET['event']);
				
				// Return 1x1 pixel
				header('Content-type: image/gif');
				header('P3P: CP="'.$l->config['p3p_policy'].'"');
				header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
				header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
				header('Cache-Control: no-store, no-cache, must-revalidate');
				header('Cache-Control: post-check=0, pre-check=0', false);
				header('Pragma: no-cache');
				
				printf(
				  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
				  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
				);
					
				exit;
           		
        }

        return;

	}
	
}

?>
