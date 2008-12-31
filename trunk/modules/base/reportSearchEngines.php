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
 * Search Engines Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportSearchEnginesController extends owa_reportController {
	
	function owa_reportSearchEnginesController($params) {
				
		return owa_reportSearchEnginesController::__construct($params);
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
				
		// top search engines
		$se = owa_coreAPI::metricFactory('base.topSearchEngines');
		$se->setPeriod($this->getPeriod());
		$se->setConstraint('site_id', $this->getParam('site_id')); 
		$se->setLimit(15);
		$se->setPage($this->getParam('page'));
		$se->setOrder('DESC');
		$ses = $se->generate();
		$this->set('top_search_engines', $ses);
		$this->setPagination($se->getPagination());

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
		
		// set views
		$this->setView('base.report');
		$this->setSubview('base.reportSearchEngines');
		$this->setTitle('Search Engines');
		
		return;
		
	}
}


/**
 * Search engines Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportSearchEnginesView extends owa_view {
	
	function owa_reportSearchEnginesView() {
		
		return owa_reportSearchEnginesView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render() {
		
		// Assign Data to templates
		$this->body->set('se_hosts', $this->get('top_search_engines'));
		$this->body->set('summary_stats', $this->get('summary_stats_data'));
		$this->body->set('summary_trend', $this->get('summary_trend'));
		$this->body->set_template('report_search_engines.tpl');
		
		return;
	}
	
	
}


?>