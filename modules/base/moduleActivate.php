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
 * Module Activation Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_moduleActivateController extends owa_controller {
	
	function owa_moduleActivateController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'admin';
		
		return;
	}

	function action() {
		
		$config = owa_coreAPI::entityFactory('base.configuration');
		$config->getByPk('id', $this->c->get('base', 'configuration_id'));
		
		$settings = unserialize($config->get('settings'));
		
		if (!empty($settings)):
			
			// settings overrides are in the db and the modules sub array already exists
			if (!empty($settings['base']['modules'])):
				$settings['base']['modules'][] = $this->params['module'];
				$config->set('settings', serialize($settings));
				$config->update();
			
			// settings overrides exist in db but no modules sub arrray exists
			else:
				$modules = $this->config['modules'];
				$modules[] = $this->params['module'];
				$settings['base']['modules'] = $modules;
				$config->set('settings', serialize($settings));
				$config->update();
			endif;
		
		else:
			// need to create persist the settings overrides for the first time
			$modules = $this->config['modules'];
			$modules[] = $this->params['module'];
			$settings = array('base' => array('modules' => $modules));
			$config->set('settings', serialize($settings));
			$config->set('id', $this->c->get('base', 'configuration_id'));
			$config->create();
			
		endif;
		
		
	
		$data = array();
		
		$data['do'] = 'base.optionsModules';
		$data['view_method'] = 'redirect';
		$data['status_code'] = 2501;
		
		return $data;
	
	}
	
}

?>