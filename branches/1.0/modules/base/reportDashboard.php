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
		
		$data = array();
		$data['params'] = $this->params;
		
		switch ($this->params['period']) {

			case "this_year":
				$data['core_metrics_data'] = $api->getMetric('base.dashCoreByDay', array(
				
					'constraints'		=> array('site_id'	=> $this->params['site_id']),
					'groupby'			=> array('month')
				
				));
				
			break;
			
			default:
				$data['core_metrics_data'] = $api->getMetric('base.dashCoreByDay', array(
			
					'constraints'		=> array('site_id'	=> $this->params['site_id']),
					'groupby'			=> array('day')
				
				));
			break;
		}
		
		$data['summary_stats_data'] = $api->getMetric('base.dashCounts', array(
		
			'result_format'		=> 'single_row',
			'constraints'		=> array('site_id'	=> $this->params['site_id'])
		
		));
		
		//print_r($data['summary_stats_data'] );

		$data['latest_visits'] = $api->getMetric('base.latestVisits', array(
		
			'constraints'	=> array('site_id'	=> $this->params['site_id']),
			'limit'			=> 15,
			'orderby'		=> array('session.timestamp'),
			'order'			=> 'DESC'
		
		));
		
		$data['top_pages_data'] = $api->getMetric('base.topPages', array(
			
			'constraints'		=> array('site_id'	=> $this->params['site_id']),
			'limit'			=> '10'
		));
		
		$data['top_referers_data'] = $api->getMetric('base.topReferers', array(
			
			'limit'				=> '10',
			'constraints'		=> array(
				'site_id'	=> $this->params['site_id'],
				'referers.is_searchengine' => '0'),
			'order'				=>	'DESC'
		));
		
		
		
		$data['view'] = 'base.report';
		$data['subview'] = 'base.reportDashboard';
		$data['nav_tab'] = 'base.reportDashboard';	
		
		return $data;	
		
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
		
		// Set Page title
		$this->t->set('page_title', '');
		
		// Set Page headline
		$this->body->set('headline', 'Dashboard');
		
		// load body template
		$this->body->set_template('report_dashboard.tpl');
	
		$this->body->set('headline', 'Analytics Dashboard');
		
		
		$this->body->set('summary_stats', $data['summary_stats_data']);
		
		$this->body->set('config', $this->config);
		
		$this->body->set('params', $data['params']);
		
		$this->body->set('core_metrics', $data['core_metrics_data']);
		
		
		
		$this->body->set('visits', $data['latest_visits']);
		
		$this->body->set('top_pages', $data['top_pages_data']);
		
		$this->body->set('top_referers', $data['top_referers_data']);

		return;
	}
	
	
}


?>