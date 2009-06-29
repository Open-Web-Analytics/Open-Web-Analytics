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

class owa_reportVisitorController extends owa_reportController {
	
	function owa_reportVisitorController($params) {
		
		return owa_reportVisitorController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.latestVisits');
		$m->setConstraint('site_id', $this->getParam('site_id'));
		$m->setConstraint('owa_session.visitor_id', $this->getParam('visitor_id'));
		$period = $this->makeTimePeriod('all_time');
		$m->setPeriod($period);
		$m->setOrder('DESC'); 
		$m->setLimit(15);
		$m->setPage($this->getParam('page'));
		$results = $m->generate();
		$pagination = $m->getPagination();
		$this->set('visits', $results);
		$this->set('pagination', $pagination);
		$this->set('visitor_id', $this->getParam('visitor_id'));
		$this->setView('base.report');
		$this->setSubview('base.reportVisitor');
		$this->setTitle('Visitor History');
				
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

class owa_reportVisitorView extends owa_view {
	
	function owa_reportVisitorView() {
		
		return owa_reportVisitorView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_visitor.tpl');	
		$this->body->set('visitor_id', $this->get('visitor_id'));
		$this->body->set('visits', $this->get('visits'));

		return;
	}
	
	
}


?>