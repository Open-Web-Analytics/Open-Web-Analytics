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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Installer Default Site Profile Entry Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installDefaultSiteProfileEntryController extends owa_controller {
	
	function owa_installDefaultSiteProfileEntryController($params) {
		
		return  owa_installDefaultSiteProfileEntryController::__construct($params); 
	}
	
	function __construct($params) {
		
		parent::__construct($params);
		
		//Load config from db
		$this->c->load();
		// Secure access to this controller if the installer has already been run
		if ($this->c->get('base', 'install_complete') === true) {
			$this->setRequiredCapability('edit_modules');
		}
	
		return;
		
	}
	
	function action() {
		
		$this->setView('base.install');
		$this->setSubview('base.base.installDefaultSiteProfileEntry');
		return;

	}
	
	
}


/**
 * Installer Default Site Profile Entry View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installDefaultSiteProfileEntryView extends owa_view {
	
	function owa_installDefaultSiteProfileEntryView() {
		
		return owa_installDefaultSiteProfileEntryView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render() {
		
		// Set Page title
		$this->t->set('page_title', 'Default Site Profile');
		
		// Set Page headline
		$this->body->set('headline', 'Default Site Profile');
		
		// Set Page headline
		$this->body->set('action', 'base.installDefaultSiteProfile');
				
		// load body template
		$this->body->set_template('sites_addoredit.tpl');
		
		return;
	}
	
	
}



?>