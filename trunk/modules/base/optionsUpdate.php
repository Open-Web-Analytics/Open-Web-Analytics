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

require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Options Update Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_optionsUpdateController extends owa_adminController {
	
	function owa_optionsUpdateController($params) {
		$this->owa_adminController($params);
		$this->priviledge_level = 'admin';
		
		return;
	}

	function action() {
	
		$configuration_id = $this->c->get('base', 'configuration_id');
		$config = owa_coreAPI::entityFactory('base.configuration');
		$config->getByPk('id', $configuration_id);
		
		$settings = unserialize($config->get('settings'));
		$new_settings = array();
	
		if (!empty($settings)):
			if (!empty($settings['base'])):
				$new_settings['base'] = array_merge($settings['base'], $this->params['config']);
			else:
				$new_settings['base'] = $this->params['config'];
			endif;
		else:
			
			$new_settings['base'] = $this->params['config'];
		endif;
		
		$config->set('settings', serialize($new_settings));
		
		$id = $config->get('id');
		
		if (!empty($id)):
			$config->update();
		else:
			$config->set('id', $configuration_id);
			$config->create();
		endif;
	
		$this->e->notice("Configuration changes saved to database.");
	
		$data = array();
		$data['do'] = 'base.optionsGeneral';
		$data['view_method'] = 'redirect';
		$data['status_code'] = 2500;
		
		return $data;
	
	}
	
}


?>