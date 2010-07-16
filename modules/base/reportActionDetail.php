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
 * Action Detail Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_reportActionDetailController extends owa_reportController {

	function action() {
	
	
		$actionName = $this->getParam('actionName');
		
		$this->setSubview('base.reportDimensionDetail');
		$this->setTitle('Action Detail: ', $actionName);
		$this->set('metrics', 'actions,actionsValue');
		$this->set('dimension', 'actionName');
		$this->set('trendChartMetric', 'actions');
		$this->set('trendTitle', 'There were <%= this.d.resultSet.aggregates.actions.value %> actions performed on this web site.');
		$this->set('constraints', 'actionName=='.urlencode($actionName));	
	}
}

/**
 * Action Detail Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_reportActionDetailView extends owa_view {
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render() {
		
		$this->body->set_template('report_actionDetail.php');
		$this->body->set('aggregates', $this->get('aggregates'));	
		$this->body->set('actionName', $this->get('actionName'));	
	}
}

?>