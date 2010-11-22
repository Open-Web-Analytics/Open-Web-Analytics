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
 * CLI Controller Class
 *
 * This controller should be used for internal management pages/actions that require authentication
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_cliController extends owa_adminController {
	
	var $is_admin = true;
	
	/**
	 * Constructor
	 *
	 * @param array $params
	 * @return owa_controller
	 */
	function __construct($params) {
		
		if (owa_coreAPI::getSetting('base', 'cli_mode')) {
		
			return parent::__construct($params);
			
		} else {
		
			owa_coreAPI::notice("Controller not called from CLI");
			exit;
		}
	}
	
		
}

?>