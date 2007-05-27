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

/**
 * Abstract Module Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_module extends owa_base {
	
	/**
	 * Name of module
	 *
	 * @var string
	 */
	var $name;
	
	/**
	 * Description of Module
	 *
	 * @var string
	 */
	var $description;
	
	/**
	 * Version of Module
	 *
	 * @var string
	 */
	var $version;
	
	/**
	 * Name of author of module
	 *
	 * @var string
	 */
	var $author;
	
	/**
	 * URL for author of module
	 *
	 * @var unknown_type
	 */
	var $author_url;
	
	/**
	 * Wiki Page title. Used to generate link to OWA wiki for this module.
	 * 
	 * Must be unique or else it will could clobber another wiki page.
	 *
	 * @var string
	 */
	var $wiki_title;
	
	/**
	 * name used in display situations
	 *
	 * @var unknown_type
	 */
	var $display_name;
	
	/**
	 * Array of event names that this module has handlers for
	 *
	 * @var array
	 */
	var $subscribed_events;
	
	/**
	 * Array of link information for admin panels that this module implements.
	 *
	 * @var array
	 */
	var $admin_panels;
	
	/**
	 * Array of navigation links that this module implements
	 *
	 * @var unknown_type
	 */
	var $nav_links;
	
	/**
	 * Array of metric names that this module implements
	 *
	 * @var unknown_type
	 */
	var $metrics;
	
	/**
	 * Array of graphs that are implemented by this module
	 *
	 * @var array
	 */
	var $graphs;
	
	/**
	 * The Module Group that the module belongs to. 
	 * 
	 * This is used often to group a module's features or functions together in the UI
	 * 
	 * @var string 
	 */
	var $group;
	
	/**
	 * Array of Entities that are implmented by the module
	 * 
	 * @var array 
	 */
	var $entities;
	
	/**
	 * Constructor
	 *
	 * @return owa_module
	 */
	function owa_module() {
		
		$this->owa_base();
		
		// register event handlers unless OWA is operating in async handling mode
		if ($this->config['async_db'] == false):
			$this->_registerEventHandlers();
		endif;
		
		$this->_registerEntities();
		
		return;
		
	}
	
	/**
	 * Returns array of admin Links for this module to be used in navigation
	 * 
	 * @access public
	 * @return array
	 */
	function getAdminPanels() {
		
		return $this->admin_panels;
	}
	
	/**
	 * Returns array of report links for this module that will be 
	 * used in report navigation
	 *
	 * @access public
	 * @return array
	 */
	function getNavigationLinks() {
		
		return $this->nav_links;
	}
	
	/**
	 * Abstract method for registering event handlers
	 * 
	 * @access public
	 * @return array
	 */
	function _registerEventHandlers() {
		
		return;
	}
	
	/**
	 * Abstract method for registering administration panels
	 * 
	 * @access public
	 * @return array
	 */
	function _registerAdminPanels() {
		
		return;
	}
	
	/**
	 * Attaches an event handler to the event queue
	 *
	 * @param array $event_name
	 * @param string $handler_name
	 * @return boolean
	 */
	function _addHandler($event_name, $handler_name) {
		
		$handler_dir = OWA_BASE_DIR.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$this->name.DIRECTORY_SEPARATOR.'handlers';
		
		$class = 'owa_'.$handler_name;
		
		// Require class file if class does not already exist
		if(!class_exists('owa_'.$handler_name)):	
			require_once($handler_dir.DIRECTORY_SEPARATOR.$handler_name.'.php');
		endif;
		
		$handler = &owa_lib::factory($handler_dir,'owa_', $handler_name);
		$handler->_priority = PEAR_LOG_INFO;
		
		$eq = &eventQueue::get_instance();
			
		// Register event names for this handler
		if(is_array($event_name)):
			
			foreach ($event_name as $k => $name) {	
				$handler->_event_type[] = $name;	
			}
			
		else:
			$handler->_event_type[] = $event_name;
			
		endif;
			
		$eq->attach($handler);
		
		return ;
		
	}
	
	/**
	 * Registers an admin panel with this module 
	 *
	 */
	function addAdminPanel($panel) {
		
		$this->admin_panels[] = $panel;
		
		return true;
	}
	
	/**
	 * Registers Navigation Link with a particular View
	 * 
	 */
	function addNavigationLink($link) {
		
		$this->nav_links[] = $link;
		
		return;
	}
	
	/**
	 * Installation method for this module
	 * 
	 * Concrete classes must be placed in the install sub directory and 
	 * use the following naming convention: owa_install_{module name}_{database type}.
	 *
	 */
	function install() {
		
		$obj = $this->installerFactory();
		$this->e->notice('starting install');
		$tables_to_install = $obj->checkForSchema();
		
		$table_errors = '';
		
		if (!empty($tables_to_install)):
		
			foreach ($tables_to_install as $table) {
			
				$status = $obj->create($table);
				
				if ($status == true):
					$this->e->notice(sprintf("Created %s table.", $table));
				else:
					$this->e->err(sprintf("Creation of %s table failed.", $table));
					$table_errors = 'error';
				endif;
				
			}
			
		endif;
		
		if ($table_errors != 'error'):
			
			// save schema version to configuration THIS NEEDS TO BE FIXED.
			$this->c->set($this->name, 'schema_version', $obj->version);
			// activate module and persist configuration changes 
			$this->activate();
	
			$this->e->notice(sprintf("Schema version %s installation complete.", $obj->version));
			return true;
		else:
			return false;
		endif;
				
	}
	
	
	/**
	 * Install Class Factory 
	 * 
	 * @return object Concrete install class for this module
	 */
	function installerFactory($params = array()) {
		
		$obj = owa_lib::factory(OWA_BASE_DIR.'/modules/'.$this->name.'/install/', 'owa_', 'install_'.$this->name.'_'.OWA_DB_TYPE, $params);
		
		$obj->module = $this->name;
		
		return $obj;
		
	}
	
	/**
	 * Checks for and applies schema upgrades for the module
	 *
	 */
	function upgrade() {
		
		return;
	}
	
	/**
	 * Deactivates and removes schema for the module
	 * 
	 */
	function uninstall() {
		
		return;
	}
	
	/**
	 * Places the Module into the active module list in the global configuration
	 * 
	 */
	function activate() {
		
		if ($this->name != 'base'):
			
			$config = owa_coreAPI::entityFactory('base.configuration');
			$config->getByPk('id', $this->c->get('base', 'configuration_id'));
			
			$settings = unserialize($config->get('settings'));
			
			if (!empty($settings)):
				
				// settings overrides are in the db and the modules sub array already exists
				if (!empty($settings['base']['modules'])):
					$settings['base']['modules'][] = $this->name;
					$config->set('settings', serialize($settings));
					$config->update();
				
				// settings overrides exist in db but no modules sub arrray exists
				else:
					$modules = $this->config['modules'];
					$modules[] = $this->name;
					$settings['base']['modules'] = $modules;
					$config->set('settings', serialize($settings));
					$config->update();
				endif;
			
			else:
				// need to create persist the settings overrides for the first time
				$modules = $this->config['modules'];
				$modules[] = $this->name;
				$settings = array('base' => array('modules' => $modules));
				$config->set('settings', serialize($settings));
				$config->set('id', $this->c->get('base', 'configuration_id'));
				$config->create();
				
			endif;
			
		endif;
		
	
		
		return;
	}
	
	/**
	 * Deactivates the module by removing it from the active module list in the global configuration
	 * 
	 */
	function deactivate() {
		
		if ($this->name != 'base'):
			
			$config = owa_coreAPI::entityFactory('base.configuration');
			$config->getByPk('id', $this->c->get('base', 'configuration_id'));
			
			$settings = unserialize($config->get('settings'));
			
			$new_modules = array();
			
			foreach ($settings['base']['modules'] as $k => $v){
				if ($v != $this->name):
					$new_modules[] = $v;
				endif;
			}
			
			$settings['base']['modules'] = $new_modules;
			$config->set('settings', serialize($settings));
			$config->update();	
			
		endif;
		
		return;
	}
	
	/**
	 * Registers a set of entities for the module
	 * 
	 */
	function _registerEntities() {
		
		return;
	}
	
	
}

?>