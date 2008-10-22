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
		$this->data['title'] = 'Dashboard Trend';
		
		// set default dimensions
		$this->setHeight(300);
		$this->setWidth(800);
		
		// enable formats
		$this->enableFormat('graph', 'Graph');
		$this->enableFormat('table', 'Table');
		$this->enableFormat('sparkline', 'Sparkline');
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.dashCoreByDay');
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setConstraint('is_browser', 1);
		$m->setPeriod($this->params['period']);
		$m->setOrder(OWA_SQL_ASCENDING); 
			
		switch ($this->params['format']) {
		
			case 'graph':
				
				$this->data['view'] = 'base.openFlashChart';
				break;
				
			case 'graph-data':
			
				$results = $m->generate();
				$series = owa_lib::deconstruct_assoc($results);
				$this->data['y']['label'] = 'Page Views';
				$this->data['y2']['label'] = 'Visits';
				$this->data['x']['label'] = 'Day';
				$this->data['y']['series'] = $series['page_views'];
				$this->data['y2']['series'] = $series['sessions'];
				$this->data['x']['series'] = owa_lib::makeDateArray($results, "n/j");				
				$this->data['view'] = 'base.areaBarsFlashChart';
				break;
				
			case 'table':
			
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
				break;
				
			case 'sparkline':
			
				$this->data['type'] = 'line';
				$this->data['view'] = 'base.sparkline';			
				break;
			case 'sparkline-image':
				
				$this->data['view'] = 'base.sparklineLineGraph';
				break;		
		}
		
		return;
		
	}
}


?>