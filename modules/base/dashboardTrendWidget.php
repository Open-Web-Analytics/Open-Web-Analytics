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
require_once(OWA_BASE_CLASSES_DIR.'owa_controller.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_view.php');

class owa_dashboardTrendWidgetController extends owa_controller {

	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function owa_dashboardTrendWidgetController($params) {
	
		return owa_dashboardTrendWidgetController::__construct($params);
	}

	function action() {
		
		$m = owa_coreApi::metricFactory('base.dashCoreByDay');
		
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setConstraint('is_browser', 1);
		$m->setPeriod($this->params['period']);
		$m->setOrder(OWA_SQL_ASCENDING); 
		
		
		if (array_key_exists('format', $this->params)):
			$format = $this->params['format'];
		else:
			$format = 'graph';
		endif;
			
		$data['title'] = 'Dashboard Trend';
		$data['params'] = $this->params;
		$data['widget'] = 'base.dashboardTrendWidget';
		
		switch ($format) {
		
			case 'graph':
				
				$data['view'] = 'base.openFlashChart';
				$data['height'] = $this->params['height'];
				$data['width'] = $this->params['width'];
				break;
				
			case 'graph-data':
				$results = $m->generate();
				$series = owa_lib::deconstruct_assoc($results);
				$data['y']['label'] = 'Page Views';
				$data['y2']['label'] = 'Visits';
				$data['x']['label'] = 'Day';
				$data['y']['series'] = $series['page_views'];
				$data['y2']['series'] = $series['sessions'];
				$data['x']['series'] = owa_lib::makeDateArray($results, "n/j");				
				$data['view'] = 'base.areaBarsFlashChart';
				break;
				
			case 'table':
				$m->setLimit(5);
				$results = $m->generate();
				$data['labels'] = $m->getLabels();
				$data['rows'] = $results;
				$data['view'] = 'base.genericTable';
				break;
				
			
		}
		
		if ($this->params['initial-view'] == true):
			$data['subview'] = $data['view'];
			$data['view'] = 'base.widget';
		endif;
		
		
		return $data;
		
	}
}


?>