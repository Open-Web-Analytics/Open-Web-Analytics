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

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Hello World Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_helloModule extends owa_module {
	
	
	function __construct() {
		
		$this->name = 'hello';
		$this->display_name = 'Hello World';
		$this->group = 'hello';
		$this->author = 'Peter Adams';
		$this->version = '1.0';
		$this->description = 'Hello world sample module.';
		$this->config_required = false;
		$this->required_schema_version = 1;
		
		return parent::__construct();
	}
	
	/**
	 * Registers Admin panels with the core API
	 *
	 */
	function registerAdminPanels() {
		
		$this->addAdminPanel(array( 'do' 			=> 'hello.exampleSettings', 
									'priviledge' 	=> 'admin', 
									'anchortext' 	=> 'Hello World!',
									'group'			=> 'Test',
									'order'			=> 1));
		
									
		return;
		
	}
	
	public function registerNavigation() {
		$this->addNavigationSubGroup('Hello World', 'hello.reportDashboard', 'Hello Dashboard');
		$this->addNavigationLinkInSubGroup('Hello World','hello.reportSearchterms','also to the dashboard',1);
		
	}
	
	/**
	 * Registers Event Handlers with queue queue
	 *
	 */
	function _registerEventHandlers() {
		
		
		// Clicks
		//$this->_addHandler('base.click', 'clickHandlers');
		
		return;
		
	}
	
	function _registerEntities() {
		
		//$this->entities[] = 'myentity';
	}
	
	
}


?>