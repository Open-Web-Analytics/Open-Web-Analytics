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

require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_settings_class.php');

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

class owa_optionsUpdateController extends owa_controller {
	
	function owa_optionsUpdateController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'admin';
		
		return;
	}

	function action() {
		
		//create the new config array
		$new_config = array();
			
		// needed for following DB queries just in case the various 
		// implementations of the GUI does not allow you to set this.
		$new_config['configuration_id'] = $this->config['configuration_id'];
			
		foreach ($this->params['config'] as $key => $value) {
				
				// update exising config, needed?
				$this->config[$key] = $value;
				//add to config going to the db
				$new_config[$key] = $value;
				
		}
		
		owa_settings::save($new_config);
		
		$this->e->notice("Configuration changes saved to database.");
	
		$data = array();
		$data['view'] = 'base.options';
		$data['subview'] = 'base.optionsGeneral';
		$data['view_method'] = 'delegate';
		$data['status_msg'] = $this->getMsg(2500);
		
		return $data;
	
	}
	
}


?>