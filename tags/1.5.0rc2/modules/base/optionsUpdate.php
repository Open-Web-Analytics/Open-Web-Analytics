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

require_once(OWA_BASE_CLASSES_DIR.'owa_adminController.php');

/**
 * Base Options Update Controller
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
	
	function __construct($params) {
	
		$this->setRequiredCapability('edit_settings');
		$this->setNonceRequired();
		return parent::__construct($params);
	
	}

	function action() {
		
		$c = owa_coreAPI::configSingleton();
		
		$config_values = $this->get('config');
		
		if (!empty($config_values)) {
			
			foreach ($config_values as $k => $v) {
			
				list($module, $name) = explode('.', $k);
				
				if ( $module && $name ) {
					$c->persistSetting($module, $name, $v);	
				}
			}
			
			$c->save();
			owa_coreAPI::notice("Configuration changes saved to database.");
			$this->setStatusCode(2500);	
		}
		
		$this->setRedirectAction('base.optionsGeneral');
	}
	
}


?>