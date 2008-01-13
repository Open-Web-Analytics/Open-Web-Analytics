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
 * Edit Sites View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sitesEditView extends owa_view {
	
	function owa_sitesEditView($params) {
		
		$this->owa_view($params);
		$this->priviledge_level = 'admin';
		
		return;
	}
	
	function construct($data) {
		
		//page title
		$this->t->set('page_title', 'Edit Web Site');
		$this->body->set('headline', 'Edit Web Site Profile');
		// load body template
		$this->body->set_template('sites_addoredit.tpl');
		
		$this->body->set('action', 'base.sitesEdit');
		
		//Check to see if user is passed by constructor or else fetch the object.
		if ($data['sites']):
			$this->body->set('site', $data['site']);
		else:
			$site = owa_coreAPI::entityFactory('base.site');
			$site->getByColumn('site_id', $data['site_id']);
			$this->body->set('site', $site->_getProperties());
			
		endif;
		
		return;
	}
	
	
}

/**
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sitesEditController extends owa_controller {
	
	function owa_sitesEditController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'admin';
	}
	
	function action() {
		
		// This needs form validation in a bad way.
		
		$site = owa_coreAPI::entityFactory('base.site');
		$site->set('id', $this->params['site_id']);
		$site->set('name', $this->params['name']);
		$site->set('domain', $this->params['domain']);
		$site->set('description', $this->params['description']);
		$site->update();
		
		$data['view_method'] = 'redirect';
		$data['view'] = 'base.options';
		$data['subview'] = 'base.sites';
		$data['status_code'] = 3201;
		//assign original form data so the user does not have to re-enter the data
		
		
		//$data['site'] = $this->params;
		
		
		return $data;
	}
	
}


?>