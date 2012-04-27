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
 * Goals Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_reportGoalsController extends owa_reportController {
	
	function action() {
		
		$this->setSubview('base.reportGoals');
		$this->setTitle('Goals');
		$this->set('metrics', 'visits,goalCompletionsAll,goalConversionRateAll,goalAbandonRateAll,goalValueAll');
		$this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.goalCompletionsAll.formatted_value *> goals completed.');
		$this->set('trendChartMetric', 'goalCompletionsAll');
		
		$gm = owa_coreAPI::supportClassFactory('base', 'goalManager', $this->getParam( 'siteId' ) );
    	$goals = $gm->getActiveGoals();
    	
    	if ($goals) {
	    	$goal_metrics = '';
	    	$goal_count = count($goals);
	    	$i = 1;
	    	foreach ($goals as $goal) {
	    		$goal_metrics .= 'goal'.$goal['goal_number'].'Completions';
	    		
	    		if ($i < $goal_count) {
		  	  		$goal_metrics .= ',';
	    		}
	    		$i++;
	    	}
    	}
    	$this->set('goal_metrics', $goal_metrics);	
	}
}

/**
 * Goal Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_reportGoalsView extends owa_view {
		
	function render() {
		
		// Assign Data to templates
		$this->body->set('metrics', $this->get('metrics'));
		$this->body->set('dimensions', $this->get('dimensions'));
		$this->body->set('sort', $this->get('sort'));
		$this->body->set('resultsPerPage', $this->get('resultsPerPage'));
		$this->body->set('dimensionLink', $this->get('dimensionLink'));
		$this->body->set('trendChartMetric', $this->get('trendChartMetric'));
		$this->body->set('trendTitle', $this->get('trendTitle'));
		$this->body->set('constraints', $this->get('constraints'));
		$this->body->set('gridTitle', $this->get('gridTitle'));
		$this->body->set('hideGrid', true);
		$this->body->set('goal_metrics', $this->get('goal_metrics'));
		$this->body->set_template('report_goals.php');
	}
}

?>