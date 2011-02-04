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

require_once(OWA_BASE_DIR.'/owa_lib.php');

/**
 * OWA Core API
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_coreAPI {
		
	public static function &singleton($params = array()) {
		
		static $api;
		
		if(!isset($api)):
			$api = new owa_coreAPI();
		endif;
		
		if(!empty($params)):
			$api->params = $params;
		endif;
		
		return $api;
	}
	
	public static function setupStorageEngine($type) {
	
		if (!class_exists('owa_db')) {
			require_once(OWA_BASE_CLASSES_DIR.'owa_db.php');
		}
		
		if ($type) {
			
		$connection_class = "owa_db_" . $type;
		
			if (!class_exists($connection_class)) {
				$connection_class_path = OWA_PLUGINS_DIR.'/db/' . $connection_class . ".php";
		
			 	if (!require_once($connection_class_path)) {
			 		owa_coreAPI::error(sprintf('Cannot locate proper db class at %s.', $connection_class_path));
			 		return false;
				}
			}
		
		}
		
	 	return true;

	}
	
	public static function &dbSingleton() {
		
		static $db;
	
		if (!isset($db)) {
			
			$db_type = owa_coreAPI::getSetting('base', 'db_type');
			$ret = owa_coreAPI::setupStorageEngine($db_type);
	
		 	if (!$ret) {
		 		owa_coreAPI::error(sprintf('Cannot locate proper db class at %s. Exiting.', $connection_class_path));
		 		return;
			} else { 	
				$connection_class = 'owa_db_'.$db_type;
				$db = new $connection_class(
					owa_coreAPI::getSetting('base','db_host'), 
					owa_coreAPI::getSetting('base','db_name'),
					owa_coreAPI::getSetting('base','db_user'),
					owa_coreAPI::getSetting('base','db_password'),
					owa_coreAPI::getSetting('base','db_force_new_connections'),
					owa_coreAPI::getSetting('base','db_make_persistant_connections')
				);	
			}
		}
		
		return $db;
	}
		
	public static function &configSingleton($params = array()) {
		
		static $config;
		
		if(!isset($config)):
			
			if (!class_exists('owa_settings')):
				require_once(OWA_BASE_CLASS_DIR.'settings.php');
			endif;
			
			$config = owa_coreAPI::supportClassFactory('base', 'settings');
			
		endif;
		
		return $config;
	}
	
	public static function &errorSingleton() {
		
		static $e;
		
		if(!$e) {
			
			if (!class_exists('owa_error')):
				require_once(OWA_BASE_CLASS_DIR.'error.php');
			endif;
			
			$e = owa_coreAPI::supportClassFactory('base', 'error');
			
		}
		
		return $e;
	}
	
	public static function getSetting($module, $name) {
		
		$s = &owa_coreAPI::configSingleton();
		return $s->get($module, $name);
	}
	
	public static function setSetting($module, $name, $value, $persist = false) {
		
		$s = &owa_coreAPI::configSingleton();
		
		if ($persist === true) {
			$s->persistSetting($module, $name, $value);
		} else {
			$s->setSetting($module, $name, $value);
		}
		
	}
	
	public static function persistSetting($module, $name, $value) {
		
		$s = &owa_coreAPI::configSingleton();
		$s->persistSetting($module, $name, $value);
		
	}
	
	public static function getSiteSetting($site_id, $name) {
		
		$site = owa_coreAPI::entityFactory('base.site');
		$site->load( $site->generateId( $site_id ) );
		if ( $site->wasPersisted() ) {
		
			$settings = $site->get('settings');
			if (!empty($settings)) {
				if ( array_key_exists($name, $settings) ) {
					return $settings[$name];
				}
			}			
		}
	}
	
	public static function persistSiteSetting($site_id, $name, $value) {
		
		$site = owa_coreAPI::entityFactory('base.site');
		$site->load( $site->generateId( $site_id ) );
		if ( $site->wasPersisted() ) {
			$settings = $site->get('settings');
			if ( ! $settings ) {
				$settings = array();
			}
			$settings[$name] = $value;
			$site->set('settings', $settings);	
			$site->update();
		}
	}
	
	public static function getSiteSettings($site_id) {
		
		$site = owa_coreAPI::entityFactory('base.site');
		$site->load( $site->generateId( $site_id ) );
		if ( $site->wasPersisted() ) {
		
			$settings = $site->get('settings');
			
			if ( $settings ) {
				return $settings;
			} else {
				return array();
			}
		}
		
	}
	
	public static function getAllRoles() {
		
		$caps = owa_coreAPI::getSetting('base', 'capabilities');
		return array_keys($caps);
	}
	
	public static function &getCurrentUser() {
		
		$s = &owa_coreAPI::serviceSingleton();
		return $s->getCurrentUser();
	}
	
	/**
	 * check to see if the current user has a capability
	 * always returns a bool
	 */
	public static function isCurrentUserCapable($capability) {
		
		$cu = &owa_coreAPI::getCurrentUser();
		owa_coreAPI::debug("Current User Role: ".$cu->getRole());
		owa_coreAPI::debug("Current User Authentication: ".$cu->isAuthenticated());
		$ret = $cu->isCapable($capability);
		owa_coreAPI::debug("Is current User capable: ".$ret);
		return $ret;
	}
	
	public static function isCurrentUserAuthenticated() {
		
		$cu = &owa_coreAPI::getCurrentUser();
		return $cu->isAuthenticated();
	}
	
	public static function &serviceSingleton() {
		
		static $s;
		
		if(empty($s)) {
			
			if (!class_exists('owa_service')) {
				require_once(OWA_BASE_CLASS_DIR.'service.php');
			}
			
			$s = owa_coreAPI::supportClassFactory('base', 'service');
			
		}
		
		return $s;
	}
	
	public static function &cacheSingleton($params = array()) {
		
		static $cache;
		
		if ( !isset ( $cache ) ) {
			$cache_type = owa_coreAPI::getSetting('base', 'cacheType');
			
			switch ($cache_type) {
				
				case "memcached":
					$implementation = array('owa_memcachedCache', OWA_BASE_CLASS_DIR.'memcachedCache.php');
					break;
				default:
					$implementation = array('owa_fileCache', OWA_BASE_CLASS_DIR.'fileCache.php');
					
			}
			
			if ( ! class_exists( $implementation[0] ) ) {
				require_once( $implementation[1] );
			}
			// make this plugable
			$cache = new $implementation[0];		
		}
		
		return $cache;
	}
	
	public static function requestContainerSingleton() {
	
		static $request;
		
		if(!isset($request)):
			
			if (!class_exists('owa_requestContainer')):
				require_once(OWA_DIR.'owa_requestContainer.php');
			endif;
			
			$request = owa_lib::factory(OWA_DIR, '', 'owa_requestContainer');
			
		endif;
		
		return $request;
	
	}
		
	public static function moduleRequireOnce($module, $class_dir, $file) {
		
		if (!empty($class_dir)) {
		
			$class_dir .= DIRECTORY_SEPARATOR;
			
		}
		
		$full_file_path = OWA_BASE_DIR.'/modules/'.$module.DIRECTORY_SEPARATOR.$class_dir.$file.'.php';
		
		if (file_exists($full_file_path)) {
			return require_once($full_file_path);
		} else {
			owa_coreAPI::debug("moduleRequireOnce says no file found at: $full_file_path");
			return false;
		}
	}
	
	public static function moduleFactory($modulefile, $class_suffix = null, $params = '', $class_ns = 'owa_') {
		
		list($module, $file) = explode(".", $modulefile);
		$class = $class_ns.$file.$class_suffix;
		//print $class;
		// Require class file if class does not already exist
		if(!class_exists($class)):	
			owa_coreAPI::moduleRequireOnce($module, '', $file);
		endif;
			
		$obj = owa_lib::factory(OWA_BASE_DIR.'/modules/'.$module, '', $class, $params);
		
		//if (isset($obj->module)):
			$obj->module = $module;
		//endif;
		
		return $obj;
	}
	
	public static function moduleGenericFactory($module, $sub_directory, $file, $class_suffix = null, $params = '', $class_ns = 'owa_') {
		
		$class = $class_ns.$file.$class_suffix;
	
		// Require class file if class does not already exist
		if(!class_exists($class)):	
			owa_coreAPI::moduleRequireOnce($module, $sub_directory, $file);
		endif;
			
		$obj = owa_lib::factory(OWA_DIR.'modules'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.$sub_directory, '', $class, $params);
		
		return $obj;
	}
	
	/**
	 * Produces Module Classes (module.php)
	 *  
	 * @return Object module class object
	 */
	public static function moduleClassFactory($module) {
		
		if (!class_exists('owa_module')):
			require_once(OWA_BASE_CLASSES_DIR.'owa_module.php');
		endif;
			
		require_once(OWA_BASE_DIR.'/modules/'.$module.'/module.php');
			
		return owa_lib::factory(OWA_BASE_CLASSES_DIR.$module, 'owa_', $module.'Module');
		
	}

	
	public static function updateFactory($module, $filename, $class_ns = 'owa_') {
	
		require_once(OWA_BASE_CLASS_DIR.'update.php');
		
		//$obj = owa_coreAPI::moduleGenericFactory($module, 'updates', $filename, '_update');
		$class = $class_ns.$module.'_'.$filename.'_update';
	
		// Require class file if class does not already exist
		if(!class_exists($class)):	
			owa_coreAPI::moduleRequireOnce($module, 'updates', $filename);
		endif;
			
		$obj = owa_lib::factory(OWA_DIR.'modules'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'updates', '', $class);

		$obj->module_name = $module;
		if (!$obj->schema_version) {
			$obj->schema_version = $filename;
		}
		return $obj;
	}
		
	public static function subViewFactory($subview, $params = array()) {
		
		list($module, $class) = explode(".", $subview);
		//print_r($module.' ' . $class);
		//owa_lib::moduleRequireOnce($module, $class);
	
		$subview =  owa_lib::moduleFactory($subview, 'View', $params);
		$subview->is_subview = true;
		
		return $subview;
	}
	
	public static function &supportClassFactory($module, $class, $params = array(),$class_ns = 'owa_') {
		
		$obj = &owa_lib::factory(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR, $class_ns, $class, $params);
		$obj->module = $module;
		
		return $obj;
		
		
	}
	
	/**
	 * Convienence method for generating entities
	 *
	 * @param unknown_type $entity_name
	 * @return unknown
	 */
	public static function entityFactory($entity_name) {
		
		/* SETUP STORAGE ENGINE */
		
		// Must be called before any entities are created
		
		if (!defined('OWA_DTD_INT')) {
			if (defined('OWA_DB_TYPE')) {
				owa_coreAPI::setupStorageEngine(OWA_DB_TYPE);
			} else {
				owa_coreAPI::setupStorageEngine('mysql');
			}
				
		}
		
		
			
		if (!class_exists('owa_entity')):
			require_once(OWA_BASE_CLASSES_DIR.'owa_entity.php');	
		endif;
			
		$entity = owa_coreAPI::moduleSpecificFactory($entity_name, 'entities', '', '', false);
		$entity->name = $entity_name;
		return $entity;
		//return owa_coreAPI::supportClassFactory('base', 'entityManager', $entity_name);
		
	}
	
	/**
	 * Convienence method for generating entities
	 *
	 * @param unknown_type $entity_name
	 * @return unknown
	 * @depricated
	 * @todo REMOVE
	 */
	public static function rawEntityFactory($entity_name) {
			
		return owa_coreAPI::entityFactory($entity_name);
				
	}
	
	/**
	 * Factory for generating module specific classes
	 *
	 * @param string $modulefile
	 * @param string $class_dir
	 * @param string $class_suffix
	 * @param array $params
	 * @return unknown
	 */
	public static function moduleSpecificFactory($modulefile, $class_dir, $class_suffix = null, $params = '', $add_module_name = true, $class_ns = 'owa_') {
		
		list($module, $file) = explode(".", $modulefile);
		$class = $class_ns.$file.$class_suffix;
		
		// Require class file if class does not already exist
		if(!class_exists($class)):	
			owa_coreAPI::moduleRequireOnce($module, $class_dir, $file);
		endif;
			
		$obj = owa_lib::factory(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$class_dir.DIRECTORY_SEPARATOR.$module, '', $class, $params);
		
		if ($add_module_name == true):
			$obj->module = $module;
		endif;
		
		return $obj;
		
		
	}
	
	public static function executeApiCommand($map) {
		
		if (!array_key_exists('do', $map)) {
			echo ("API Command missing from request.");
			owa_coreAPI::debug('API Command missing from request. Aborting.');
			exit;
		} else {
			// load service
			$s = owa_coreAPI::serviceSingleton();
			// lookup method class
			$do = $s->getApiMethodClass($map['do']);
				
		}
		
		// if exists, pass to OWA as a request
		if ($do) {
				
			if (array_key_exists('args', $do)) {
				
				$passed_args = array();
				
				foreach ($do['args'] as $arg) {
					
					if (isset($map[$arg])) {
						$passed_args[] = $map[$arg];
					} else {
						$passed_args[] = '';
					}
				}
				
				if (!empty($do['file'])) {
					
					if (!class_exists($do['callback'][0])) {
						require_once($file);
					}
				}
				
				$something = call_user_func_array($do['callback'], $passed_args);
			}	
			
			return $something;
		} else {
			echo "No API Method Found.";
		}

	}
	
	/**
	 * Convienence method for generating metrics
	 *
	 * @param unknown_type $entity_name
	 * @return unknown
	 */
	public static function metricFactory($metric_name, $params = array()) {
		
		if (!strpos($metric_name, '.')) {
			$s = owa_coreAPI::serviceSingleton();
			$metric_name = $s->getMetricClasses($metric_name);
		}
		
		if (!class_exists('owa_metric')) {
			require_once(OWA_BASE_CLASSES_DIR.'owa_metric.php');	
		}
		
		return owa_coreAPI::moduleSpecificFactory($metric_name, 'metrics', '', $params, false);
	}
	
	/**
	 * Returns a consolidated list of admin/options panels from all active modules 
	 *
	 * @return array
	 */
	public static function getAdminPanels() {
		
		$panels = array();
		
		$service = owa_coreAPI::serviceSingleton();
		
		foreach ($service->modules as $k => $v) {
			$v->registerAdminPanels();
			$module_panels = $v->getAdminPanels();
			if ($module_panels) {
				foreach ($module_panels as $key => $value) {
					
					$panels[$value['group']][] = $value;
				}
			}			
		}
		
		return $panels;
	}
	
	/**
	 * Returns a consolidated list of nav links from all active modules for a particular view
	 * and named navigation element.
	 *
	 * @param string nav_name the name of the navigation element that you want links for
	 * @param string sortby the array value to sort the navigation array by
	 * @return array
	 */
	public static function getNavigation($view, $nav_name, $sortby ='order') {
		
		$links = array();
		
		$service = owa_coreAPI::serviceSingleton();
		
		foreach ($service->modules as $k => $v) {
			
			// If the module does not have nav links, register them. needed in case this function is called twice on
			// same view.
			if (empty($v->nav_links)):
				$v->registerNavigation();
			endif;		
			
			$module_nav = $v->getNavigationLinks();
			
	
			if (!empty($module_nav)) {
				// assemble the navigation for a specific view's named navigation element'	
				foreach ($module_nav as $key => $value) {
					
					$links[$value['view']][$value['nav_name']][] = $value;
				}
			}
			
		}
		
		//print_r($links[$view][$nav_name]);
		if (!empty($links[$view][$nav_name])):
			// anonymous sorting function, takes sort by variable.
			$code = "return strnatcmp(\$a['$sortby'], \$b['$sortby']);";
	   		
	   		// sort the array
	   		$ret = usort($links[$view][$nav_name], create_function('$a,$b', $code));
			
			return $links[$view][$nav_name];
		else: 
			return false;
		endif;
		 
	}
	
	public static function getGroupNavigation($group, $sortby ='order') {
	
		$links = array();
		
		$service = owa_coreAPI::serviceSingleton();
		
		foreach ($service->modules as $k => $v) {
			
			// If the module does not have nav links, register them. needed in case this function is called twice on
			// same view.
			if (empty($v->nav_links)):
				$v->registerNavigation();
			endif;		
			
			$module_nav = $v->getNavigationLinks();
			
			if (!empty($module_nav)):
				//loop through returned nav array
				foreach ($module_nav as $group => $nav_links) {
					
					foreach ($nav_links as $link) {	
									
						if (array_key_exists($group, $links)):
							
							// check to see if link is already present in the main array
							if (array_key_exists($link['anchortext'], $links[$group])):
								// merge various elements?? not now.
								//check to see if there is an existing subgroup
								
								if (array_key_exists('subgroup', $links[$group][$link['anchortext']])):
									// if so, merge the subgroups
									$links[$group][$link['anchortext']]['subgroup'] = array_merge($links[$group][$link['anchortext']]['subgroup'], $link['subgroup']);
								endif;	
							else:
								// else populate the link
								$links[$group][$link['anchortext']] = $link;	
							endif;
							
						else:
							$links[$group][$link['anchortext']] = $link;
						endif;
					}					
					
				}
			endif;
			
		}
		
		return $links[$group];	
	}
	
	/**
	 * @Todo REMOVE
	 */
	public static function getNavSort($a, $b) {
		
		return strnatcmp($a['order'], $b['order']);
	}
	
		
	public static function getActiveModules() {
	
		$c = owa_coreAPI::configSingleton();
		$config = $c->config->get('settings');
		
		//print_r($config);
		$active_modules = array();
		
		foreach ($config as $k => $module) {
			
			if ($module['is_active'] == true):
				$active_modules[] = $k;
			endif;
		}

		return $active_modules;
	
	}
	
	public static function getModulesNeedingUpdates() {
	
		$service = owa_coreAPI::serviceSingleton();
		
		return $service->getModulesNeedingUpdates();
	}
	
	/**
	 * Invokes controller to perform controller
	 *
	 * @param $action string
	 * 
	 */
	public static function performAction($action, $params = array()) {
		
		// Load 
		$controller = owa_coreAPI::moduleFactory($action, 'Controller', $params);
		
		if (!$controller || !method_exists($controller, 'doAction')) {
			owa_coreAPI::debug("No controller is associated with $action.");
			return;
		}
		
		$data = $controller->doAction();
						
		// Display view if controller calls for one.
		if (!empty($data['view']) || !empty($data['action'])):
		
			// 
			if ($data['view_method'] == 'delegate'):
				return owa_coreAPI::displayView($data);
			
			// Redirect to a view	
			elseif ($data['view_method'] == 'redirect'):
				owa_lib::redirectToView($data);
				return;
				
			// return an image . Will output headers and binary data.
			elseif ($data['view_method'] == 'image'):
				return owa_coreAPI::displayImage($data);
			
			else:
				return owa_coreAPI::displayView($data);
				
			endif;
		
		elseif(!empty($data['do'])):
			//print_r($data);
			owa_lib::redirectToView($data);
			return;
			
		endif;
	}
	
	/**
	 * Logs an event to the event queue
	 *
	 * take an owa_event object as a message.
	 *
	 * @param string $event_type
	 * @param object $message
	 * @return boolean
	 */
	public static function logEvent($event_type, $message = '') {
		
		// debug
		owa_coreAPI::debug("logging event $event_type");
		
		if (owa_coreAPI::getSetting('base', 'error_log_level') > 9) {
			owa_coreAPI::debug("PHP Server Global: ".print_r($_SERVER, true));
		}
			
		// Check to see if named users should be logged		
		if (owa_coreAPI::getSetting('base', 'log_named_users') != true) {
			$cu = owa_coreAPI::getCurrentUser();	
			$cu_user_id = $cu->getUserData('user_id');
			
			if(!empty($cu_user_id)) {
				return false;
			}
		}
		
		// do not log if the request is robotic
		$service = &owa_coreAPI::serviceSingleton();
		$bcap = $service->getBrowscap();
		owa_coreAPI::profile(__CLASS__, __FUNCTION__, __LINE__);
		if (!owa_coreAPI::getSetting('base', 'log_robots')) {
			
			if ($bcap->robotCheck()) {
				owa_coreAPI::debug("ABORTING: request appears to be from a robot");
				owa_coreAPI::setRequestParam('is_robot', true);
				return;
			}
			owa_coreAPI::profile(__CLASS__, __FUNCTION__, __LINE__);
		}
		
		$service->setBrowscap($bcap);
		
		// form event if one was not passed
		$class= 'owa_event';
		if (!($message instanceof $class)) {
			$event = owa_coreAPI::supportClassFactory('base', 'event');
			$event->setProperties($message);
			$event->setEventType($event_type);
		} else {
			$event = $message;
		}
								
		// Filter XSS exploits from event properties
		$event->cleanProperties();
		
		// do not log if the do not log property is set on the event.
		if ($event->get('do_not_log')) {
			return false;
		}
		
		// lookup which event processor to use to process this event type
		$processor_action = owa_coreAPI::getEventProcessor($event->getEventType());
		
		return owa_coreAPI::handleRequest(array('event' => $event), $processor_action);
	}

	
	public static function displayImage($data) {
		
		header('Content-type: image/gif');
		header('P3P: CP="'.owa_coreAPI::getSetting('base', 'p3p_policy').'"');
		header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
		
		echo owa_coreAPI::displayView($data);		
	}
	
	
	/**
	 * Displays a View without user authentication. Takes array of data as input
	 *
	 * @param array $data
	 * @param string $viewfile a specific view file to use
	 * @return string
	 * 
	 */
	public static function displayView($data, $viewfile = '') {
		
		if (empty($viewfile)):
			$viewfile = $data['view'];
		endif;
		
		$view = owa_coreAPI::moduleFactory($viewfile, 'View');
		$view->setData($data);
		return $view->assembleView($data);
		
	}
	
	public static function displaySubView($data, $viewfile = '') {
		
		if (empty($viewfile)):
			$viewfile = $data['view'];
		endif;
		
		$view =  owa_coreAPI::subViewFactory($viewfile);
		
		return $view->assembleView($data);
		
	}
	
	/**
	 * Strip a URL of certain GET params
	 * @depricated
	 * @return string
	 * @todo REMOVE
	 */
	function stripDocumentUrl($url) {
		
		if (owa_coreAPI::getSetting('base', 'clean_query_string')):
		
			if (owa_coreAPI::getSetting('base', 'query_string_filters')):
				$filters = str_replace(' ', '', owa_coreAPI::getSetting('base', 'query_string_filters'));
				$filters = explode(',', $filters);
			else:
				$filters = array();
			endif;
			
			// OWA specific params to filter
			array_push($filters, owa_coreAPI::getSetting('base', 'source_param'));
			array_push($filters, owa_coreAPI::getSetting('base', 'ns').owa_coreAPI::getSetting('base', 'feed_subscription_param'));
			
			//print_r($filters);
			
			foreach ($filters as $filter => $value) {
				
	          $url = preg_replace(
	            '#\?' .
	            $value .
	            '=.*$|&' .
	            $value .
	            '=.*$|' .
	            $value .
	            '=.*&#msiU',
	            '',
	            $url
	          );
	          
	        }
		
	    endif;
     	//print $url;
     	
     	return $url;
		
	}
	
	public static function getRequestParam($name) {
		
		$service = &owa_coreAPI::serviceSingleton();
		return $service->request->getParam($name);
		
	}
	
	public static function getRequest() {
		$service = &owa_coreAPI::serviceSingleton();
		return $service->request;
	}
	
	public static function setRequestParam($name, $value) {
		
		$service = &owa_coreAPI::serviceSingleton();
		return $service->request->setParam($name, $value);
		
	}
	
	public static function makeTimePeriod($time_period, $params = array()) {
		
		$period = owa_coreAPI::supportClassFactory('base', 'timePeriod');
		$map = array();
		
		if (array_key_exists('startDate', $params)) {
			$map['startDate'] = $params['startDate'];			
		}
		
		if (array_key_exists('endDate', $params)) {
			$map['endDate'] = $params['endDate'];
		}
		
		if (array_key_exists('startTime', $params)) {
			$map['startTime'] = $params['startTime'];			
		}
		
		if (array_key_exists('endTime', $params)) {
			$map['endTime'] = $params['endTime'];
		}
		
		$period->set($time_period, $map);
		
		return $period;
	}

	/**
	 * Factory method for producing validation objects
	 * 
	 * @return Object
	 */
	public static function validationFactory($class_file) {
		
		if (!class_exists('owa_validation')):
			require_once(OWA_BASE_CLASS_DIR.'validation.php');
		endif;
		
		return owa_lib::factory(OWA_PLUGINS_DIR.'/validations', 'owa_', $class_file, array(), 'Validation');
		
	}
	
	public static function debug($msg) {
		
		$e = owa_coreAPI::errorSingleton();
		$e->debug($msg);
		return;
	}
	
	public static function error($msg) {
		
		$e = owa_coreAPI::errorSingleton();
		$e->err($msg);
		return;
	}
	
	public static function notice($msg) {
		
		$e = owa_coreAPI::errorSingleton();
		$e->notice($msg);
		return;
	}
	
	public static function createCookie($cookie_name, $cookie_value, $expires = 0, $path = '/', $domain = '') {
		
		if ( $domain ) {
			// sanitizes the domain
			$domain = owa_lib::sanitizeCookieDomain( $domain );
		} else {
			$domain = owa_coreAPI::getSetting('base', 'cookie_domain');
		}	
		if (is_array($cookie_value)) {
			
			$cookie_value = owa_lib::implode_assoc('=>', '|||', $cookie_value);
		}
		
		// add namespace
		$cookie_name = sprintf('%s%s', owa_coreAPI::getSetting('base', 'ns'), $cookie_name);
		
		// debug
		owa_coreAPI::debug(sprintf('Setting cookie %s with values: %s under domain: %s', $cookie_name, $cookie_value, $domain));
		
		// set compact privacy header
		header(sprintf('P3P: CP="%s"', owa_coreAPI::getSetting('base', 'p3p_policy')));
		//owa_coreAPI::debug('time: '.$expires);
		setcookie($cookie_name, $cookie_value, $expires, $path, $domain);
		return;
	}
	
	public static function deleteCookie($cookie_name, $path = '/', $domain = '') {
	
		return owa_coreAPI::createCookie($cookie_name, false, time()-3600*25, $path, $domain);
	}
	
	public static function setState($store, $name = '', $value, $store_type = '', $is_perminent = '') {
		
		$service = &owa_coreAPI::serviceSingleton();
		return $service->request->state->set($store, $name, $value, $store_type, $is_perminent);
	}
	
	public static function getStateParam($store, $name = '') {
		
		$service = &owa_coreAPI::serviceSingleton();
		return $service->request->state->get($store, $name);	
	}
	
	public static function getServerParam($name = '') {
		
		$service = &owa_coreAPI::serviceSingleton();
		return $service->request->getServerParam($name);	
	}
	
	public static function clearState($store) {
		
		$service = &owa_coreAPI::serviceSingleton();
		$service->request->state->clear($store); 
				
	}
	
	public static function getEventProcessor($event_type) {
		
		$service = &owa_coreAPI::serviceSingleton();
		$processor = $service->getMapValue('event_processors', $event_type);
		
		if (empty($processor)) {
		
			$processor = 'base.processEvent';
		}
		
		return $processor;
	}
	
	/**
	 * Handles OWA internal page/action requests
	 *
	 * @return unknown
	 */
	public static function handleRequest($caller_params = null, $action = '') {
		
		static $init;
		
		$service = &owa_coreAPI::serviceSingleton();
		// Override request parsms with those passed by caller
		if (!empty($caller_params)) {
			$service->request->mergeParams($caller_params);
		};
		
		$params = $service->request->getAllOwaParams();
		
		if ($init != true) {
			owa_coreAPI::debug('Handling request with params: '. print_r($params, true));
		}
		
		// backwards compatability with old style view/controler scheme
		// still needed??
		if (array_key_exists('view', $params)) {
			// its a view request so the only data is in whats in the params
			$init = true;
			return owa_coreAPI::displayView($params);
		} 
	
		if (empty($action)) {
			$action = owa_coreAPI::getRequestParam('action');
			if (empty($action)) {
				$action = owa_coreAPI::getRequestParam('do');
				
				if (empty($action)) {
					$action = owa_coreAPI::getSetting('base', 'start_page');
				}	
			}
		}
		
		$init = true;
		owa_coreAPI::debug('About to perform action: '.$action);
		return owa_coreAPI::performAction($action, $params);
						
	}
	
	public static function isUpdateRequired() {
		
		$service = &owa_coreAPI::serviceSingleton();
		return $service->isUpdateRequired();
	}
	
	public static function getSitesList() {
		
		//$s = owa_coreAPI::entityFactory('base.site');
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_site');
		$db->selectColumn('*');
		return $db->getAllRows();
		
	}
	
	public static function profile($that = '', $function = '', $line = '', $msg = '') {
	
		if (defined('OWA_PROFILER')) {
			if (OWA_PROFILER === true) {
			
				static $profiler;
						
				if (!class_exists('PhpQuickProfiler')) {
					require_once(OWA_INCLUDE_DIR.'pqp/classes/PhpQuickProfiler.php');
				}
				
				if (empty($profiler)) {
					$profiler = new PhpQuickProfiler(PhpQuickProfiler::getMicroTime(), OWA_INCLUDE_DIR.'pqp/');
				}
				
				$class = get_class($that);
				Console::logSpeed($class."::$function - Line: $line - Msg: $msg");
				Console::logMemory($that, $class. "::$function - Line: $line");
				
				return $profiler;
			}
		}
	}
	
	public static function profileDisplay() {
		$p = owa_coreAPI::profile();
		if ($p) {
			$p->display();
		}
		
	}
	
	public static function getEventDispatch() {
		
		if (!class_exists('eventQueue')) {
			require_once(OWA_DIR.'/eventQueue.php');
		}

		$eq = &eventQueue::get_instance();
		return $eq;
	}
	
	public static function getCliCommandClass($command) {
		
		$s = owa_coreAPI::serviceSingleton();
		return $s->getCliCommandClass($command);
	}
	
	public static function getGeolocationFromIpAddress($ip_address) {
		
		$s = &owa_coreAPI::serviceSingleton();
		$s->geolocation->getGeolocationFromIp($ip_address);
		return $s->geolocation;
	}
	
	public static function getNonceTimeInterval() {
		
		return  ceil( time() / owa_coreAPI::getSetting( 'base', 'nonce_expiration_period') );
	}
	
	public static function createNonce($action) {
		
		$time = owa_coreAPI::getNonceTimeInterval();
		$cu = owa_coreAPI::getCurrentUser();
		$user_id = $cu->getUserData( 'user_id' );
		$full_nonce = $time . $action . $user_id . 'owa_nonce';
		$nonce = substr(md5($full_nonce), -12, 10);
		
		return $nonce;
	}
	
	public static function summarize($map) {
		
		$entity = owa_coreAPI::entityFactory($map['entity']);
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom($entity->getTableName(), $entity->getTableAlias());
		
		foreach ($map['columns'] as $col => $action) {
			
			switch ($action) {
				
				case 'sum':
					$col_def = sprintf("SUM(%s)", $col);
					$name = $col.'_sum';
					break;
				case 'count':
					$col_def = sprintf("COUNT(%s)", $col);
					$name = $col.'_count';
					break;
				case 'count_distinct':
					$col_def = sprintf("COUNT(distinct %s)", $col);
					$name = $col.'_dcount';
					break;
			}
			
			$db->selectColumn($col_def, $name);
		}
		
		foreach ($map['constraints'] as $con_col => $con_value) {
			
			if ( is_array( $con_value ) ) {
				$db->where($con_col, $con_value['value'], $con_value['operator']);
			} else {
				$db->where($con_col, $con_value);
			}
		}
		
		$ret = $db->getOneRow();
		return $ret;
	}
	
	public static function getJsTrackerTag( $site_id, $options = array() ) {
		
		if ( ! class_exists( 'owa_template' ) ) {
			require_once(OWA_BASE_CLASSES_DIR.'owa_template.php');
		}
		
		$t = new owa_template();
		
		// check to see if first hit tag is needed
		if (owa_coreAPI::getSetting('base', 'delay_first_hit')) {
		
			$service = &owa_coreAPI::serviceSingleton();
			//check for persistant cookie
			$v = $service->request->getOwaCookie('v');
			
			if (empty($v)) {
				
				$options['first_hit_tag'] = true;
			}		
		}
		
		//check to see if we shuld log clicks.
		if ( ! owa_coreAPI::getSetting( 'base', 'log_dom_clicks' ) ) {
			$options['do_not_log_clicks'] = true;
		}

		if ( ! owa_coreAPI::getSetting( 'base', 'log_dom_streams' ) ) {
			$options['do_not_log_domstream'] = true;
		}
		
		if (owa_coreAPI::getSetting('base', 'is_embedded')) {
			
			// needed to override the endpoint used by the js tracker
			$options['apiEndpoint'] = owa_coreAPI::getSetting('base', 'api_url');
		}
				
		$t->set( 'site_id', $site_id );
		$t->set( 'options', $options);
		
		$t->set_template('js_helper_tags.tpl');
		return $t->fetch();
	}
	
	public static function activateModule( $module_name ) {
		
		if ( $module_name ) {
		
			$m = owa_coreAPI::moduleClassFactory($module_name);
			return $m->activate();
		}
	}
	
	public static function deactivateModule( $module_name ) {
		
		if ( $module_name ) {
		
			$s = owa_coreAPI::serviceSingleton();
			$m = $s->getModule($module_name);
			return $m->deactivate();
		}
	}
	
	public static function installModule( $module_name ) {
		
		if ($module_name) {
		
			$m = owa_coreAPI::moduleClassFactory($module_name);
			$status = $m->install();
			return $status;
		}
	}
	
	public static function generateInstanceSpecificHash() {
		
		if ( defined( 'OWA_SECRET' ) ) {
			$salt = OWA_SECRET;
		} else {
			$salt = '';
		}
		
		if ( defined( 'OWA_DB_USER' ) ) { 
			$salt .= OWA_DB_USER; 
		} 
		
		if ( defined( 'OWA_DB_PASSWORD' ) ) { 
			$salt .= OWA_DB_PASSWORD; 
		}	                 
		
		return md5( $salt ); 
	}
}

?>