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

require_once(OWA_BASE_DIR.'/owa_adminController.php');

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

class owa_sitesEditController extends owa_adminController {
	
	function owa_sitesEditController($params) {
		$this->owa_adminController($params);
		$this->setRequiredCapability('edit_sites');
	}
	
	function action() {
		
		// This needs form validation in a bad way.
		
		$site = owa_coreAPI::entityFactory('base.site');
		$site->set('site_id', $this->params['site_id']);
		$site->set('name', $this->params['name']);
		$site->set('domain', $this->params['domain']);
		$site->set('description', $this->params['description']);
		$site->update('site_id');
		
		$data['view_method'] = 'redirect';
		$data['do'] = 'base.sites';
		$data['status_code'] = 3201;
		//assign original form data so the user does not have to re-enter the data
		
		
		//$data['site'] = $this->params;
		
		
		return $data;
	}
	
}


?>