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
		//$this->setHeight(450);
		//$this->setWidth(350);
		
		$this->data['labels'] = array('New', 'Repeat');
		//Metrics
		$m = owa_coreApi::metricFactory('base.visitorTypesCount');
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setPeriod($this->getPeriod());
		$this->setMetric('base.visitorTypesCount', $m);
					
		return;
		
	}
	
	function graphAction() {
		$this->data['view'] = 'base.openFlashChart';
		return;
	}
	
	function graphDataAction() {
	
		$m = $this->getMetric('base.visitorTypesCount');
		$results = $m->generate();	
	
		$this->data['values'] = array();
		//$this->data['width'] = '100%';
		//$this->data['height'] = '100%';
		$this->data['values'] = $results;	
		$this->data['view'] = 'base.pieFlashChart';
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