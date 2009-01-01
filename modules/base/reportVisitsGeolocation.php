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
		
		return owa_reportVisitsGeolocationController::__construct($params);

	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	
	}
	
	function action() {
		$this->setTitle('Visitor Geo-location');
		$this->setView('base.report');
		$this->setSubview('base.reportVisitsGeolocation');
		$this->set('user_name', $this->getParam('u'));
		
		// perfrom authentication
		// is this needed?
		$auth = &owa_auth::get_instance();
		
		$this->set('passkey', $auth->generateUrlPasskey($this->getParam('u'), $this->getParam('p')));
		
		return;

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
				
		return owa_reportVisitsGeolocationView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_geolocation.tpl');
		$this->body->set('headline', 'Visitor Geolocation Report');
		$this->body->set('user_name', $this->data['user_name']);
		$this->body->set('passkey', $this->data['passkey']);
		$this->setjs('includes/jquery/jquery.jmap-r72.js');
		
		return;
	}
	
	
}


?>