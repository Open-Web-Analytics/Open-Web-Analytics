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

/**
 * Goal Manager
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */


class owa_goalManager extends owa_base {

	var $goals;
	var $activeGoals;
	var $goal_group_labels;
	var $activeGoalGroups;
	var $activeGoalsByGroup;
	
	/**
	 * Constructor
	 * 
	 * Takes cache directory as param
	 *
	 * @param $cache_dir string
	 */
	function __construct() {
	
		$this->loadGoals();
	}
	
	function loadGoals() {
		
		$this->goals = owa_coreAPI::getSetting('base', 'goals');
		$this->goal_group_labels = owa_coreAPI::getSetting('base', 'goal_groups');
		
		foreach ($this->goals as $goal) {
			
			// set active goal lists
			if (array_key_exists('goal_status', $goal) && $goal['goal_status'] === 'active') {
				// set active goals
				$this->activeGoals[] = $goal['goal_number'];
				// set active goal groups
				if (array_key_exists('goal_group', $goal)) {
					$this->activeGoalGroups[$goal['goal_group']] = $goal['goal_group'];
					// set active goals by group
					$this->activeGoalsByGroup[$goal['goal_group']][] = $goal['goal_number'];
				}			
			}
		}
	}
	
	function getActiveGoals() {
	
		return $this->activeGoals;
	}
	
	function getActiveGoalGroups() {
	
		return $this->activeGoalGroups;
	}
	
	function getActiveGoalsByGroup($group_number) {
		print_r($this->activeGoalsByGroup);
		return $this->activeGoalsByGroup[$group_number];
	}
	
	function getGoal($number) {
		
		if ( array_key_exists( $number, $this->goals ) ) {
			
			return $this->goals[$number];
		}
	}
	
	function getGoalGroupLabel($number) {
		
		if ( array_key_exists( $number, $this->goal_group_labels ) ) {
		
			return $this->goal_group_labels[$number];
		}
	}
}

?>