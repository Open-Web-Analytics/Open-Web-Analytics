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
 * Visitors Roster Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsRosterController extends owa_reportController {
	
	function owa_reportVisitorsRosterController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {
		
		$data = array();
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data['visitors'] = $api->getMetric('base.visitorsList',array(
			
			
			'constraints'		=> array('site_id'	=> $this->params['site_id'],
										'visitor.first_session_year' => $this->params['year2'],
										'visitor.first_session_month' => $this->params['month2'],
										'visitor.first_session_day' => $this->params['day2']
										),
			'limit' 			=> $this->params['limit']
		));
		
		$data['nav_tab'] = 'base.reportVisitors';
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportVisitorsRoster';
		
		return $data;
		
	}
	
}

/**
 * Visitors Roster Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsRosterView extends owa_view {
	
	function owa_reportVisitorsRosterView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates
		
		//print_r($data['visitors']);
		
		$this->body->set_template('report_visitors_roster.tpl');
	
		$this->body->set('headline', 'Visitors');
			
		$this->body->set('visitors', $data['visitors']);
		
		
		return;
	}
	
	
}


?>