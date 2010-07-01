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

require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Dashboard Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDashboardController extends owa_reportController {

	function owa_reportDashboardController($params) {
	
		return owa_reportDashboardController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
				
		// action counts	
		$params = array('period' 	  => $this->get('period'),
						'startDate'	  => $this->get('startDate'),
						'endDate'	  => $this->get('endDate'),
						'metrics' 	  => 'actions',
						'dimensions'  => 'actionName',
						'constraints' => 'site_id='.$this->getParam('site_id'),
						'do'		  => 'getResultSet'
						);
						
		$rs = owa_coreAPI::executeApiCommand($params);	
		//print_r($rs);			
		$this->set('actions', $rs);
	
		// set view stuff
		$this->setSubview('base.reportDashboard');
		$this->setTitle('Analytics Dashboard');		
	}
	
}
		
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Dashboard Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDashboardView extends owa_view {
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render() {
		
		$this->body->set_template('report_dashboard.tpl');
		$this->body->set('summary', $this->get('summary'));			
		$this->body->set('site_trend', $this->get('site_trend'));
		$this->body->set('actions', $this->get('actions'));
	}
	
	
}


?>