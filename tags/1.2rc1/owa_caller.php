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

include_once('owa_env.php');
require_once('owa_requestContainer.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');
require_once(OWA_BASE_DIR.'/owa_base.php');
require_once(OWA_BASE_DIR.'/owa_coreAPI.php');

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
	
	var $start_time;
	
	var $end_time;
	
	var $update_required;
	
	var $service;
		
	/**
	 * PHP4 Constructor
	 *
	 */
	 
	function owa_caller($config) {
	
		register_shutdown_function(array(&$this, "__destruct"));
		return $this->__construct($config);
		
	}
	
	/**
	 * Constructor
	 *
	 * @param array $config
	 * @return owa_caller
	 */
	function __construct($config) {
		
		// Start time
		$this->start_time = owa_lib::microtime_float();
		
		/* LOAD CONFIG FILE */
		
		$file = OWA_BASE_DIR.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'owa-config.php';
		
		if (file_exists($file)):
			$config_file_exists = true;
			include($file);
		else:
			$config_file_exists = false;
			//$this->e->debug("I can't find your configuration file...assuming that you didn't create one.");
		endif;
		
		/* SETUP STORAGE ENGINE */
		
		// Must be called before any entities are created
		
		if (!defined('OWA_DB_TYPE')):
			owa_coreAPI::setupStorageEngine($config['db_type']);
		else:
			owa_coreAPI::setupStorageEngine(OWA_DB_TYPE);
		endif;
		
		/* SETUP CONFIGURATION AND ERROR LOGGER */
		
		// Parent Constructor. Sets default config entity and error logger
		$this->owa_base();
		
		// Log version debug
		$this->e->debug(sprintf('*** Starting Open Web Analytics v%s. Running under PHP v%s (%s) ***', OWA_VERSION, PHP_VERSION, PHP_OS));
			
		// Backtrace. handy for debugging who called OWA	
		//$bt = debug_backtrace();
		//$this->e->debug($bt[4]); 
		
		/* APPLY CONFIGURATION FILE OVERRIDES */
		
		if ($config_file_exists == true):
			
			/* ERROR LOGGING */
		
			// Looks for log level constant
			if (defined('OWA_ERROR_LOG_LEVEL')):
				$this->c->set('base', 'error_log_level', OWA_ERROR_LOG_LEVEL);
			endif;
		
			/* PHP ERROR LOGGING */
			
			if (OWA_LOG_PHP_ERRORS === true):
				$this->e->logPhpErrors();
			endif;
			
			/* CONFIGURATION ID */
			
			if (defined('OWA_CONFIGURATION_ID')):
				$this->c->set('base', 'configuration_id', OWA_CONFIGURATION_ID);
			endif;
			
			/* OBJECT CACHING */
		
			// Looks for object cache config constant
			// must comebefore user db values are fetched from db
			if (defined('OWA_CACHE_OBJECTS')):
				$this->c->set('base', 'cache_objects', OWA_CACHE_OBJECTS);
			endif;
			
		endif;
					
		/* DATABASE CONFIGURATION */
		
		// Can either be set by calling application or the config file.
		// This needs to come before the fetch of user overrides from the DB
		// Constants defined in the config file have the final word
		// values passed from calling application must be applied prior
		// to the rest of the caller's overrides
		
		if (!defined('OWA_DB_TYPE')):
			define('OWA_DB_TYPE', $config['db_type']);
		endif;
	
		$this->c->set('base', 'db_type', OWA_DB_TYPE);
				
		if (!defined('OWA_DB_NAME')):
			define('OWA_DB_NAME',  $config['db_name']);
		endif;
		
		$this->c->set('base', 'db_name', OWA_DB_NAME);
				
		if (!defined('OWA_DB_HOST')):
			define('OWA_DB_HOST',  $config['db_host']);
		endif;		
		
		$this->c->set('base', 'db_host', OWA_DB_HOST);
				
		if (!defined('OWA_DB_USER')):
			define('OWA_DB_USER',  $config['db_user']);
		endif;
		
		$this->c->set('base', 'db_user', OWA_DB_USER);
		
		if (!defined('OWA_DB_PASSWORD')):
			define('OWA_DB_PASSWORD',  $config['db_password']);
		endif;
		
		$this->c->set('base', 'db_password', OWA_DB_PASSWORD);
		
					
		/* APPLY USER CONFIGURATION OVERRIDES FROM DATABASE */
		
		if (!defined('OWA_CONFIG_DO_NOT_FETCH_FROM_DB')):
			$this->c->set('base', 'do_not_fetch_config_from_db', $config['do_not_fetch_config_from_db']);
		else:
			$this->c->set('base', 'do_not_fetch_config_from_db', OWA_CONFIG_DO_NOT_FETCH_FROM_DB);
		endif;		
		
		// Applies config from db or cache
		// check here is needed for installs when the configuration table does not exist.
		if ($this->c->get('base', 'do_not_fetch_config_from_db') != true):
			$this->c->load($this->c->get('base', 'configuration_id'));
		endif;

		/* APPLY CALLER CONFIGURATION OVERRIDES */
		
		// overrides all default and user config values except defined in the config file
		// must come after user overides are applied 
		// This will apply configuration overirdes that are specified by the calling application.
		// This is usually used by plugins to setup integration specific configuration values.
		
		$this->c->applyModuleOverrides('base', $config);
		
		$this->e->debug('Caller configuration overrides applied.');
		
		/* SET ERROR HANDLER */
		
		// Looks for log handler constant from config file otherwise respects
		// user and caller overrides
		if (defined('OWA_ERROR_HANDLER')):
			$this->c->set('base', 'error_handler', OWA_ERROR_HANDLER);
		endif;
		
		// Sets the correct mode of the error logger now that final config values are in place
		// This will flush buffered msgs that were thrown up untill this point
		$this->e->setHandler($this->c->get('base', 'error_handler'));
		
		
		/* SETUP REQUEST CONTAINER */
		$this->params = &owa_requestContainer::getInstance();
		//print_r($this->params);
		
		/**
		 * @todo This needs to be refactored into stateless api calls 
		 */
		$this->api = &owa_coreAPI::singleton();
		$this->api->caller_config_overrides = $config;
		// should only be called once to load all modules
		$this->api->setupFramework();
		// check for required schema updates and sets update flag
		// this is needed if the calling application or plugin needs to check for updates
		if (!empty($this->api->modules_needing_updates)):
			$this->update_required = true;
		endif;
		
		/* SET SITE ID */
		// needed in standalone installs where site_id is not set in config file.
		if (!empty($this->params['site_id'])):
			$this->c->set('base', 'site_id', $this->params['site_id']);
		endif;
		
		// re-fetch the array now that overrides have been applied.
		// needed for backwards compatability 
		$this->config = $this->c->fetch('base');
		
		/* LOAD SERVICE LAYER */
		$this->service = owa_coreAPI::serviceSingleton();
		
		return;
	
	}
	
	function handleRequestFromUrl()  {
		
		//$this->params = owa_lib::getRequestParams();
		return $this->handleRequest();
		
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
		
		if ($this->c->get('base', 'error_log_level') > 9):
			$this->e->debug(print_r($this->e->backtrace(), true));
		endif;
		
		//change config value to incomming site_id
		// NEEDED?
		if(!empty($caller_params['site_id'])):
			$this->config['site_id'] = $caller_params['site_id'];
			$this->c->set('base', 'site_id', $caller_params['site_id']);
		else:
			$caller_params['site_id'] = $this->c->get('base', 'site_id');
		endif;
		
		// do not log if the request is from a reserved IP
		// ips = owa_coreAPI::getSetting('base', 'log_not_log_ips');
		//	...
		
		
		// Don't Log if user is an admin
		if (owa_coreAPI::getSetting('base', 'log_named_users') != true):
			
			$cu = owa_coreAPI::getCurrentUser();
			$cu_user_id = $cu->getUserData('user_id');
			
			if(!empty($cu_user_id)):
				return false;
			endif;
		endif;
			
		
		// do not log if the do not log param is set by caller.
		if ($this->params['do_not_log'] == true):
			return false;
		endif;
		
		$params = array();
		// Add PHP's $_SERVER scope variables to event properties
		$params['server'] = $_SERVER;
		
		// Apply caller's params to event properties
		if (!empty($caller_params)):
			$params['caller'] = $caller_params;
		endif;
		
		// set controller to invoke
		$params['action'] = $event_type;
		
		// Filter input
		$params = owa_lib::inputFilter($params);
		
		//Load browscap
		$bcap = owa_coreAPI::supportClassFactory('base', 'browscap', $params['server']['HTTP_USER_AGENT']);
		
		//$bcap = new owa_browscap($params['server']['HTTP_USER_AGENT']);  ///!
		
		// Abort if the request is from a robot
		if ($this->config['log_robots'] != true):
			if ($bcap->robotCheck() == true):
				$this->e->debug("ABORTING: request appears to be from a robot");
				return;
			endif;
		endif;
		
		// Fetch browser capabilities and and apply to event params
		$params['browscap'] = get_object_vars($bcap->browser);
	
		return $this->handleRequest($params);
		
	}
	
	/**
	 * Logs event params taken from request scope (url, cookies, etc.).
	 * Takes event type from url.
	 *
	 * @return unknown
	 */
	function logEventFromUrl($caller_params) {
		
		// keeps php executing even if the client closes the connection
		ignore_user_abort(true);
		
		//$clean_params = owa_lib::inputFilter($caller_params);
		
		$striped_params = owa_lib::stripParams($caller_params);
		$params =array();
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
	
	function placeHelperPageTags($echo = true) {
		
		$params = array();
		$params['view'] = 'base.helperPageTags';
		$params['view_method'] = 'delegate';
		
		if ($echo == false):
			//return $this->handleHelperPageTagsRequest();
			return $this->handleRequest($params);
		else:
			echo $this->handleRequest($params);
			return;
		endif;
		
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
	 * @depricated
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
	 * @depricated
	 * @param array $data
	 */
	function displayView($data) {
		
		$view =  $this->api->moduleFactory($data['view'], 'View', $this->params);
		
		return $view->assembleView($data);
		
	}
	

	
	/**
	 * Handles OWA internal page/action requests
	 *
	 * @return unknown
	 */
	function handleRequest($caller_params = null) {
		
		static $init;
		
		// Override request parsms with those passed by caller
		if (!empty($caller_params)):
			$this->params = array_merge($this->params, $caller_params);
		endif;
		
		if ($init != true):
			$this->e->debug('Request Params: '. print_r($this->params, true));
		endif;
				
		if (!empty($this->params['action'])):
			$result = owa_coreAPI::performAction($this->params['action'], $this->params);
			unset($this->params['action']);
			
		elseif (!empty($this->params['do'])):
			$result = owa_coreAPI::performAction($this->params['do'], $this->params);	
			//unset($this->params['action']);
			
		elseif ($this->params['view']):
			// its a view request so the only data is in whats in the params
			$result = owa_coreAPI::displayView($this->params);
			unset($this->params['view']);
			
		else:
			$default_action = owa_coreAPI::getSetting('base', 'default_action');
			$result = owa_coreAPI::performAction($default_action, $this->params);
			//return;
			
		endif;
		
		$init = true;
		
		return $result;
	}
	
	function handleSpecialActionRequest() {
		
		if(isset($_GET['owa_specialAction'])):
			$this->e->debug("special action received");
			echo $this->handleRequestFromUrl();
			exit;
		elseif(isset($_GET['owa_logAction'])):
			$this->e->debug("log action received");
			$this->config['delay_first_hit'] = false;
			$this->c->set('base', 'delay_first_hit', false);
			echo $this->logEventFromUrl($_GET);
			exit;
		else:
			return;
		endif;

	}
	
	function __destruct() {
		
		$this->end_time = owa_lib::microtime_float();
		$total_time = $this->end_time - $this->start_time;
		$this->e->debug(sprintf('Total session time: %s',$total_time));
		$this->e->debug("goodbye from OWA");
		
		return;
	}
	
}

?>