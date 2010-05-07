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
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Action Tracking Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_reportActionTrackingController extends owa_reportController {

	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		
		// action counts	
		$params = array('period' 	  => $this->get('period'),
						'startDate'	  => $this->get('startDate'),
						'endDate'	  => $this->get('endDate'),
						'metrics' 	  => 'actions,uniqueActions,actionsPerVisit',
						'constraints' => 'site_id='.$this->getParam('site_id')
						);
						
		$rs = owa_coreAPI::getResultSet($params);	
		//print_r($rs);			
		$this->set('aggregates', $rs);
		
		// action counts	
		$params = array('period' 	  => $this->get('period'),
						'startDate'	  => $this->get('startDate'),
						'endDate'	  => $this->get('endDate'),
						'metrics' 	  => 'actions',
						'dimensions'  => 'actionName',
						'constraints' => 'site_id='.$this->getParam('site_id'),
						'sort'		  => 'actions-'
						);
						
		$rs = owa_coreAPI::getResultSet($params);	
		//print_r($rs);			
		$this->set('actionsByName', $rs);
		
		// action counts	
		$params = array('period' 	  => $this->get('period'),
						'startDate'	  => $this->get('startDate'),
						'endDate'	  => $this->get('endDate'),
						'metrics' 	  => 'actions',
						'dimensions'  => 'actionGroup',
						'constraints' => 'site_id='.$this->getParam('site_id'),
						'sort'		  => 'actions-'
						);
						
		$rs = owa_coreAPI::getResultSet($params);	
		//print_r($rs);			
		$this->set('actionsByGroup', $rs);
		
		// set view stuff
		$this->setSubview('base.reportActionTracking');
		$this->setTitle('Action Tracking');		
	}
}

/**
 * Action Tracking Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_reportActionTrackingView extends owa_view {
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render() {
		
		$this->body->set_template('report_actionTracking.php');
		$this->body->set('aggregates', $this->get('aggregates'));			
		$this->body->set('actionsByName', $this->get('actionsByName'));
		$this->body->set('actionsByGroup', $this->get('actionsByGroup'));
	}
}

?>