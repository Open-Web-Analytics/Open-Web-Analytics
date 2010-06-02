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
 * Traffic Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportTrafficController extends owa_reportController {
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function owa_reportTrafficController($params) {
		
		return owa_reportTrafficController::__construct($params);
	}
	
	function action() {
	
		// view stuff
		$this->setView('base.report');
		$this->setSubview('base.reportTraffic');
		$this->setTitle('Traffic Sources');	
	}
}


/**
 * Traffic Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportTrafficView extends owa_view {
	
	function __construct() {

		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign Data to templates
	
		$this->body->set('sessions', $this->get('session_count'));
		$this->body->set('from_feeds', $this->get('from_feeds'));
		$this->body->set('from_sites', $this->get('from_sites'));
		$this->body->set('from_direct', $this->get('from_direct'));
		$this->body->set('from_se', $this->get('from_se'));
		
		$this->body->set_template('report_traffic.tpl');
	}
}


?>