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

class owa_reportVisitorController extends owa_reportController {
	
	function owa_reportVisitorController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function action() {
		
		$data = array();
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data['latest_visits'] = $api->getMetric('base.latestVisits', array(
		
			'constraints'				=> array(
				'site_id'				=> $this->params['site_id'],
				'session.visitor_id' 	=> $this->params['visitor_id']),
			'limit' => 10
			
		));
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportVisitor';
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

class owa_reportVisitorView extends owa_view {
	
	function owa_reportVisitorView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_visitor.tpl');
	
		$this->body->set('headline', 'Visitor Report');
		
		//$this->body->set('config', $this->config);
		
		//$this->body->set('params', $data);
		
		$this->body->set('visitor_id', $data['params']['visitor_id']);
			
		$this->body->set('visits', $data['latest_visits']);

		return;
	}
	
	
}


?>