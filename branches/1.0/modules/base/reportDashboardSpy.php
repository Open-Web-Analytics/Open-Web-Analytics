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
 * Dashboard Report Spy Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDashboardSpyController extends owa_reportController {

	function owa_reportDashboardSpyController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
	
		return;
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data = array();
		$data['params'] = $this->params;
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportDashboardSpy';	
		$data['nav_tab'] = 'base.reportDashboard';
		
		return $data;	
		
	}
	
}
		


/**
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDashboardSpyView extends owa_view {
	
	function owa_reportDashboardSpyView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Set Page title
		$this->t->set('page_title', '');
		
		// Set Page headline
		$this->body->set('headline', 'Analytics Dashboard Spy');
		
		$this->body->set('nav', $data['nav']);
		
		// load body template
		$this->body->set_template('report_dashboard_spy.tpl');		
				
		return;
	}
	
	
}


?>