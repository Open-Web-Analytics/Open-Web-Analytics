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
 * Visits Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_reportVisitsController extends owa_reportController {
		
	function action() {
		
		$visitorId = $this->getParam('visitorId');
		
		if (!$visitorId) {
			$visitorId = $this->getParam('visitor_id');
		}
		
		$v = owa_coreAPI::entityFactory('base.visitor');
		$v->load($visitorId);
		
		if ($this->getParam('date')) {
			$startDate = $this->getParam('date');
			$endDate = $this->getParam('date');
		}
				
		$rs = owa_coreAPI::executeApiCommand(array(
			
			'do'			=> 'getLatestVisits',
			'visitorId'		=> $visitorId,
			'siteId'		=> $this->getParam('siteId'),
			'page'			=> $this->getParam('page'),
			'startDate'		=> $startDate,
			'endDate'		=> $endDate,		
			'format'		=> '' ) );
		
		$this->set('visits', $rs);
		$this->set('visitor', $v);
		$this->set('visitor_id', $visitorId);
		$this->setView('base.report');
		$this->setSubview('base.reportVisits');
		$this->setTitle('Visit History For: ', $v->getVisitorName());	
	}
}

/**
 * Visits Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_reportVisitsView extends owa_view {
			
	function render() {
		
		$this->body->set_template('report_visits.php');	
		$this->body->set('visitor_id', $this->get('visitor_id'));
		$this->body->set('visits', $this->get('visits'));
		$this->body->set('visitor', $this->get('visitor'));
	}
}

?>