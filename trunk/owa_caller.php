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
require_once 'owa_requestContainer.php';
require_once(OWA_BASE_DIR.'/owa_auth.php');
require_once(OWA_BASE_DIR.'/owa_base.php');
require_once(OWA_BASE_DIR.'/owa_coreAPI.php');
require_once(OWA_BASE_DIR.'/owa_browscap.php');

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
class owa_caller extends owa_base {
	
	/**
	 * Request Params from get or post
	 *
	 * @var array
	 */
	var $params;
	
	/**
	 * Core API
	 *
	 * @var object
	 */
	var $api;
	
	/**
	 * Constructor
	 *
	 * @param array $config
	 * @return owa_caller
	 */
	function owa_caller($config) {
		
		//set_error_handler(array("owa_error", "handlePhpError"), E_ALL);
		
		$this->owa_base();
		
		$this->config = &owa_settings::get_settings();
		
		$this->apply_caller_config($config);
		
		if ($this->config['fetch_config_from_db'] == true):
			$this->load_config_from_db();
			
			// Needed to dump the error logger because it was loaded initially with the default setting
			// and not the setting stored in the DB.
			$this->e = null;
			$this->e = owa_error::get_instance();
		endif;
		
		// Create Request Container
		$this->params = &owa_requestContainer::getInstance();
		
		// Load the core API
		$this->api = &owa_coreAPI::singleton();
		// should only be called once to load all modules
		$this->api->setupFramework();
		
		
		//ob_end_clean();
		
		return;
	
	}
	
	function handleRequestFromUrl()  {
		
		//$this->params = owa_lib::getRequestParams();
		return $this->handleRequest();
		
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
	function log($caller_params = '') {
		
		return $this->logEvent('base.processRequest', $caller_params);
		
	}
	
	/**
	 * Logs an event to the event queue
	 * 
	 * This function sets the action to be perfromed, santizes, 
	 * and adds all of PHP's $_SERVER vars to the $caller_params.
	 * $_REQUEST vars are already added to $this->params in the constructor.
	 *
	 * @param array $caller_params
	 * @param string $event_type
	 * @return boolean
	 */
	function logEvent($event_type, $caller_params = '') {
		
		// Add PHP's $_SERVER scope variables to event properties
		$params = $_SERVER;
		
		// Apply caller's params to event properties
		if (!empty($caller_params)):
			
			// Apply caller specific params
			foreach ($caller_params as $k => $v) {
				
				$params[$k] = $v;
				
			}
		
		endif;
		
		// set controller to invoke
		$params['action'] = $event_type;
		
		// Filter input
		$params = owa_lib::inputFilter($params);
		
		//Load browscap
		$bcap = new owa_browscap($params['HTTP_USER_AGENT']);  ///!
		
		// Abort if the request is from a robot
		if ($this->config['log_robots'] != true):
			if ($bcap->robotCheck() == true):
				return;
			endif;
		endif;
		
		// Fetch browser capabilities and and apply to event params
		$bcap_array = get_object_vars($bcap->browser);
	
		foreach ($bcap_array as $k => $v) {
				
			$params['browscap_'.$k] = $v;
				
		}
		
		return $this->handleRequest($params);
		
	}
	
	/**
	 * Logs event params taken from request scope (url, cookies, etc.).
	 * Takes event type from url.
	 *
	 * @return unknown
	 */
	function logEventFromUrl($caller_params) {
		ignore_user_abort(true);
		
		//$clean_params = owa_lib::inputFilter($caller_params);
		
		$striped_params = owa_lib::stripParams($caller_params);
		
		// Apply caller specific params
			foreach ($striped_params as $k => $v) {
				
				$params[$k] = base64_decode(urldecode($v));
				
			}
			
		//$this->e->debug('logEventFromUrl decoded params: '. print_r($params, true));
		
		return $this->logEvent($params['action'], $params);
		
	}
	
	function requestTag($site_id) {
		
		return $api->requestTag($site_id);
		
	}
	
	function handleHelperPageTagsRequest() {
	
		$params = array();
		$params['view'] = 'base.helperPageTags';
		$params['view_method'] = 'delegate';
		return $this->handleRequest($params);
	
	}
	
	/**
	 * Authenticated Rendering of view 
	 *
	 * @param array $caller_data
	 * @return string
	 */
	function renderView($data) {
	
		$view =  $this->api->moduleFactory($data['view'], 'View', $this->params);
		
		//perfrom authentication
		$auth = &owa_auth::get_instance();
		$auth_data = $auth->authenticateUser($view->priviledge_level);
	
		// if auth was success then procead to assemble view.
		if ($auth_data['auth_status'] == true):
	
			return $view->assembleView($data);
		else: 
			//$this->e->debug('RenderView: '.print_r($data, true));
			return $this->api->displayView($auth_data);
		endif;
		
	}
	
	/**
	 * Displays a View without user authentication. Takes array of data as input
	 *
	 * @param array $data
	 */
	function displayView($data) {
		
		$view =  $this->api->moduleFactory($data['view'], 'View', $this->params);
		
		return $view->assembleView($data);
		
	}
	
	/**
	 * Invokes controller to perform controller
	 *
	 * @param $action string
	 * 
	 */
	function performAction($action) {
		
		// Load 
		$controller = $this->api->moduleFactory($action, 'Controller', $this->params);
		
		//perfrom authentication
		$auth = &owa_auth::get_instance();
		$data = $auth->authenticateUser($controller->priviledge_level);
		
		// if auth was success then procead to do action specified in the intended controller.
		if ($data['auth_status'] == true):
			$data = $controller->doAction();
		endif;
		
		// Display view if controller calls for one.
		if (!empty($data['view'])):
		
			// 
			if ($data['view_method'] == 'delegate'):
				return $this->api->displayView($data);
			
			// Redirect to a view	
			elseif ($data['view_method'] == 'redirect'):
				owa_lib::redirectToView($data);
				return;
				
			// return an image . Will output headers and binary data.
			elseif ($data['view_method'] == 'image'):
				return $this->api->displayImage($data);
			
			else:
				return $this->api->displayView($data);
				
			endif;
		
		endif;
		
		return;
		
	}

	
	/**
	 * Handles OWA internal page/action requests
	 *
	 * @return unknown
	 */
	function handleRequest($caller_params = null) {
		
		// Override request parsms with those passed by caller
		if (!empty($caller_params)):
		
			foreach ($caller_params as $n => $v) {
				$this->params[$n] = $v;
			}
		
		endif;
		
		$this->e->debug('Request Params: '. print_r($this->params, true));
		
		if (!empty($this->params['action'])):
			
			$result =  $this->performAction($this->params['action']);
			unset($this->params['action']);
			
		elseif (!empty($this->params['do'])):
			$result =  $this->performAction($this->params['do']);
			//unset($this->params['action']);
			
		elseif ($this->params['view']):
			// its a view request so the only data is in whats in the params
			$result = $this->renderView($this->params);
			unset($this->params['view']);
			
		else:
			print 'Caller: No view or action param found. I\'m not sure what to do here.';
			return;
		endif;
		
		//clean up any open db connection
		if ($this->config['async_db'] == false):
			$db = &owa_db::get_instance();
			$db->close();
		endif;
		
		return $result;
	}
	
	
}

?>
