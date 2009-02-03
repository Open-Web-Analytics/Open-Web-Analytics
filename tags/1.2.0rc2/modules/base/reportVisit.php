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
		
		return owa_reportVisitController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.latestVisits');
		$m->setConstraint('site_id', $this->getParam('site_id'));
		$m->setConstraint('owa_session.id', $this->getParam('session_id'));
		$period = $this->makeTimePeriod('all_time');
		$m->setPeriod($period); 
		$m->setLimit(1);
		$this->set('latest_visits', $m->generate());
		
		//setup Metrics
		$c = owa_coreApi::metricFactory('base.clickstream');
		$c->setConstraint('site_id', $this->getParam('site_id'));
		$c->setConstraint('session_id', $this->getParam('session_id'));
		$period = $this->makeTimePeriod('all_time');
		$c->setPeriod($period); 
		$c->setLimit(35);
		$c->setPage($this->getParam('page'));
		$this->set('clickstream', $c->generate());
		$pagination = $c->getPagination();
		$this->setPagination($pagination);
		$this->set('session_id', $this->getParam('session_id'));
		$this->setView('base.report');
		$this->setSubview('base.reportVisit');
		$this->setTitle('Visit Clickstream');
		return;
			
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
		
		return owa_reportVisitView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function construct($data) {
		
		// Assign data to templates

		$this->body->set_template('report_visit.tpl');	
		$this->body->set('session_id', $this->get('session_id'));
		$this->body->set('visits', $this->get('latest_visits'));
		$this->body->set('clickstream', $this->get('clickstream'));

		return;
	}
	
	
}


?>