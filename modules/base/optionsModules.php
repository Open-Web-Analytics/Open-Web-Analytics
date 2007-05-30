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

require_once(OWA_BASE_CLASSES_DIR.'owa_controller.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_view.php');

/**
 * Options Modules Roster Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_optionsModulesController extends owa_controller {
	
	function owa_optionsModulesController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'admin';
		
		return;
	}

	function action() {
		
		$path = OWA_BASE_CLASSES_DIR.'modules'.DIRECTORY_SEPARATOR;
		$dirs = array();
		
		if ($handle = opendir($path)):
 			while (($file = readdir($handle)) !== false) {
 				
 				// test for '.' in dir name
				if (strpos($file, '.') === false): 	
					
					// test for whether file is a dir
					if (is_dir($path.$file)):
		 			
		 				$mod = owa_coreAPI::moduleClassFactory($file);
		 				$dirs[$file]['name'] = $mod->name;
		 				$dirs[$file]['display_name'] = $mod->display_name;
		 				$dirs[$file]['author'] = $mod->author;
		 				$dirs[$file]['group'] = $mod->group;
		 				$dirs[$file]['version'] = $mod->version;
		 				$dirs[$file]['description'] = $mod->description;
		 				$dirs[$file]['config_required'] = $mod->config_required;
		 				//$dirs['stats'] = lstat($path.$file);
		 				
 					endif;
   					
   				endif;
 			}
 		endif;
 		
 		closedir($handle);
	
		ksort($dirs);
		
		// remove base module so it can't be deactivated
		unset($dirs['base']);
		
		$active_modules = owa_coreAPI::getActiveModules();
		
		foreach ($active_modules as $module) {
			
			if (!empty($dirs[$module])):
				$dirs[$module]['status'] = 'active';
			endif;
		}
		
		// add data to container
		$this->data['view'] = 'base.options';
		$this->data['subview'] = 'base.optionsModules';
		$this->data['view_method'] = 'delegate';
		$this->data['modules'] = $dirs;
		
		return $this->data;
	
	}
	
}

/**
 * Options Modules View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_optionsModulesView extends owa_view {
	
	function owa_optionsModulesView($params) {
		
		$this->owa_view($params);
		//set priviledge level
		$this->_setPriviledgeLevel('admin');
		//set page type
		$this->_setPageType('Administration Page');
		
		return;
	}
	
	function construct($data) {
		
		
		//$this->c->get('base', 'modules'));
		
		// load template
		$this->body->set_template('options_modules.tpl');
		
		// fetch admin links from all modules
		$this->body->set('headline', 'Modules Administration');
	
		// Assign module data
		$this->body->set('modules', $data['modules']);
		
		return;
	}
	
	
}


?>