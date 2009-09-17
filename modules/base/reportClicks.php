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

class owa_reportClicksController extends owa_reportController {
	
	function owa_reportClicksController($params) {
		
		return owa_reportClicksController::__construct($params);
	
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	
	function action() {
		
					
		// Fetch document object
		$d = owa_coreAPI::entityFactory('base.document');
		$d->getByPk('id', $this->getParam('document_id'));
		$this->set('document_details', $d->_getProperties());
		$this->set('document_id', $this->getParam('document_id'));
		
		// Get clicks
		$c = owa_coreAPI::metricFactory('base.topClicks');
		$c->setPeriod($this->getPeriod());
		$c->setConstraint('site_id', $this->getParam('site_id')); 
		$c->setConstraint('document_id', $this->getParam('document_id'));
		$c->setConstraint('ua_id', $this->getParam('ua_id'));
		$c->setLimit(500);
		$this->set('clicks', $c->generate());
		
		// Get top user agents to populate pull-down
		$ua = owa_coreAPI::metricFactory('base.clickBrowserTypes');
		$ua->setPeriod($this->getPeriod());
		$ua->setConstraint('site_id', $this->getParam('site_id')); 
		$ua->setConstraint('document_id', $this->getParam('document_id'));
		$ua->setLimit(10);
		$this->set('uas', $ua->generate());
		
		// Get top user agents to populate pull-down
		$s = owa_coreAPI::metricFactory('base.requestCounts');
		$s->setPeriod($this->getPeriod());
		$s->setConstraint('site_id', $this->getParam('site_id')); 
		$s->setConstraint('document_id', $this->getParam('document_id'));
		$this->set('summary_stats_data', $s->generate());
		
		// view stuff		
		$this->setView('base.report');
		$this->setSubview('base.reportClicks');
		$this->setTitle('Click Analysis');
		
		return;
	}
}


/**
 * Click Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportClicksView extends owa_view {
	
	function owa_reportClicksView() {
		
		return owa_reportClicksView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign data to templates
		
		$this->body->set_template('report_clicks.tpl');
		$this->body->set('clicks', $this->get('clicks'));
		$this->body->set('uas', $this->get('uas'));
		$this->body->set('detail', $this->get('document_details'));
		$this->body->set('document_id', $this->get('document_id'));
		$this->body->set('summary_stats', $this->get('summary_stats_data'));
		$this->setJs('dynifs', 'base/js/dynifs.js');
		$this->setJs('wz_jsgraphics', 'base/js/wz_jsgraphics.js');
		
		return;
	}
	
	
}


?>