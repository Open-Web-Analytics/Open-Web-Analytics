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

require_once(OWA_DIR.'owa_lib.php');
require_once(OWA_DIR.'owa_view.php');
require_once(OWA_DIR.'owa_controller.php');


/**
 * Updates Application Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_updatesApplyController extends owa_controller {
	
	function owa_updatesApplyController($params) {
		
		$this->priviledge_level = 'guest';
		
		$this->owa_controller($params);

		return;
	}

	function action() {
		
		// fetch list of modules that require updates
		$api = &owa_coreAPI::singleton();
		
		$modules = $api->getModulesNeedingUpdates();
		
		// foreach do update in order
		
		foreach ($modules as $k => $v) {
		
			$ret = $api->modules[$v]->update();
			
			if ($ret != true):
				break;
			endif;
		
		}
			
		// add data to container
		$this->data['status_code'] = '';
		$this->data['do'] = 'base.optionsGeneral';
		$this->data['view_method'] = 'delegate';
		
		return $this->data;
	
	}
	
}



?>