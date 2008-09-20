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
require_once(OWA_BASE_CLASSES_DIR.'owa_view.php');

class owa_dashboardTrendWidgetController extends owa_widgetController {

	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function owa_dashboardTrendWidgetController($params) {
	
		return owa_dashboardTrendWidgetController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Dashboard Trend';
		
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
				$this->data['height'] = $this->params['height'];
				$this->data['width'] = $this->params['width'];
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
			
				$m->setLimit(5);
				$results = $m->generate();
				$this->data['labels'] = $m->getLabels();
				$this->data['rows'] = $results;
				$this->data['view'] = 'base.genericTable';
				break;
					
		}
		
		return;
		
	}
}


?>