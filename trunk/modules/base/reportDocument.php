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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Document Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDocumentController extends owa_reportController {
	
	function owa_reportDocumentController($params) {
		
		return owa_reportDocumentController::__construct($params);
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
		
		// document request trends
		$r = owa_coreAPI::metricFactory('base.requestCountsByDay');
		$period = $this->makeTimePeriod('last_thirty_days');
		$r->setPeriod($period);
		$r->setConstraint('site_id', $this->getParam('site_id')); 
		$r->setConstraint('document_id', $this->getParam('document_id')); 
		$r->setOrder('DESC');
		$core_metrics_data =  $r->generate();
		$this->set('core_metrics_data', $core_metrics_data);
		
		// document counts
		$rc = owa_coreAPI::metricFactory('base.requestCounts');
		$rc->setPeriod($this->getPeriod());
		$rc->setConstraint('site_id', $this->getParam('site_id')); 
		$rc->setConstraint('document_id', $this->getParam('document_id')); 
		$this->set('summary_stats_data', $rc->generate());
		
		// load document details
		$d = owa_coreAPI::entityFactory('base.document');
		$d->getByPk('id', $this->getParam('document_id'));
		$this->set('document_details', $d->_getProperties());
		
		// load top external referring sites
		$ref = owa_coreAPI::metricFactory('base.topReferers');
		$ref->setPeriod($this->getPeriod());
		$ref->setConstraint('site_id', $this->getParam('site_id')); 
		$ref->setConstraint('session.first_page_id', $this->getParam('document_id')); 
		$ref->setLimit(30);
		//$ref->setPage($this->getParam('page'));
		$this->set('top_referers', $ref->generate());
				
		// trend chart
		$series = owa_lib::deconstruct_assoc($core_metrics_data);
		$cd = owa_coreAPI::supportClassFactory('base', 'chartData');
		$cd->setSeries('x', owa_lib::makeDateArray($core_metrics_data, "n/j"), 'Day');
		$cd->setSeries('area', $series['page_views'], 'Page Views');
		$chart = owa_coreAPI::supportClassFactory('base', 'ofc');
		$json = $chart->area($cd);
		$this->set('chart1_data', $json);
		
		// view stuff
		$this->setView('base.report');
		$this->setSubview('base.reportDocument');
		//$this->set('document_id', $this->getParam('document_id'));
		$this->setTitle('Document Detail:');
		
		return;

		
	}

}


/**
 * Document Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDocumentView extends owa_view {
	
	function owa_reportDocumentView() {
		
		return owa_reportDocumentView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		$request_params = $this->get('params');
		
		$this->body->caller_params['link_state']['document_id'] = $this->data['params']['document_id'];
		
		// Assign data to templates
		
		$this->body->set_template('report_document.tpl');
		$this->body->set('core_metrics', $this->get('core_metrics_data'));
		$this->body->set('summary_stats',  $this->get('summary_stats_data'));
		$this->body->set('document',  $this->get('document_details'));
		$this->body->set('top_referers',  $this->get('top_referers'));
		$this->body->set('document_id', $this->data['params']['document_id']);
		$this->body->set('chart1_data',  $this->get('chart1_data'));	
		$this->body->set('chart2_data',  $this->get('chart2_data'));	
		return;
	}
	
	
}


?>