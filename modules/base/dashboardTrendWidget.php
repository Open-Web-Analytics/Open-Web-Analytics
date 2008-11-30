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

require_once(OWA_BASE_CLASSES_DIR.'owa_lib.php');
require_once(OWA_BASE_CLASS_DIR.'widget.php');
require_once(OWA_BASE_CLASS_DIR.'chartData.php');

/**
 * DashBoard Trend Widget Controller
 *
 *
 */
class owa_dashboardTrendWidgetController extends owa_widgetController {
	
	function __construct($params) {
		
		$this->default_format = 'graph';
		
		return parent::__construct($params);
	}
	
	function owa_dashboardTrendWidgetController($params) {
	
		return owa_dashboardTrendWidgetController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Site Usage Trend';		
		
		// enable formats
		$this->enableFormat('graph');
		$this->enableFormat('table');
		$this->enableFormat('sparkline');
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.dashCoreByDay');
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setConstraint('is_browser', 1);
		//print_r($this->getPeriod());
		$m->setPeriod($this->getPeriod());
		$m->setOrder(OWA_SQL_ASCENDING); 
		$this->metrics['base.dashCoreByDay'] = $m;
			
		return;
	}
	
	function graphAction() {
		
		$this->data['view'] = 'base.openFlashChart';
		return;
	}
	
	function graphDataAction() {
	
		$m = $this->getMetric('base.dashCoreByDay');
		$results = $m->generate();
		$series = owa_lib::deconstruct_assoc($results);
		$cd = owa_coreAPI::supportClassFactory('base', 'chartData');
		$cd->setSeries('x', owa_lib::makeDateArray($results, "n/j"), 'Day');
		$cd->setSeries('bar', $series['page_views'], 'Page Views');
		$cd->setSeries('area', $series['sessions'], 'Visits');
		$this->set('chart_data', $cd);
		$this->data['view'] = 'base.areaBarsFlashChart';
		return;
	}	
	
	function tableAction() {
	
		$m = $this->getMetric('base.dashCoreByDay');
		// apply limit override
		if (array_key_exists('limit', $this->params)):
			$m->setLimit($this->params['limit']);
		else:
			$m->setLimit(5);	
		endif;
									
		// set page number of results
		if (array_key_exists('page', $this->params)):
			$m->setPage($this->params['page']);
		endif;
		
		$results = $m->generate();
		
		$this->data['labels'] = $m->getLabels();
		$this->data['rows'] = $results;
		$this->data['view'] = 'base.genericTable';
		
		// generate pagination array
		$this->data['pagination'] = $m->getPagination();
	
		//print_r($this->data['pagination']);
		return;
		
	}
	
	function sparklineAction() {
	
		$this->data['type'] = 'line';
		$this->data['view'] = 'base.sparkline';
		return;	
	}	
	
	function sparklineImageAction() {
	
		$this->data['view'] = 'base.sparklineLineGraph';
		return;
	}					
				
}

?>