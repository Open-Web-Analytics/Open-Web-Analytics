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
		
		return owa_reportVisitorsController::__construct($params);
		
	}
	
	function __construct($params = null) {
	
		return parent::__construct($params);
	}
	
	function action() {
				
		// Top Visitors	
		$v = owa_coreAPI::metricFactory('base.topVisitors');
		$v->setPeriod($this->getPeriod());
		$v->setConstraint('site_id', $this->getParam('site_id')); 
		$v->setOrder('DESC');
		$v->setLimit(10);
		$this->set('top_visitors_data', $v->generate());
		
		
		//latest visitors 
		$m = owa_coreApi::metricFactory('base.latestVisits');
		$m->setConstraint('site_id', $this->getParam('site_id'));
		$m->setPeriod($this->getPeriod());
		$m->setOrder('DESC'); 
		$m->setLimit(25);
		$m->setPage($this->getParam('page'));
		$results = $m->generate();
		$pagination = $m->getPagination();
		$this->set('latest_visits', $results);
		$this->setPagination($pagination);
		
		// browser types
		$b = owa_coreAPI::metricFactory('base.sessionBrowserTypes');
		$b->setPeriod($this->getPeriod());
		$b->setConstraint('site_id', $this->getParam('site_id')); 
		//$b->setOrder('ASC');
		$this->set('browser_types', $b->generate());		
		
		// dash counts	
		$d = owa_coreAPI::metricFactory('base.dashCounts');
		$d->setPeriod($this->getPeriod());
		$d->setConstraint('site_id', $this->getParam('site_id')); 
		$d->setOrder('ASC');
		$this->set('summary_stats_data', $d->zeroFill($d->generate()));

		// view stuff
		$this->setView('base.report');
		$this->setSubview('base.reportVisitors');
		$this->setTitle('Visitors');
		
		return;
		
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
		
		return owa_reportVisitorsView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_visitors.tpl');
		$this->body->set('top_visitors', $this->get('top_visitors_data'));
		$this->body->set('browser_types', $this->get('browser_types'));
		$this->body->set('summary_stats', $this->get('summary_stats_data'));
		$this->body->set('visits', $this->get('latest_visits'));		
		return;
	}
	
	
}


?>