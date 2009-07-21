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

require_once(OWA_BASE_CLASS_DIR.'widget.php');

/**
 * Feed Types Widget Controller
 *
 *
 */
class owa_widgetFeedReaderTypesController extends owa_widgetController {
	
	function __construct($params) {
		
		$this->setDefaultFormat('graph');
		// enable formats
		$this->enableFormat('graph');
		$this->enableFormat('table');
		
		return parent::__construct($params);
	}
	
	function owa_widgetFeedReaderTypesController($params) {
	
		return owa_widgetFeedReaderTypesController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Feed Reader Types';
		
		// set default dimensions
		$this->setHeight('200px');
		$this->setWidth('100%');
		
		//Metrics
		// feed formats
		$ff = owa_coreAPI::metricFactory('base.feedReaderTypesCount');
		$ff->setPeriod($this->getPeriod());
		$ff->setConstraint('site_id', $this->getParam('site_id')); 
		$this->setMetric('base.feedReaderTypesCount', $ff);
					
		return;
		
	}
	
	function graphAction() {
	
		$ff = $this->getMetric('base.feedReaderTypesCount');
		$ff->setLimit(5);
		$results = $ff->generate();	
		$series = owa_lib::deconstruct_assoc($results);
		$pagination = $ff->getPagination();
		
		if ($pagination['more_pages'] === true) {
			$series['count'][] = $pagination['total_count'] - $ff->getLimit();
			$series['ua'][] = 'Other';
		}
		
		// add a final slice
		$cd = owa_coreAPI::supportClassFactory('base', 'chartData');
		$cd->setSeries('values', $series['count'], 'Fetch Count');
		$cd->setSeries('labels', $series['ua'], 'Feed Formats');
		$chart = owa_coreAPI::supportClassFactory('base', 'ofc');
		$json = $chart->pie($cd);
		$this->set('chart_data', $json);
		$this->set('width', '100%');
		//$this->setHeight('300px');
		$this->setView('base.chart');
		return;
	}
	
	function tableAction() {
	
		$ff = $this->getMetric('base.feedReaderTypesCount');
		$results = $ff->generate();	
		$this->set('rows', $results);
		$this->set('labels', array('Fetch Count', 'User Agent', 'Name'));
		$this->data['view'] = 'base.genericTable';
		
		return;

	}
	
	
}

?>