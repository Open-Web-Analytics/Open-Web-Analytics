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
 * @version        $Revision$
 * @since        owa 1.4.0
 */


class owa_goalManager extends owa_base {

    var $goals;
    var $activeGoals;
    var $goal_group_labels;
    var $activeGoalGroups;
    var $activeGoalsByGroup;
    var $site_id;
    var $numGoals;
    var $numGoalGroups;
    var $isDirtyGoals;
    var $isDirtyGoalGroups;

    /**
     * Constructor
     *
     * Takes cache directory as param
     *
     * @param $cache_dir string
     */
    function __construct( $site_id ) {

        $this->site_id = $site_id;
        $this->numGoals = owa_coreAPI::getSetting('base', 'numGoals');
        $this->numGoalGroups = owa_coreAPI::getSetting('base', 'numGoalGroups');
        $this->loadGoals( $site_id );
        $this->loadGoalGroupLabels ( $site_id );
    }

    function setSiteId( $site_id ) {

        $this->site_id = $site_id;
    }

    function loadGoalGroupLabels( $site_id ) {

        $this->goal_group_labels = array();
        for ( $i = 1; $i <= $this->numGoalGroups; $i++ ) {
            $this->goal_group_labels[$i] = "Goal Group $i";
        }

        $from_db = owa_coreAPI::getSiteSetting( $site_id , 'goal_groups' );

        if ($from_db) {

            foreach($from_db as $k => $goalGroup) {
                if (array_key_exists($k, $this->goal_group_labels)) {
                    $this->goal_group_labels[$k] = $goalGroup;
                }
            }
        }
    }

    function loadGoals( $site_id ) {

        $this->goals = array();

        for ( $i = 1; $i <= $this->numGoals; $i++ ) {
            $this->goals[$i] = array(
                    'goal_number'    => '',
                    'goal_name'        => '',
                    'goal_group'    => '',
                    'goal_status'    => '',
                    'goal_type'        => ''
            );
        }

        $from_db = owa_coreAPI::getSiteSetting( $site_id, 'goals' );

        if ($from_db) {

            foreach ($from_db as $k => $goal) {

                if (array_key_exists($k, $this->goals)) {
                    // add to goal array
                    $this->goals[$k] = $goal;
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
        }
    }

    function getActiveGoals() {
        if (!empty($this->activeGoals)) {
            $goals = array();
            foreach ($this->activeGoals as $goal_number) {
                $goals[$goal_number] = $this->getGoal($goal_number);
            }
            return $goals;
        }
    }

    function getAllGoals() {

        return $this->goals;
    }

    function getActiveGoalGroups() {

        return $this->activeGoalGroups;
    }

    function getActiveGoalsByGroup($group_number) {

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

    function getAllGoalGroupLabels() {

        return $this->goal_group_labels;
    }

    function saveGoal($number, $goal) {

        if ( $number <= $this->numGoals ) {

            $goal['goal_number'] = $number;
            $this->goals[$goal['goal_number']] = $goal;
            $this->isDirtyGoals = true;
        }
    }

    function saveGoalGroupLabel($number, $goal_group) {

        $this->goal_group_labels[$number] = $goal_group;
        $this->isDirtyGoalGroups = true;
    }

    function __destruct() {

        if ( $this->isDirtyGoals ) {

            owa_coreAPI::persistSiteSetting( $this->site_id, 'goals', $this->goals );
        }

        if ( $this->isDirtyGoalGroups ) {

            owa_coreAPI::persistSiteSetting( $this->site_id, 'goal_groups', $this->goal_group_labels );
        }
    }

    function getGoalFunnel($goal_number) {

        $goal = $this->getGoal($goal_number);
        if ( array_key_exists( 'details', $goal ) && array_key_exists( 'funnel_steps', $goal['details'] ) ) {
            return $goal['details']['funnel_steps'];
        }
    }
}

?>