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
 * Feeds Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportFeedsController extends owa_reportController {
	
	function owa_reportFeedsController($params) {
		
		return owa_reportFeedsController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// summary counts
		$fc = owa_coreAPI::metricFactory('base.feedSummaryCount');
		$fc->setPeriod($this->getPeriod());
		$fc->setConstraint('site_id', $this->getParam('site_id')); 
		$this->set('feed_counts', $fc->generate());
		
		// summary trend
		$f = owa_coreAPI::metricFactory('base.feedViewsTrend');
		$f->setPeriod($this->getPeriod());
		$f->setConstraint('site_id', $this->getParam('site_id')); 
		$f->setOrder('DESC');
		$feed_trend = $f->generate();
		$this->set('feed_trend', $feed_trend);
		
		// trend chart
		$series = owa_lib::deconstruct_assoc($feed_trend);
		$cd = owa_coreAPI::supportClassFactory('base', 'chartData');
		$cd->setSeries('x', owa_lib::makeDateArray($feed_trend, "n/j"), 'Day');
		$cd->setSeries('area', $series['fetch_counts'], 'Fetch Counts');
		$chart = owa_coreAPI::supportClassFactory('base', 'ofc');
		$json = $chart->area($cd);
		$this->set('feed_chart_data', $json);
			
		// view stuff
		$this->setView('base.report');
		$this->setSubview('base.reportFeeds');
		$this->setTitle('Feeds');
		
		return;
		
	}
	
}

/**
 * Feeds Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportFeedsView extends owa_view {
	
	function owa_reportFeedsView() {
		
		return owa_reportFeedsView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
	
		// Assign Data to templates
	
		$this->body->set('feed_trend', $this->get('feed_trend'));
		$this->body->set('feed_counts', $this->get('feed_counts'));
		$this->body->set('feed_chart_data', $this->get('feed_chart_data'));
		$this->body->set_template('report_feeds.tpl');

		return;
	}
	
	
}


?>