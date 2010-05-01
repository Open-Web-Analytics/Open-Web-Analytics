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
		
		$cu = owa_coreAPI::getCurrentUser();
		//print_r($cu);
		
		// dash counts	
		$d = owa_coreAPI::metricFactory('base.dashCounts');
		$d->setPeriod($this->getPeriod());
		$d->setConstraint('site_id', $this->getParam('site_id')); 
		$res = $d->generate();
		//print_r($d->zeroFill($res));
		$this->set('summary_stats_data', $d->zeroFill($res));
		
		
		// action counts	
		$params = array('period' 	  => $this->get('period'),
						'startDate'	  => $this->get('startDate'),
						'endDate'	  => $this->get('endDate'),
						'metrics' 	  => 'actions',
						'dimensions'  => 'actionName',
						'constraints' => 'site_id='.$this->getParam('site_id')
						);
						
		$rs = owa_coreAPI::getResultSet($params);	
		//print_r($rs);			
		$this->set('actions', $rs);
		
		// dash trend	
		$dt = owa_coreAPI::metricFactory('base.dashCoreByDay');
		$dt->setPeriod($this->makeTimePeriod('last_thirty_days'));
		$dt->setConstraint('site_id', $this->getParam('site_id')); 
		$trend = owa_lib::deconstruct_assoc($dt->generate());
		//print_r($trend);
		$this->set('site_trend', $trend);
		
		// set view stuff
		$this->setSubview('base.reportDashboard');
		$this->setTitle('Analytics Dashboard');	
			
		return;	
		
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
	
	function owa_reportDashboardView() {
		
		return owa_reportDashboardView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render() {
		
		$this->body->set_template('report_dashboard.tpl');
		$this->body->set('summary_stats', $this->get('summary_stats_data'));			
		$this->body->set('site_trend', $this->get('site_trend'));
		$this->body->set('actions', $this->get('actions'));
		return;
	}
	
	
}


?>