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

require_once(OWA_BASE_CLASS_DIR.'installController.php');

/**
 * Install Default Site Profile Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installDefaultSiteProfileController extends owa_installController {
	
	function owa_installDefaultSiteProfileController($params) {
		
		return  owa_installDefaultSiteProfileController::__construct($params); 
	}
	
	function __construct($params) {
		
		parent::__construct($params);
		
		// validations
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($this->getParam('domain'));
		$v1->setErrorMessage($this->getMsg(3207));
		$this->setValidation('domain', $v1);
		
		return;
		
	}
	
	function errorAction() {
		
		$this->setView('base.install');
		$this->setSubview('base.installDefaultSiteProfile');
		$this->set('site', $this->params);
		return;
	}
	
	function action() {
		
			$site = owa_coreAPI::entityFactory('base.site');	
			$site->set('site_id', md5($this->params['domain']));
			$site->set('name', $this->params['name']);
			$site->set('description', $this->params['description']);
			$site->set('domain', $this->params['domain']);
			$site->set('site_family', $this->params['site_family']);
			
			$status = $site->create();
			
			if ($status == true):	
				// Setup the data array that will be returned to the view.
				
				$data['view'] = 'base.install';
				$data['subview'] = 'base.installAdminUserEntry';
				$data['status_code'] = 3303;
				$data['site_id'] = $site->get('site_id');
			
			else:
			
				$data['view_method'] = 'delegate'; // Delegate, redirect
				$data['view'] = 'base.install';
				$data['subview'] = 'base.installDefaultSiteProfileEntry';
				$data['error_msg'] = $this->getMsg(3206);
				$data['site'] = $this->params;	
				
			endif;
		
		return $data;
	}
	
	
}


?>