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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Installation Start Controller for Embedded Configurations
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installStartEmbeddedController extends owa_controller {


	function __construct($params) {

		$this->setRequiredCapability('edit_modules');
		return parent::__construct($params);
	}

	
	function owa_installEmbeddedController($params) {
	
		return owa_installEmbeddedController::__construct($params);
	}
	
	function action() {
		
		$this->set('site_id', $this->getParam('site_id'));
		$this->set('name', $this->getParam('name'));
		$this->set('domain', $this->getParam('domain'));
		$this->set('description', $this->getParam('description'));
		$this->setView('base.installStartEmbedded');
		
		return;
	}




}
/**
 * Installation Start View for Embedded Configurations
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installStartEmbeddedView extends owa_view {
	
	function owa_installStartEmbeddedView() {
		
		return owa_installStartEmbeddedView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render() {
		
		$this->body->set_template('install_start_embedded.tpl');
		
		//page title
		$this->t->set_template('wrapper_public.tpl');
		$this->t->set('page_title', 'Open Web Analytics Installation');
		
		// assign data		
		$this->body->set('headline', 'Shall we install Open Web Analytics?');
		$this->body->set('site_id', $this->get('site_id'));
		$this->body->set('domain', $this->get('domain'));
		$this->body->set('name', $this->get('name'));
		$this->body->set('description', $this->get('description'));
		
		return;
	}
	
	
}


?>