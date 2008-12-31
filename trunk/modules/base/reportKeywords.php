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
 * Keywords Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportKeywordsController extends owa_reportController {
	
	function owa_reportKeywordsController($params) {
		
		return owa_reportKeywordsController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		$k = owa_coreAPI::metricFactory('base.topReferingKeywords');
		$k->setPeriod($this->getPeriod());
		$k->setConstraint('site_id', $this->getParam('site_id')); 
		$k->setLimit(30);
		$k->setPage($this->get('page'));
		$this->set('top_keywords', $k->generate());
		$this->setPagination($k->getPagination());
	
		// summary stats
		$s = owa_coreAPI::metricFactory('base.dashCountsTraffic');
		$s->setPeriod($this->getPeriod());
		$s->setConstraint('site_id', $this->getParam('site_id')); 
		$s->setConstraint('referer.is_searchengine', true);
		$this->set('summary_stats_data', $s->generate());
		
		// summary stats trend	used by sparklines
		$t = owa_coreAPI::metricFactory('base.trafficSummaryTrend');
		$t->setPeriod($this->makeTimePeriod('last_thirty_days'));
		$t->setConstraint('site_id', $this->getParam('site_id')); 
		$t->setConstraint('referer.is_searchengine', true);
		$trend = owa_lib::deconstruct_assoc($t->generate());
		$this->set('summary_trend', $trend);
				
		$this->setTitle('Keywords');
		$this->setView('base.report');
		$this->setSubview('base.reportKeywords');
		
		return;
		
	}
}


/**
 * Keywords Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportKeywordsView extends owa_view {
	
	function owa_reportKeywordsView() {
	
		return owa_reportKeywordsView::__construct() ;
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign Data to templates
		$this->body->set('keywords', $this->get('top_keywords'));
		$this->body->set('summary_stats', $this->get('summary_stats_data'));	
		$this->body->set('summary_trend', $this->get('summary_trend'));
		$this->body->set_template('report_keywords.tpl');

		return;
	}
	
	
}


?>