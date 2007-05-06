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
require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Visits geolocation Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitsGeolocationController extends owa_reportController {
	
	function owa_reportVisitsGeolocationController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data = array();
		$data['params'] = $this->params;
		
		$data['nav_tab'] = 'base.reportVisitors';
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportVisitsGeolocation';
		$data['user_name'] = $this->params['u'];
		
		//perfrom authentication
		$auth = &owa_auth::get_instance();
		
		$data['passkey'] = $auth->generateUrlPasskey($this->params['u'], $this->params['p']);
		
		return $data;

		
	}

}


/**
 * Visits Geolocation Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitsGeolocationView extends owa_view {
	
	function owa_reportVisitsGeolocationView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_geolocation.tpl');
		$this->body->set('headline', 'Visitor Geolocation Report');
		$this->body->set('user_name', $data['user_name']);
		$this->body->set('passkey', $data['passkey']);
		
		return;
	}
	
	
}


?>