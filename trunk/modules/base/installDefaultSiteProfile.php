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
 * Installer Default Site Profile View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installDefaultSiteProfileView extends owa_view {
	
	function owa_installDefaultSiteProfileView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		// Set Page title
		$this->t->set('page_title', 'Default Site Profile');
		
		// Set Page headline
		$this->body->set('headline', 'Default Site Profile');
		
		$this->body->set('action', 'base.installDefaultSiteProfile');
		
		// load body template
		$this->body->set_template('sites_addoredit.tpl');
		
		
		
		return;
	}
	
	
}

/**
 * Installer Default Site Profile Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installDefaultSiteProfileController extends owa_controller {
	
	function owa_installDefaultSiteProfileController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		// Control logic
			
		// validations
		
		if (empty($this->params['domain'])):
			$data['view_method'] = 'delegate'; // Delegate, redirect
			$data['view'] = 'base.install';
			$data['subview'] = 'base.installDefaultSiteProfile';
			$data['error_msg'] = $this->getMsg(3207);
			$data['site'] = $this->params;	
			
			return $data;
			
		endif;
		
		$site = owa_coreAPI::entityFactory('base.site');
		
		$site->set('site_id', md5($this->params['domain']));
		$site->set('name', $this->params['name']);
		$site->set('description', $this->params['description']);
		$site->set('domain', $this->params['domain']);
		$site->set('site_family', $this->params['site_family']);
		
		$status = $site->create();
		
		if ($status == true):	
			// Setup the data array that will be returned to the view.
			
			$data['view_method'] = 'redirect'; // Delegate, redirect
			$data['view'] = 'base.install';
			$data['subview'] = 'base.installFinish';
			$data['status_code'] = 3303;
			$data['site_id'] = $site->get('site_id');
		
		else:
		
			$data['view_method'] = 'delegate'; // Delegate, redirect
			$data['view'] = 'base.install';
			$data['subview'] = 'base.installDefaultSiteProfile';
			$data['error_msg'] = $this->getMsg(3206);
			$data['site'] = $this->params;	
			
		endif;
			
		return $data;
	}
	
	
}


?>