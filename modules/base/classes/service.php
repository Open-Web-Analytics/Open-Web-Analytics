<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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

/**
 * Service User Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_service extends owa_base {
	
	var $init = false;
	var $request;
	var $state;
	var $current_user;
	var $settings;
	var $maps = array();
	var $update_required = false;
	var $install_required = false;
	var $modules_needing_updates = array();
	var $modules = array();
	var $entities = array();
	var $metrics = array();
	
	function owa_service() {
		
		return owa_service::__construct();
	}
	
	function __construct() {
		
		// setup request container
		$this->request = owa_coreAPI::requestContainerSingleton();
		// setup settings
		//$this->settings = owa_coreAPI::configSingleton(); 
		// setup current user
		$this->current_user = owa_coreAPI::supportClassFactory('base', 'serviceUser');
		$this->current_user->setRole('everyone');
		// the 'log_users' confi directive relies on this being populated
		$this->current_user->setUserData('user_id', $this->request->state->get('u'));
		
		return;
	}
	
	function initializeFramework() {
		
		if (!$this->isInit()) {
			$this->_loadModules();
			$this->_loadEntities();
			$this->_loadEventProcessors();
			$this->setInit();
		}
		
		return;
	}
	
	function _loadModules() {
			
		$am = owa_coreAPI::getActiveModules();
		
		foreach ($am as $k => $v) {
			
			$m = owa_coreAPI::moduleClassFactory($v);
			
			$this->addModule($m);
			
			// check for schema updates
			$check = $m->isSchemaCurrent();
			
			if ($check != true) {
				$this->markModuleAsNeedingUpdate($m->name);
			}
		}
		
		// set schema update flag
		if (!empty($this->modules_needing_updates)) {
			$this->setUpdateRequired();
		}
		
		return;
	}
	
		
	function _loadEntities() {
		
		foreach ($this->modules as $k => $module) {
			
			foreach ($module->entities as $entity_k => $entity_v) {
				// TODO: remove this to make API stateless
				//$this->entities[] = $module->name.$entity_v;
				// proper call
				$this->addEntity($entity_v, $module->name.'.'.$entity_v);
			}
		}
		
		return;
	}
	
	function _loadEventProcessors() {
		
		$processors = array();
		
		foreach ($this->modules as $k => $module) {
			
			$processors = array_merge($processors, $module->event_processors);
		}
		
		$this->setMap('event_processors', $processors);
	
	}
	
	function &getCurrentUser() {
		
		return $this->current_user;
	}
	
	function getRequest() {
		
		return $this->request;
	}
	
	function getState() {
		
		return $this->request->state;
	}
	
	function getMapValue($map_name, $name) {
		
		if (array_key_exists($map_name, $this->maps)) {
			
			if (array_key_exists($name, $this->maps[$map_name])) {
				
				return $this->maps[$map_name][$name];
			} else {
				
				return false;
			}
		} else {
			
			return false;
		}
	}
	
	function setMap($name, $map) {
		
		$this->maps[$name] = $map;
		return;
	}
	
	function setUpdateRequired() {
		
		$this->update_required = true;
		return;
	}
	
	function isUpdateRequired() {
		
		return $this->update_required;
	}
	
	function addModule($module) {
		
		$this->modules[$module->name] = $module;
	}
	
	function markModuleAsNeedingUpdate($name) {
		
		$this->modules_needing_updates[] = $name;
	}
	
	function getModulesNeedingUpdates($name) {
		
		return $this->modules_needing_updates;
	}
	
	
	function setInstallRequired() {
		$this->install_required = true;
	}
	
	function isInstallRequired() {
		
		return $this->install_required;
	}
	
	function addEntity($entity_name, $class) {
		
		$this->entities[$entity_name] = $class;
	}
	
	function setInit() {
		$this->init = true;
	}
	
	function isInit() {
		
		return $this->init;
	}
	
	function getModule($name) {
		
		if (array_key_exists($name, $this->modules)) {
			return $this->modules[$name];
		} else {
			return false;
		}
		
	}
	
	function getAllModules() {
		return $this->modules;
	}

	
}


?>