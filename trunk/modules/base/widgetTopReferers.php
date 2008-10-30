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
 * Top Referers Widget Controller
 *
 *
 */
class owa_widgetTopReferersController extends owa_widgetController {
	
	function __construct($params) {
		
		$this->setDefaultFormat('table');
		
		return parent::__construct($params);
	}
	
	function owa_widgetTopReferersController($params) {
	
		return owa_widgetTopReferersController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Top Refering Web Sites';
		
		// set default dimensions
		$this->setHeight(450);
		$this->setWidth(350);
		
		// enable formats
		//$this->enableFormat('graph', 'Graph');
		$this->enableFormat('table', 'Table');
		//$this->enableFormat('sparkline', 'Sparkline');
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.topReferers');
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setConstraint('is_browser', 1);
		$m->setPeriod($this->params['period']);
		$m->setOrder(OWA_SQL_ASCENDING); 
			
		switch ($this->params['format']) {
		
			case 'graph':
				
				break;
				
			case 'graph-data':
			
				break;
				
			case 'table':
			
				// apply limit override
				if (array_key_exists('limit', $this->params)):
					$m->setLimit($this->params['limit']);
				else:
					$m->setLimit(10);	
				endif;
											
				// set page number of results
				if (array_key_exists('page', $this->params)):
					$m->setPage($this->params['page']);
				endif;
				
				$results = $m->generate();
				
				$this->data['labels'] = array('Web Page', 'Page Views');
				$this->data['rows'] = $results;
				$this->data['view'] = 'base.genericTable';
				$this->data['table_row_template'] = 'row_topReferers.tpl';
				
				// generate pagination array
				$this->data['pagination'] = $m->getPagination();
			
				//print_r($this->data['pagination']);
				break;
				
		}
		
		return;
		
	}
}


?>