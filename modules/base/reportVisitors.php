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
 * Visitors Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsController extends owa_reportController {

	function action() {

		$rs = owa_coreAPI::executeApiCommand(array(
			
			'do'				=> 'getLatestVisits',
			'siteId'			=> $this->getParam('siteId'),
			'page'				=> $this->getParam('page'),
			'startDate'			=> $this->getParam('startDate'),
			'endDate'			=> $this->getParam('endDate'),
			'period'			=> $this->getParam('period'),
			'resultsPerPage'	=> 10 ) );
		
		$this->set('latest_visits', $rs);
		
		// view stuff
		$this->setView('base.report');
		$this->setSubview('base.reportVisitors');
		$this->setTitle('Visitors');
	}
}

/**
 * Visitors Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportVisitorsView extends owa_view {
			
	function render($data) {
			
		$this->body->set_template('report_visitors.tpl');
		$this->body->set('visits', $this->get('latest_visits'));		
	}
}

?>