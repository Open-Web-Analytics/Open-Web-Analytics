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
		
		// Sets default config and error logger
		$this->owa_base();
		
		// Log init debug
		$this->e->debug(sprintf('*** Open Web Analytics v%s ***', OWA_VERSION));
				
		//$bt = debug_backtrace();
		//$this->e->debug($bt[4]); 
		
		
		/** 
		 * Super Global Default Config Overrides
		 * 
		 * These are constants that can be defined in the config file, plugin, or caller
		 * the will override default config values
		 */
		
		/* DATABASE CONNECTIONS */
		
		//load DB constants if not set already by caller
		if (!defined('OWA_DB_HOST')):
			$file = OWA_BASE_DIR.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'owa-config.php';
			if (file_exists($file)):
				include ($file);
			else:
				$this->e->emerg("Uh-oh. I can't find your configuration file...");
				exit;
			endif;
		endif;
		
		
		/* OBJECT CACHING */
		
		// Looks for object cache config constant
		if (!defined('OWA_OBJECT_CACHING')):
			$config['cache_objects'] = OWA_OBJECT_CACHING;
		endif;
		
		
		/* ERROR LOGGING */
		
		// Looks for log level constant
		if (!defined('OWA_ERROR_LOG_LEVEL')):
			$config['error_log_level'] = OWA_ERROR_LOG_LEVEL;
		endif;
		
		// log PHP warnings and errors
		if (OWA_LOG_PHP_ERRORS === true):
			$this->e->logPhpErrors();
		endif;
		
		
		/**
		 * User Settings Config Overrides
		 *
		 * These overrides come from user settings stored in the database
		 */
			
		// sets config ID is not already set
		if (empty($config['configuration_id'])):
			$config['configuration_id'] = 1;
		endif;	
		
		// Applies config from db or cache
		// needed for installs when the configuration table does not exist.
		if ($config['do_not_fetch_config_from_db'] != true):
			$this->c->load($config['configuration_id']);
		endif;
			
		/**
		 * Run-time Config Overrides
		 *
		 */
		 
		// Applies run time config overrides
		$this->c->applyModuleOverrides('base', $config);
		$this->e->debug('caller config overrides applied.');
		
		// re-fetch the array now that overrides have been applied.
		// needed for backwards compatability 
		$this->config = $this->c->fetch('base');

		
		/**
		 * Post Config Framework Setup
		 *
		 */
		
		// Sets the correct mode of the error logger now that final config values are in place
		// This will flush buffered msgs that were thrown up untill this point
		$this->e->setHandler($this->c->get('base', 'error_handler'));
		
		// Create Request Container
		$this->params = &owa_requestContainer::getInstance();
		
		// Load the core API
		$this->api = &owa_coreAPI::singleton();
		
		$this->api->caller_config_overrides = $config;
		
		// should only be called once to load all modules
		$this->api->setupFramework();
		
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
		
		//change config value to incomming site_id
			if(!empty($caller_params['site_id'])):
				$this->config['site_id'] = $caller_params['site_id'];
				$this->c->set('base', 'site_id', $caller_params['site_id']);
			endif;
		
		
		// do not log if the request is comming fro mthe preview plane of the admin interface
		if ($this->params['preview'] == true):
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
		if (!empty($data['view']) || !empty($data['action'])):
		
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
		
		elseif(!empty($data['do'])):
		
			if ($data['view_method'] == 'redirect'):
				owa_lib::redirectToView($data);
				return;
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
		
		static $init;
		
		// Override request parsms with those passed by caller
		if (!empty($caller_params)):
		
			foreach ($caller_params as $n => $v) {
				$this->params[$n] = $v;
			}
		
		endif;
		
		if ($init != true):
			$this->e->debug('Request Params: '. print_r($this->params, true));
		endif;
			
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
			print "Caller: No view or action param found. I'm not sure what to do here.";
			return;
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