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
 * Content Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportContentController extends owa_reportController {

	function owa_reportContentController($params) {
		
		return owa_reportContentController::__construct($params);
	
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
		
		// dash counts	
		$d = owa_coreAPI::metricFactory('base.dashCounts');
		$d->setPeriod($this->getPeriod());
		$d->setConstraint('site_id', $this->getParam('site_id')); 
		$d->setOrder('ASC');
		$this->set('summary_stats_data', $d->zeroFill($d->generate()));
		
		//setup Metrics
		$m = owa_coreApi::metricFactory('base.topPages');
		$m->setConstraint('site_id', $this->getParam('site_id'));
		$m->setConstraint('is_browser', 1);
		$m->setPeriod($this->getPeriod());
		$m->setPage($this->getParam('page'));
		$m->setOrder('DESC'); 
		$m->setLimit(20);
		$this->set('top_pages', $m->generate());
		$this->set('pagination', $m->getPagination());
	
		// view stuff		
		$this->setView('base.report');
		$this->setSubview('base.reportContent');
		$this->setTitle('Content');
			
		return;
		
		
	}
	
}

/**
 * Content Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportContentView extends owa_view {
	
	function owa_reportContentView() {
		
		return owa_reportContentView::__construct();
		
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		// Assign Data to templates
		
		$this->body->set('headline', 'Content');
		$this->body->set('summary_stats', $this->get('summary_stats_data'));
		$this->body->set('top_pages', $this->get('top_pages'));
		$this->body->set_template('report_content.tpl');
		
		return;
	}

}

?>
