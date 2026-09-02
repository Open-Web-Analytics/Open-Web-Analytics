<?php
namespace OWA\Module\Base\Classes;


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


class GoalManager extends \OWA\Core\Base {

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
        $this->numGoals = \OWA\Core\CoreAPI::getSetting('base', 'numGoals');
        $this->numGoalGroups = \OWA\Core\CoreAPI::getSetting('base', 'numGoalGroups');
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

        $from_db = \OWA\Core\CoreAPI::getSiteSetting( $site_id , 'goal_groups' );

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

        $from_db = self::loadKeyEventsAsGoals( $site_id );

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

    /**
     * This Profile's key events, in the 1.x goal shape.
     *
     * Goals were twenty numbered slots inside one serialized array -- all
     * twenty present whether used or not -- in a settings row. They are rows in
     * owa_key_event now (Update025), modelled on what v2 key events need so the
     * v2 migration reads the table rather than reinterpreting it.
     *
     * Rebuilt into the old shape here on purpose. The conversion evaluator, the
     * goals reports and the goal metrics all speak numbered goals, and changing
     * where a goal is STORED should not also be a rewrite of everything that
     * reads one -- that would have made a single change impossible to review.
     *
     * @return array  goal_number => goal array
     */
    public static function loadKeyEventsAsGoals( $site_id ) {

        if ( ! $site_id ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'site_id', $site_id );

        $goals = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );
            $keyEvent->setProperties( $row );

            $number = (int) $row['goal_number'];

            /*
             * A key event with no slot is skipped HERE and only here. It is a
             * real key event -- created after the twenty slots stopped being
             * the limit -- but it has no numbered metric to report through, so
             * the 1.x goal surface has nothing to say about it. The new screen
             * reads the table directly and shows all of them.
             */
            if ( $number < 1 ) {

                continue;
            }

            $goals[ $number ] = $keyEvent->toGoalArray();
        }

        return $goals;
    }

    /**
     * The stable id for one Profile's slot.
     *
     * Derived rather than minted so the migration, this class and the admin
     * screens all name the same row for the same goal -- and so re-running the
     * migration updates rather than duplicates.
     */
    public static function keyEventIdFor( $site_id, $number ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        return $entity->generateId( 'key_event:' . $site_id . ':' . (int) $number );
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

            /*
             * Which slots changed, not just that something did.
             *
             * The blob had to be rewritten whole, so two people editing
             * different goals lost one of the two edits. Rows do not, but only
             * if the write is per goal -- so the numbers are tracked.
             */
            $this->dirtyGoalNumbers[ $number ] = $number;
        }
    }

    /** Slots touched since load. @var array */
    private $dirtyGoalNumbers = array();

    /**
     * Write one goal to its key-event row.
     *
     * An upsert against a derived id, so saving a goal twice updates one row.
     */
    private function persistGoal( $number ) {

        $goal = $this->getGoal( $number );

        if ( ! is_array( $goal ) ) {

            return;
        }

        $id = self::keyEventIdFor( $this->site_id, $number );

        $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );
        $keyEvent->load( $id );

        $details = isset( $goal['details'] ) && is_array( $goal['details'] )
            ? $goal['details'] : array();

        $cents = \OWA\Module\Base\Entity\KeyEvent::decimalToCents( $goal['goal_value'] ?? '' );

        $keyEvent->set( 'site_id', $this->site_id );
        $keyEvent->set( 'name', (string) ( $goal['goal_name'] ?? '' ) );
        $keyEvent->set( 'goal_number', $number );
        $keyEvent->set( 'goal_group', (string) ( $goal['goal_group'] ?? '' ) );
        $keyEvent->set( 'is_active', ( ( $goal['goal_status'] ?? '' ) === 'active' ) ? 1 : 0 );
        $keyEvent->set( 'value', $cents === null ? 0 : $cents );
        $keyEvent->set( 'trigger_event_type',
            \OWA\Module\Base\Entity\KeyEvent::TRIGGER_PAGE_VIEW );
        $keyEvent->set( 'condition_property',
            \OWA\Module\Base\Entity\KeyEvent::PROPERTY_PAGE_URI );
        $keyEvent->set( 'condition_operator', (string) ( $details['match_type'] ?? '' ) );
        $keyEvent->set( 'condition_value', (string) ( $details['goal_url'] ?? '' ) );

        if ( $keyEvent->wasPersisted() ) {

            $keyEvent->update();

            return;
        }

        $keyEvent->set( 'id', $id );
        $keyEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $keyEvent->create();
    }

    function saveGoalGroupLabel($number, $goal_group) {

        $this->goal_group_labels[$number] = $goal_group;
        $this->isDirtyGoalGroups = true;
    }

    function __destruct() {

        if ( $this->isDirtyGoals ) {

            foreach ( $this->dirtyGoalNumbers as $number ) {

                $this->persistGoal( $number );
            }
        }

        if ( $this->isDirtyGoalGroups ) {

            \OWA\Core\CoreAPI::persistSiteSetting( $this->site_id, 'goal_groups', $this->goal_group_labels );
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