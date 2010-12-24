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

require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Dashboard Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDashboardController extends owa_reportController {
	
	function action() {
				
		// action counts	
		$params = array('period' 	  => $this->get('period'),
						'startDate'	  => $this->get('startDate'),
						'endDate'	  => $this->get('endDate'),
						'metrics' 	  => 'actions',
						'dimensions'  => 'actionName',
						'siteId' 	  => $this->getParam('siteId'),
						'do'		  => 'getResultSet'
						);
						
		$rs = owa_coreAPI::executeApiCommand($params);	
		//print_r($rs);			
		$this->set('actions', $rs);
		
		$rs = owa_coreAPI::executeApiCommand(array(
			
			'do'				=> 'getLatestVisits',
			'siteId'			=> $this->getParam('siteId'),
			'page'				=> $this->getParam('page'),
			'startDate'			=> $this->getParam('startDate'),
			'endDate'			=> $this->getParam('endDate'),
			'period'			=> $this->getParam('period'),
			'resultsPerPage'	=> 10
		));
		
		$this->set('latest_visits', $rs);
	
		// set view stuff
		$this->setSubview('base.reportDashboard');
		$this->setTitle('Dashboard');
		
		$metrics = 'visits,uniqueVisitors,pageViews,bounceRate,pagesPerVisit,visitDuration';
		
		if ( owa_coreAPI::getSiteSetting( $this->getParam('siteId'), 'enableEcommerceReporting') ) {
			$metrics .= ',transactions,transactionRevenue';	
		}
		
		$this->set('metrics', $metrics);	
	}
}
		
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Dashboard Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportDashboardView extends owa_view {
	
	function render() {
		
		$this->body->set_template('report_dashboard.tpl');
		$this->body->set('summary', $this->get('summary'));			
		$this->body->set('site_trend', $this->get('site_trend'));
		$this->body->set('visits', $this->get('latest_visits'));
		$this->body->set('actions', $this->get('actions'));
		$this->body->set('metrics', $this->get('metrics'));
	}
}

?>