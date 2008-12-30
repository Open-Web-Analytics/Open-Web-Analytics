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
 * Visitor Types Widget Controller
 *
 *
 */
class owa_widgetVisitorTypesController extends owa_widgetController {
	
	function __construct($params) {
		
		$this->setDefaultFormat('graph');
		// enable formats
		$this->enableFormat('graph');
		$this->enableFormat('table');
		
		return parent::__construct($params);
	}
	
	function owa_widgetVisitorTypesController($params) {
	
		return owa_widgetVisitorTypesController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Visitor Types';
		
		// set default dimensions
		$this->setHeight('');
		$this->setWidth('');
		
		$this->data['labels'] = array('New', 'Repeat');
		//Metrics
		$m = owa_coreApi::metricFactory('base.visitorTypesCount');
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setPeriod($this->getPeriod());
		$this->setMetric('base.visitorTypesCount', $m);
					
		return;
		
	}
	
	function graphAction() {
	
		$m = $this->getMetric('base.visitorTypesCount');	
		$m->setLimit(5);
		$results = $m->generate();	
		$cd = owa_coreAPI::supportClassFactory('base', 'chartData');
		$cd->setSeries('values', array($results['new_visitor'], $results['repeat_visitor']), 'Visitors');
		$cd->setSeries('labels', array('New', 'Repeat'), 'Visitor Types');
		$chart = owa_coreAPI::supportClassFactory('base', 'ofc');
		$json = $chart->pie($cd);
		$this->set('chart_data', $json);
		//$this->set('width', '100%');
		//$this->setHeight('300px');
		$this->setView('base.chart');
		return;
	
	}
	
	function tableAction() {
	
		$m = $this->getMetric('base.visitorTypesCount');
		$results = $m->generate();	
		$rows = array();
		$rows[] =  array('New', $results['new_visitor']);
		$rows[] =  array('Repeat', $results['repeat_visitor']);
		$this->data['rows'] = $rows;
		$this->data['view'] = 'base.genericTable';
		
		return;

	}
	
	
}

?>