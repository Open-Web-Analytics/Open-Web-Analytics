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
 * Visitors Loyalty Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsLoyaltyController extends owa_reportController {
	
	function owa_reportVisitorsLoyaltyController($params) {
				
		return owa_reportVisitorsLoyaltyController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// visitors age	
		$va = owa_coreAPI::metricFactory('base.visitorsAge');
		$va->setPeriod($this->getPeriod());
		$va->setConstraint('site_id', $this->getParam('site_id'));
		$va->setLimit(30); 
		$this->set('visitors_age', $va->generate());
		
		// dash counts	
		$d = owa_coreAPI::metricFactory('base.dashCounts');
		$d->setPeriod($this->getPeriod());
		$d->setConstraint('site_id', $this->getParam('site_id')); 
		$this->set('summary_stats_data', $d->generate());	
				
		$this->setView('base.report');
		$this->setSubview('base.reportVisitorsLoyalty');
		$this->setTitle('Visitor Loyalty');
		
		return;
		
	}
	
}

/**
 * Visitors Loyalty Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsLoyaltyView extends owa_view {
	
	function owa_reportVisitorsLoyaltyView() {
					
		return owa_reportVisitorsLoyaltyView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_visitors_loyalty.tpl');
		$this->body->set('visitors_age', $this->get('visitors_age'));
		$this->body->set('summary_stats', $this->get('summary_stats_data'));
		
		return;
	}
	
	
}


?>