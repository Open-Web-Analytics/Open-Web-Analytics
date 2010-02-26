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
require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Site Profile Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sitesProfileController extends owa_adminController {
	
	function owa_sitesProfileController($params) {
		
		return owa_adminController::__construct($params);
	}
	
	function __construct($params) {
		
		$this->setRequiredCapability('edit_sites');
		return parent::__construct($params);
	}
	
	function action() {
		
		// needed as this controller is 
		$site_id = $this->getParam('site_id');
		if (!empty($site_id)) {
			$site = owa_coreAPI::entityFactory('base.site');
			$site->getByColumn('site_id', $this->getParam('site_id'));
			$site_data = $site->_getProperties();
		} else {
			$site_data = array();
		}
		
		$this->set('site', $site_data);
		$this->set('edit', $this->getParam('edit'));
		$this->setView('base.options');
		$this->setSubview('base.sitesProfile');
		return;
	}
	
}


/**
 *  Sites Profile View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sitesProfileView extends owa_view {
	
	function owa_sitesProfileView() {
		
		return owa_sitesProfileView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render() {
		//print $this->get('edit'); 
		//page title
		
		if ($this->get('edit')) {
			$this->body->set('action', 'base.sitesEdit');
			$this->body->set('headline', 'Edit Tracked Site Profile');

		} else {
			$this->body->set('action', 'base.sitesAdd');
			$this->body->set('headline', 'Add a New Tracked Site Profile');
		
		}
		
		
		$this->t->set('page_title', 'Tracked Site Profile');
		//$this->body->set('headline', $this->get('headline'));
		// load body template
		$this->body->set_template('sites_addoredit.tpl');
		//$this->body->set('action', $this->get('form_action'));		
		$this->body->set('site', $this->get('site'));
		$this->body->set('edit', $this->get('edit'));
		return;
	}
	
	
}



?>