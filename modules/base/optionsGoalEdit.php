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

require_once(OWA_DIR.'owa_view.php');
require_once(OWA_DIR.'owa_adminController.php');

/**
 * Goals Edit Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_optionsGoalEditController extends owa_adminController {
	
	function __construct($params) {
	
		parent::__construct($params);
		$this->type = 'options';
		$this->setRequiredCapability('edit_settings');
		$this->setNonceRequired();
		$goal = $this->getParam('goal');
		// check that goal number is present
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($goal['goal_number']);
		$this->setValidation('goal_number', $v1);
		
		// check that goal name is present
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($goal['goal_value']);
		$this->setValidation('goal_name', $v1);
		
		// check that goal status is present
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($goal['goal_status']);
		$this->setValidation('goal_status', $v1);
		
		// check that goal type is present
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($goal['goal_type']);
		$this->setValidation('goal_type', $v1);
		
		if ($goal['goal_type'] === 'url_destination') {
			// check that match_type is present
			$v1 = owa_coreAPI::validationFactory('required');
			$v1->setValues($goal['details']['match_type']);
			$this->setValidation('match_type', $v1);
			
			// check that goal_url is present
			$v1 = owa_coreAPI::validationFactory('required');
			$v1->setValues($goal['details']['goal_url']);
			$this->setValidation('goal_url', $v1);		
		}
		
		$steps = $goal['details']['funnel_steps'];
		
		if ($steps) {
			
			foreach ($steps as $num => $step) {
				
				if (!empty($step['name']) || !empty($step['url'])) { 
					// check that step name is present
					$v1 = owa_coreAPI::validationFactory('required');
					$v1->setValues($step['name']);
					$this->setValidation('step_name_'.$num, $v1);	
					
					// check that step url is present
					$v1 = owa_coreAPI::validationFactory('required');
					$v1->setValues($step['url']);
					$this->setValidation('step_url_'.$num, $v1);	
					
					// check that step is_required is present
					$v1 = owa_coreAPI::validationFactory('required');
					$v1->setValues($step['is_required']);
					$this->setValidation('step_is_required_'.$num, $v1);
				}
				
				$check = owa_lib::array_values_assoc($step);
				if (!empty($check)) {
					$step['step_number'] = $num;
					$this->params['goal']['details']['funnel_steps'][$num] = $step;
				} else {
					// remove the array as it only contains empty values.
					// this can happen when the use adds a step but does not fill in any
					// values.
					unset( $this->params['goal']['details']['funnel_steps'][$num] ); 
				}				
			}
		}
	}
	
	function action() {
	
		$all_goals = owa_coreAPI::getSetting('base', 'goals');
		$all_goals[$goal['goal_number']] = $goal;
		owa_coreAPI::debug('New goals: '.print_r($all_goals,true));
		owa_coreAPI::persistSetting('base', 'goals', $all_goals);	
		$this->setRedirectAction('base.optionsGoals');
	}
	
	function errorAction() {
		$goal = $this->getParam('goal');
		$this->setView('base.options');
		$this->setSubview('base.optionsGoalEntry');
		$this->set('error_code', 3311);
		$this->set('goal', $goal);
		$this->set('goal_number', $goal['goal_number']);
	}
}

?>