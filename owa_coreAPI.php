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

require_once(OWA_BASE_DIR.'/owa_base.php');
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

class owa_coreAPI extends owa_base {
	
	var $modules;
	
	var $admin_panels;
	
	var $init;
	
	/**
	 * Container for request params
	 * 
	 * @var array
	 */
	var $params;
	
	/**
	 * Container for caller config overrides.
	 * 
	 * @var array
	 */
	var $caller_config_overrides;
	
	
	function owa_coreAPI() {
		
		$this->owa_base();
		
		return;
	}
	
	function &singleton($params = array()) {
		
		static $api;
		
		if(!isset($api)):
			$api = new owa_coreAPI();
		endif;
		
		if(!empty($params)):
			$api->params = $params;
		endif;
		
		return $api;
	}
	
	function &dbSingleton() {
		
		static $db;
	
		if (!isset($db)):
			
			//$c = &owa_coreAPI::configSingleton();
			//$config = $c->fetch('base');
			//$e = &owa_error::get_instance();
			
			if (!class_exists('owa_db')):
				require_once(OWA_BASE_CLASSES_DIR.'owa_db.php');
			endif;
			
			$connection_class = "owa_db_" . OWA_DB_TYPE;
			$connection_class_path = OWA_PLUGINS_DIR.'/db/' . $connection_class . ".php";
	
	 		if (!include($connection_class_path)):
	 			$e->emerg(sprintf('Cannot locate proper db class at %s. Exiting.',
	 							$connection_class_path));
	 			return;
			else:  	
				$db = new $connection_class;
				
				//$this->e->debug(sprintf('Using db class at %s.',	$connection_class_path));
			endif;	
			
		endif;
		
		return $db;
		
	}
	
	function &configSingleton($params = array()) {
		
		static $config;
		
		if(!isset($config)):
			
			if (!class_exists('owa_settings')):
				require_once(OWA_BASE_CLASS_DIR.'settings.php');
			endif;
			
			$config = owa_coreAPI::supportClassFactory('base', 'settings');
			
		endif;
		
		return $config;
	}
	
	
	function setupFramework() {
		
		if ($this->init != true):
			$this->_loadModules();
			$this->_loadEntities();
			$this->init = true;
		endif;
		
		return;
	}
	
	function _loadModules() {
		
		foreach ($this->config['modules'] as $k => $module) {
			
			$m = owa_coreAPI::moduleClassFactory($module);
			$this->modules[$m->name] = $m;
		}
		
		return;
	}
	
	/**
	 * Produces Module Classes (module.php)
	 *  
	 * @return Object module class object
	 */
	function moduleClassFactory($module) {
		
		if (!class_exists('owa_module')):
			require_once(OWA_BASE_CLASSES_DIR.'owa_module.php');
		endif;
			
		require_once(OWA_BASE_DIR.'/modules/'.$module.'/module.php');
			
		return owa_lib::factory(OWA_BASE_CLASSES_DIR.$module, 'owa_', $module.'Module');
		
	}
	
	function _loadEntities() {
		
		foreach ($this->modules as $k => $module) {
			
			foreach ($module->entities as $entitiy_k => $entitiy_v) {
			
				$this->entities[] = $module->name.$entitiy_v;
				
			}
		}
		
		return;
	}
	
	
	function displayImage($data) {
		
		header('Content-type: image/gif');
		header('P3P: CP="'.$this->config['p3p_policy'].'"');
		header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-store, no-cache, must-revalidate');
		header('Cache-Control: post-check=0, pre-check=0', false);
		header('Pragma: no-cache');
		
		print owa_coreAPI::displayView($data);
		
		return;
		
	}
	
	
	/**
	 * Displays a View without user authentication. Takes array of data as input
	 *
	 * @param array $data
	 * @param string $viewfile a specific view file to use
	 * @return string
	 * 
	 */
	function displayView($data, $viewfile = '') {
		
		if (empty($viewfile)):
			$viewfile = $data['view'];
		endif;
		
		$view =  owa_coreAPI::moduleFactory($viewfile, 'View', $params);
		
		return $view->assembleView($data);
		
	}
	
	function displaySubView($data, $viewfile = '') {
		
		if (empty($viewfile)):
			$viewfile = $data['view'];
		endif;
		
		$view =  owa_coreAPI::subViewFactory($viewfile);
		
		return $view->assembleView($data);
		
	}
	
	function moduleRequireOnce($module, $class_dir, $file) {
		
		if (!empty($class_dir)):
		
			$class_dir .= DIRECTORY_SEPARATOR;
			
		endif;
		
		return require_once(OWA_BASE_DIR.'/modules/'.$module.DIRECTORY_SEPARATOR.$class_dir.$file.'.php');
	}
	
	function moduleFactory($modulefile, $class_suffix = null, $params = '') {
		
		list($module, $file) = split("\.", $modulefile);
		$class = 'owa_'.$file.$class_suffix;
	
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
	
	function subViewFactory($subview, $params = array()) {
		
		list($module, $class) = split("\.", $subview);
		
		owa_lib::moduleRequireOnce($module, $class);
	
		$subview =  owa_lib::moduleFactory($module, $class.'View', $params);
		$subview->is_subview = true;
		
		return $subview;
	}
	
	function supportClassFactory($module, $class, $params = array()) {
			
		$obj = owa_lib::factory(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$module.DIRECTORY_SEPARATOR.'classes'.DIRECTORY_SEPARATOR, 'owa_', $class, $params);
		$obj->module = $module;
		
		return $obj;
		
		
	}
	
	/**
	 * Convienence method for generating entities
	 *
	 * @param unknown_type $entity_name
	 * @return unknown
	 */
	function entityFactory($entity_name) {
			
		if (!class_exists('owa_entity')):
			require_once(OWA_BASE_CLASSES_DIR.'owa_entity.php');	
		endif;
			
		return owa_coreAPI::moduleSpecificFactory($entity_name, 'entities', '', '', false);
		
	}
	
	/**
	 * Convienence method for generating metrics
	 *
	 * @param unknown_type $entity_name
	 * @return unknown
	 */
	function getMetric($metric_name, $params) {
		
		if (!class_exists('owa_metric')):
		
			require_once(OWA_BASE_CLASSES_DIR.'owa_metric.php');
			
		endif;
		
		$m = owa_coreAPI::moduleSpecificFactory($metric_name, 'metrics', '', $this->params, false);
		
		$m->applyOverrides($params);
		
		return $m->generate();
	}
	
	/**
	 * Factory for generating graphs
	 *
	 * @param unknown_type $entity_name
	 * @return unknown
	 */
	function graphFactory($graph_name, $params = array()) {
		
		return owa_coreAPI::moduleSpecificFactory($graph_name, 'graphs', '', $params, false);
		
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
	function moduleSpecificFactory($modulefile, $class_dir, $class_suffix = null, $params = '', $add_module_name = true) {
		
		list($module, $file) = split("\.", $modulefile);
		$class = 'owa_'.$file.$class_suffix;
		
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
	
	
	/**
	 * Returns a consolidated list of admin/options panels from all active modules 
	 *
	 * @return array
	 */
	function getAdminPanels() {
		
		$panels = array();
		
		foreach ($this->modules as $k => $v) {
			$v->registerAdminPanels();
			$module_panels = $v->getAdminPanels();
			
			foreach ($module_panels as $key => $value) {
				
				$panels[$value['group']][] = $value;
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
	function getNavigation($view, $nav_name, $sortby ='order') {
		
		$links = array();
		
		foreach ($this->modules as $k => $v) {
			
			// If the module does not have nav links, register them. needed in case this function is called twice on
			// same view.
			if (empty($v->nav_links)):
				$v->registerNavigation();
			endif;		
			
			$module_nav = $v->getNavigationLinks();
			
	
			if (!empty($module_nav)):
				// assemble the navigation for a specific view's named navigation element'	
				foreach ($module_nav as $key => $value) {
					
					$links[$value['view']][$value['nav_name']][] = $value;
				}
			endif;
			
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
	
	function getNavSort($a, $b) {
		
		return strnatcmp($a['order'], $b['order']);
	}
	
	/**
	 * Strip a URL of certain GET params
	 *
	 * @return string
	 */
	function stripDocumentUrl($url) {
		
		if ($this->config['clean_query_string'] == true):
		
			if (!empty($this->config['query_string_filters'])):
				$filters = str_replace(' ', '', $this->config['query_string_filters']);
				$filters = explode(',', $filters);
			else:
				$filters = array();
			endif;
			
			// OWA specific params to filter
			array_push($filters, $this->config['source_param']);
			array_push($filters, $this->config['ns'].$this->config['feed_subscription_id']);
			
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
	
}

?>