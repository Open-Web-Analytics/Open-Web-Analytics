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
 * Entry Exits Content Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportEntryExitsController extends owa_reportController {

	function owa_reportEntryExitsController($params) {
		
		return owa_reportEntryExitsController::__construct($params); 
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// entry pages
		$en = owa_coreAPI::metricFactory('base.topEntryPages');
		$en->setPeriod($this->getPeriod());
		$en->setConstraint('site_id', $this->getParam('site_id')); 
		$en->setLimit(20);
		$en->setOrder('DESC');
		$this->set('entry_documents', $en->generate());
		
		// exit pages
		$ex = owa_coreAPI::metricFactory('base.topExitPages');
		$ex->setPeriod($this->getPeriod());
		$ex->setConstraint('site_id', $this->getParam('site_id')); 
		$ex->setLimit(20);
		$ex->setOrder('DESC');
		$this->set('exit_documents', $ex->generate());
		
		// summary stats
		$s = owa_coreAPI::metricFactory('base.dashCounts');
		$s->setPeriod($this->getPeriod());
		$s->setConstraint('site_id', $this->getParam('site_id')); 
		$this->set('summary_stats_data', $s->generate());

		// view stuff
		$this->setView('base.report');
		$this->setSubview('base.reportEntryExits');
		$this->setTitle('Entry & Exit Pages');
		return;
	}
	
}

/**
 * Entry Exits Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportEntryExitsView extends owa_view {
	
	function owa_reportEntryExitsView() {
	
		return owa_reportEntryExitsView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign Data to templates
		$this->body->set('top_entry_pages', $this->get('entry_documents'));
		$this->body->set('top_exit_pages', $this->get('exit_documents'));
		$this->body->set('summary_stats', $this->get('summary_stats_data'));
		$this->body->set_template('report_entry_exits.tpl');
		
		return;
	}

}

?>
