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
	
	var $init;
	var $request;
	var $current_user;
	var $settings;
	
	
	function owa_service() {
		
		return owa_service::__construct();
	}
	
	function __construct() {
		
		// setup request container
		$this->request = owa_coreAPI::requestContainerSingleton();
		// setup settings
		$this->settings = owa_coreAPI::configSingleton(); 
		// setup current user
		$this->current_user = owa_coreAPI::supportClassFactory('base', 'serviceUser');
		$this->current_user->setRole('everyone');
	}
	
	function &getCurrentUser() {
		
		return $this->current_user;
	}
	
	function getRequest() {
		
		return $this->request;
	}
	
}


?>