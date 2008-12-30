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
 * Anchortext Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportAnchortextController extends owa_reportController {
	
	function owa_reportAnchortextController($params) {
				
		return owa_reportAnchortextController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
			
		// top referers
		$a = owa_coreAPI::metricFactory('base.topReferingAnchors');
		$a->setPeriod($this->getPeriod());
		$a->setConstraint('site_id', $this->getParam('site_id')); 
		$a->setLimit(30);
		$a->setPage($this->get('page'));
		$this->set('top_anchors', $a->generate());
		$this->set('pagination', $a->getPagination());

		// summary stats
		$s = owa_coreAPI::metricFactory('base.dashCountsTraffic');
		$s->setPeriod($this->getPeriod());
		$s->setConstraint('site_id', $this->getParam('site_id')); 
		$s->setConstraint('referer.is_searchengine', true, '!=');
		$s->setConstraint('session.source', '', '='); 
		$s->setConstraint('session.referer_id', '0', '!='); 
		$this->set('summary_stats_data', $s->generate());
		
		$this->setView('base.report');
		$this->setSubview('base.reportAnchortext');
		$this->setTitle('Links');
		return;
		
	}
}


/**
 *  Anchortext Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportAnchortextView extends owa_view {
	
	function owa_reportAnchortextView() {
		
		$this->owa_view();
		$this->priviledge_level = 'view';
		
		return;
	}
	
	function construct($data) {
		
		// Assign Data to templates
		
		$this->body->set('headline', 'Inbound Link Text');
		
		$this->body->set('anchors', $data['top_anchors']);
		$this->body->set('summary_stats', $data['summary_stats_data']);
	
		
		$this->body->set_template('report_anchortext.tpl');
		
		return;
	}
	
	
}


?>