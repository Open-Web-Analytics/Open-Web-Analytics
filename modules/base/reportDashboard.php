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
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
	
		return;
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$this->data['params'] = $this->params;
		
		// dash counts	
		$d = owa_coreAPI::metricFactory('base.dashCounts');
		$d->setConstraint('site_id', $this->params['site_id']); 
		$this->data['summary_stats_data'] = $d->generate();
		//print_r($this->data['summary_stats_data']);
		// Latest Visits	
		$lv = owa_coreAPI::metricFactory('base.latestVisits');
		$lv->setConstraint('site_id', $this->params['site_id']);
		$lv->setLimit(15);
		$lv->setOrder(OWA_SQL_DESCENDING); 
		
		if (array_key_exists('page', $this->params)):
			$lv->setPage($this->params['page']);
		endif;
		
		$this->data['latest_visits'] = $lv->generate();
		
		$this->data['pagination'] = $lv->getPagination();
		//print_r($this->data['pagination']);
		
		
		// Counts
		$s = owa_coreAPI::metricFactory('base.sessionsCount');
		$s->setConstraint('site_id', $this->params['site_id']);
		$s->setPeriod($this->params['period']);
		$this->data['sessions_count'] = $s->generate();
		
		// Counts
		$st = owa_coreAPI::metricFactory('base.sessionsTrend');
		$st->setConstraint('site_id', $this->params['site_id']);
		$st->setPeriod('this_year');
		$this->data['sessions_trend'] = $st->generate();
		
		$this->data['view'] = 'base.report';
		$this->data['subview'] = 'base.reportDashboard';
		$this->data['nav_tab'] = 'base.reportDashboard';	
		$this->data['headline'] = 'Analytics Dashboard';	
		return $this->data;	
		
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
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		// Set Page headline
		
		// load body template
		$this->body->set_template('report_dashboard.tpl');
	
		$this->body->set('summary_stats', $data['summary_stats_data']);
		
		$this->body->set('config', $this->config);
		
		$this->body->set('params', $data['params']);
				
		$this->body->set('visits', $data['latest_visits']);
		
		$this->body->set('sessions_count', $data['sessions_count']);
		
		$this->body->set('sessions_trend', $data['sessions_trend']);
		
		$this->body->set('pagination', $data['pagination']);
		
		$this->setJs("owa.widgets.js");
		$this->setCss("owa.widgets.css");
		
		//$this->setJs('includes/json2.js');
		//$this->setJs('includes/swfobject.js');

		return;
	}
	
	
}


?>