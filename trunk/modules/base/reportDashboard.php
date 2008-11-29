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

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		// dash counts	
		$d = owa_coreAPI::metricFactory('base.dashCounts');
		$d->setPeriod($this->getPeriod());
		$d->setConstraint('site_id', $this->getParam('site_id')); 
		$this->set('summary_stats_data', $d->generate());
			
		// Counts
		$s = owa_coreAPI::metricFactory('base.sessionsCount');
		$s->setConstraint('site_id', $this->getParam('site_id'));
		$s->setPeriod($this->getPeriod());
		$this->set('sessions_count', $s->generate());
		
		// Counts
		$st = owa_coreAPI::metricFactory('base.sessionsTrend');
		$st->setConstraint('site_id', $this->getParam('site_id'));
		$st_period = owa_coreAPI::supportClassFactory('base', 'timePeriod');
		$st_period->set('this_year');
		$st->setPeriod($st_period);
		$this->set('sessions_trend', $st->generate());
		
		// set view stuff
		$this->setSubview('base.reportDashboard');
		$this->setTitle('Analytics Dashboard');	
			
		return;	
		
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

class owa_reportDashboardView extends owa_view {
	
	function owa_reportDashboardView() {
		
		return owa_reportDashboardView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render() {
		
		// load body template
		$this->body->set_template('report_dashboard.tpl');
	
		$this->body->set('summary_stats', $this->data['summary_stats_data']);
		
		$this->body->set('config', $this->config);
		
		$this->body->set('params', $this->data['params']);
				
		$this->body->set('visits', $this->data['latest_visits']);
		
		$this->body->set('sessions_count', $this->data['sessions_count']);
		
		$this->body->set('sessions_trend', $this->data['sessions_trend']);
		
		//$this->body->set('pagination', $this->data['pagination']);
		
		$this->setJs("owa.widgets.js");
		$this->setCss("owa.widgets.css");
		
		//$this->setJs('includes/json2.js');
		//$this->setJs('includes/swfobject.js');

		return;
	}
	
	
}


?>