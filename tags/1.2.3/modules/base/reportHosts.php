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
 * Visitor Hosts Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportHostsController extends owa_reportController {
	
	function owa_reportHostsController($params) {
		
		return owa_reportController::__construct($params);
		
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function action() {
		
		// top hosts	
		$h = owa_coreAPI::metricFactory('base.topHosts');
		$h->setPeriod($this->getPeriod());
		$h->setConstraint('site_id', $this->getParam('site_id')); 
		$h->setLimit(15);
		$h->setPage($this->getParam('page'));
		$h->setOrder('DESC');
		$this->set('top_hosts', $h->generate());
		$this->setPagination($h->getPagination());
		
		// summary_stats_data	
		$s = owa_coreAPI::metricFactory('base.dashCounts');
		$s->setPeriod($this->getPeriod());
		$s->setConstraint('site_id', $this->getParam('site_id'));
		
		$this->set('summary_stats_data', $s->generate());
			
		$this->setSubview('base.reportHosts');
		$this->setTitle('Visiting Domains');
				
		return;
		
	}
}


/**
 * Visitor Hosts Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportHostsView extends owa_view {
	
	function owa_reportHostsView() {
		
		return owa_reportHostsView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render() {
		
		// Assign Data to templates
		$this->body->set('domains', $this->data['top_hosts']);
		$this->body->set('pagination', $this->data['pagination']);
		$this->body->set('summary_stats', $this->data['summary_stats_data']);
		$this->body->set_template('report_hosts.tpl');
		
		return;
	}
	
	
}


?>