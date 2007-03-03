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
 * Visitors Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsController extends owa_reportController {
	
	function owa_reportVisitorsController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
		
		//print_r($this->config);
		
		return;
	}
	
	function action() {
		
		$data = array();
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data['visitors_age'] = $api->getMetric('base.visitorsAge',array(
			
			'period'			=> $this->params['period'],
			'constraints'		=> array('site_id'	=> $this->params['site_id']),
			'limit' 			=> $this->params['limit']
		));
		
		//print_r($data['visitors_age']);
		//$data['sub_nav'] = $api->getNavigation('base.reportVisitors', 'sub_nav');
		$data['nav_tab'] = 'base.reportVisitors';
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportVisitors';
		
		return $data;
		
	}
	
}

/**
 * Visitors Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsView extends owa_view {
	
	function owa_reportVisitorsView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_visitors.tpl');
	
		$this->body->set('headline', 'Visitors Report');
			
		$this->body->set('visitors_age', $data['visitors_age']);
		$this->body->set('sub_nav', $data['sub_nav']);
		
		return;
	}
	
	
}


?>