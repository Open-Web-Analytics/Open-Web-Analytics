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
	 * Wiki Page title. Sused to generate link to OWA wiki for this module.
	 * Must be unique. Check the wiki if you are unsure.
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
	 * Array of graph names that this module implments
	 *
	 * @var unknown_type
	 */
	var $graphs;
	
	var $group;
	
	var $entities;
	
	/**
	 * Constructor
	 *
	 * @return owa_module
	 */
	function owa_module() {
		
		$this->owa_base();
		
		// register event handlers
		$this->_registerEventHandlers();
		$this->_registerEntities();
		
		return;
		
	}
	
	/**
	 * Returns array of admin Links for this module to be used in navigation
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
	 * Registers handlers
	 * 
	 * @access public
	 * @return array
	 */
	function _registerEventHandlers() {
		
		return;
	}
	
	/**
	 * Registers Admin Panels
	 * 
	 * @access public
	 * @return array
	 */
	function _registerAdminPanels() {
		
		return;
	}
	
	/**
	 * Attaches a handler to the event queue
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
		//print_r($eq);
		return ;
		
	}
	
	/**
	 * Registers a report with this module
	 *
	 */
	function _addReport() {
		
		return;
	}
	
	/**
	 * Registers an admin panel with this module 
	 *
	 */
	function addAdminPanel($panel) {
		
		$this->admin_panels[] = $panel;
		
		return;
	}
	
	/*
	 * Registers Navigation Link with a particular View
	 * 
	 */
	function addNavigationLink($link) {
		
		$this->nav_links[] = $link;
		
		return;
	}
	
	/**
	 * Registers a report with this module
	 *
	 */
	function _addMetric() {
		
		return;
	}
	
	/**
	 * Registers a graph with this module
	 *
	 */
	function _addGraph() {
		
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
		return $obj->install();
		
	}
	
	function installerFactory($params = array()) {
		
		$obj = owa_lib::factory(OWA_BASE_DIR.'/modules/'.$this->name.'/install/', 'owa_', 'install_'.$this->name.'_'.$this->config['db_type'], $params);
		
		$obj->module = $this->name;
		
		return $obj;
		
	}
	
	/**
	 * Upgrade method for this module
	 *
	 */
	function upgrade() {
		
		return;
	}
	
	function uninstall() {
		
		return;
	}
	
	function activiate() {
		
		return;
	}
	
	function deactivate() {
		
		return;
	}
	
	function _registerEntities() {
		
		return;
	}
	
	
}




?>