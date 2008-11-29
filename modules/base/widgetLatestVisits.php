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
 * Latest Visits Widget Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_widgetLatestVisitsController extends owa_widgetController {

	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function owa_widgetLatestVisitsController($params) {
			
		return owa_widgetLatestVisitsController::__construct($params);
	}
	
	function action() {
		
		$this->data['title'] = 'Recent Visits';
		
		$this->e->debug(sprintf("start: %s, end: %s, Now: %s", date("F j, Y, g:i:s a", $this->params['start_time']), date("F j, Y, g:i:s a"), date("F j, Y, g:i:s a", time())));
		
		$data['params'] = $this->params;
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.latestVisits');
		$m->setConstraint('site_id', $this->params['site_id']);
		$m->setPeriod($this->getPeriod());
		$m->setOrder(OWA_SQL_ASCENDING); 
		$m->setLimit(5);
		$results = $m->generate();
		$this->data['rows'] = $results;	
		$this->data['view'] = 'base.genericTable';
		$this->data['table_row_template'] = 'row_visitSummary.tpl';	
		$this->data['is_sortable'] = false;	
		return;	
		
	}
	
}

?>
