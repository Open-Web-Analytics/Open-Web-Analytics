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
 * Visit Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitController extends owa_reportController {
	
	function owa_reportVisitController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data = array();
		
		$data['params'] = $this->params;
		
		$data['clickstream'] = $api->getMetric('base.clickstream', array(
	
			'limit'				=> '50',
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'session_id' 	=> $this->params['session_id']
				)
		));
		
		$data['latest_visits'] = $api->getMetric('base.latestVisits', array(
		
			'period'			=> 'all_time',
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'],
				'session.id' 	=> $this->params['session_id']
				),
				
			'limit' 			=> 1
		));
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportVisit';
		$data['nav_tab'] = 'base.reportVisitors';
		
		return $data;
			
	}
	
}	

/**
 * Visit Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitView extends owa_view {
	
	function owa_reportVisitView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates

		$this->body->set_template('report_visit.tpl');
	
		$this->body->set('headline', 'Visit Report');
		
		//$this->body->set('config', $this->config);
		
		//$this->body->set('params', $data);
		
		$this->body->set('session_id', $data['params']['session_id']);
			
		$this->body->set('visits', $data['latest_visits']);
		
		$this->body->set('clickstream', $data['clickstream']);

		return;
	}
	
	
}


?>