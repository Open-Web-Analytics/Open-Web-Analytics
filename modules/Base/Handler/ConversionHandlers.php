<?php
namespace OWA\Module\Base\Handler;


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
 * Conversion Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ConversionHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {
    
        $update = false;

        $conversion_info = $this->checkForConversion( $event );

        // check for conversion
        if ( $conversion_info ) {

            // check for needed session_id
            if ( $event->get('session_id') ) {

                   // load session
                $s = \OWA\Core\CoreAPI::entityFactory('base.session');

                $s->load( $event->get( 'session_id' ), 'id', \OWA\Core\Db::factDateConstraint( $event->get('yyyymmdd') ) );

                // if session exists
                if ( $s->wasPersisted() ) {

                    //record conversion
                    if ( !empty( $conversion_info['conversion'] ) ) {
                        $goal_column = 'goal_'.$conversion_info['conversion'];
                        $already = $s->get( $goal_column );
                        // see if an existing value has been set goal value
                        $goal_value_column = 'goal_'.$conversion_info['conversion'].'_value';
                        $existing_value = $s->get( $goal_value_column );
                        $value = $conversion_info['value'];

                        // determin is we have a conversion event worth updating
                        // only record one goal of a particular type per session
                        if ( $already != true )    {
                            // there is a goal conversion
                            $s->set( $goal_column , true );
                            $update = true;
                            \OWA\Core\CoreAPI::debug( "$goal_column was achieved." );
                        } else {
                            // goal already happened but check to see if we need to add a value to it.
                            // happens in the case of ecommerce transaction where the value
                            // can come in a secondary request. if no value then return.
                            if ( ! $value ) {

                                \OWA\Core\CoreAPI::debug( 'Not updating session. Goal was already achieved and in same session.' );

                                return OWA_EHS_EVENT_HANDLED;
                            }
                        }

                        // Allow a value to be set if one has not be set already.
                        // this is needed to support dynamic values passed by commerce transaction events
                        if ( $value  && ! $existing_value )  {
                            $s->set( $goal_value_column, \OWA\Core\Lib::prepareCurrencyValue( $value ) );
                            $update = true;
                        }
                    }
                    //record goal start
                    if ( !empty($conversion_info['start'] ) ) {
                        $goal_start_column = 'goal_'.$conversion_info['start'].'_start';
                        $already_started = $s->get( $goal_start_column );

                        if ( $already_started != true ) {

                            $s->set( $goal_start_column, true );
                            $update = true;
                            \OWA\Core\CoreAPI::debug( "$goal_start_column was started." );

                        } else {
                            \OWA\Core\CoreAPI::debug( "$goal_start_column was already started." );
                        }
                    }

                    //update object
                    if ( $update ) {

                        // summarize goal conversions
                        $s->set('num_goals', $this->countGoalConversions($s));

                        // summarize goal conversion value
                        $s->set('goals_value', $this->sumGoalValues($s));

                        // summarize goal starts
                        $s->set('num_goal_starts', $this->countGoalStarts($s));

                        $ret = $s->update();
                        if ( $ret ) {
                            // create a new_conversion event so that the total conversion
                            // metrics can be resummarized
                            $this->dispatchNewConversionEvent($event);

                            return OWA_EHS_EVENT_HANDLED;
                        } else {

                            return OWA_EHS_EVENT_FAILED;
                        }

                    } else {
                        \OWA\Core\CoreAPI::debug( "nothing about this conversion is worth updating." );

                        return OWA_EHS_EVENT_HANDLED;
                    }

                } else {
                    \OWA\Core\CoreAPI::debug("Conversion processing aborted. No session could be found.");

                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                \OWA\Core\CoreAPI::notice('Not persisting conversion. Session id missing from event.');

                return OWA_EHS_EVENT_HANDLED;
            }

        } else {
            \OWA\Core\CoreAPI::debug('No goal start or conversion detected.');

            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    // create a new_conversion event so that the total conversion
    // metrics can be resummarized
    function dispatchNewConversionEvent($event) {
    
        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();
        $ce = $dispatch->makeEvent( 'new_conversion' );
        $ce->set( 'session_id', $event->get( 'session_id' ) );
        $dispatch->asyncNotify( $ce );
    }
        
    /**
     * The site's active goals. Extracted so the conversion logic can be
     * exercised without a goal manager or a database behind it.
     *
     * @param    string    $siteId
     * @return    array
     */
    protected function getActiveGoals( $siteId ) {

        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);

        return $gm->getActiveGoals();
    }

    function checkForConversion($event) {
    
        $goal_info = array('conversion' => '', 'value' => '', 'start' => '');
        $siteId = $event->get('siteId');

        if ( ! $siteId ) {
            $siteId = $event->get('site_id');
        }

        $goals = $this->getActiveGoals( $siteId );
        \OWA\Core\CoreAPI::debug('active goals: '.print_r($goals, true));
        if (empty($goals)) {
            return;
        }

        $is_match = false;

        $start = '';

        foreach ($goals as $num => $goal) {

            if (!empty($goal)) {

                if (array_key_exists('goal_status', $goal) && $goal['goal_status'] === 'active') {
                    // Reset per goal. These once persisted across
                    // iterations, so a goal carrying no value of its own
                    // inherited the previous goal's, and a goal whose type
                    // matched no case kept the previous goal's match.
                    $match = '';
                    $start = '';
                    $goal_value = '';

                    /*
                     * Evaluated from the goal event's CONDITIONS, not from a
                     * single url_destination triple.
                     *
                     * The switch had exactly one case, so a goal of any other
                     * type silently never converted -- this install has had one
                     * in that state since it was made. Conditions are rows now,
                     * there can be several, and they combine with all or any,
                     * so there is no type to switch on: a goal event either
                     * describes the event in front of us or it does not.
                     */
                    $match = $this->checkGoalEventConditions( $event, $siteId, $num );
                    $start = $this->checkGoalEventStart( $event, $siteId, $num );

                    if ($start) {
                        $goal_info['start'] = $start;
                    }

                    if ( ! $match ) {

                        continue;
                    }

                    $goal_info['conversion'] = $match;

                    //check for dynamic value from commerce transaction

                    if ($event->get('ct_total')) {
                        $goal_value =  $event->get('ct_total');
                    } else {
                        // else just use the static value if one is set.
                        if ( array_key_exists('goal_value', $goal) ) {
                            $goal_value = $goal['goal_value'];
                        }
                    }

                    // Only the converting goal contributes a value. This was
                    // previously assigned every iteration, so the value
                    // reported belonged to whichever goal came last.
                    $goal_info['value'] = $goal_value;
                } else {
                    \OWA\Core\CoreAPI::debug("Goal $num not active.");
                }
            }
        }
        \OWA\Core\CoreAPI::debug('conversion info: '.print_r($goal_info, true));
        return $goal_info;
    }
    
    /**
     * Does this event satisfy the goal event in one slot?
     *
     * @return string  the slot number when it matches, '' when it does not --
     *                 the shape the caller already had, where a slot number is
     *                 truthy and no match is empty.
     */
    protected function checkGoalEventConditions( $event, $siteId, $number ) {

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->load( \OWA\Module\Base\Classes\GoalManager::goalEventIdFor( $siteId, $number ) );

        if ( ! $goalEvent->wasPersisted() ) {

            return '';
        }

        return $goalEvent->matchesEvent( $event ) ? $number : '';
    }

    /**
     * Did this event BEGIN the goal event in one slot?
     *
     * From the goal event's own START condition, not from the first step of its
     * funnel.
     *
     * Reading funnel step 1 tied an ingest-time decision to a reporting
     * artefact: goal_N_start is written at collection, so a funnel could not
     * become a read-time report widget without silently taking the seven
     * goalNStarts metrics with it. "Began this" is a fact about the goal event,
     * and is stated as one.
     *
     * @return string  the slot number when it started, '' otherwise.
     */
    protected function checkGoalEventStart( $event, $siteId, $number ) {

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->load( \OWA\Module\Base\Classes\GoalManager::goalEventIdFor( $siteId, $number ) );

        if ( ! $goalEvent->wasPersisted() ) {

            return '';
        }

        return $goalEvent->startedByEvent( $event ) ? $number : '';
    }

    function checkUrlDestinationGoal($event, $goal) {
        $match = '';
        $page_uri = $event->get('page_uri');

        switch ($goal['details']['match_type']) {

            case 'exact':

                if ( $page_uri === $goal['details']['goal_url'] ) {
                    $match = $goal['goal_number'];
                }
                break;

            case 'begins':

                $length = strlen( $goal['details']['goal_url'] );
                $check = strpos( $page_uri, $goal['details']['goal_url']);
                if ( $check === 0 ) {
                    $match = $goal['goal_number'];
                }
                break;

            case 'regex':

                $pattern = sprintf('@%s@i', $goal['details']['goal_url']);
                $check = preg_match( $pattern, $page_uri );
                if ( $check > 0 ) {
                    $match = $goal['goal_number'];
                }
                break;
        }

        return $match;
    }
    
    function countGoalConversions($session) {

        $num = \OWA\Core\CoreAPI::getSetting('base', 'numGoals');
        $count = 0;
        for ($i = 0;$i < $num;$i++) {
            $col_name = 'goal_'.$i;
            $count = $count + $session->get($col_name);

        }
        \OWA\Core\CoreAPI::debug('session total goal count: '.$count);
        return $count;
    }

    function countGoalStarts($session) {

        $num = \OWA\Core\CoreAPI::getSetting('base', 'numGoals');
        $count = 0;
        for ($i = 0;$i < $num;$i++) {
            $col_name = 'goal_'.$i.'_start';
            $count = $count + $session->get($col_name);
        }
        \OWA\Core\CoreAPI::debug('session total goal starts: '.$count);
        return $count;
    }
    
    function sumGoalValues($session) {

        $num = \OWA\Core\CoreAPI::getSetting('base', 'numGoals');
        $sum = 0;
        for ($i = 0;$i < $num;$i++) {
            $col_name = 'goal_'.$i.'_value';
            $sum = $sum + $session->get($col_name);
        }
        \OWA\Core\CoreAPI::debug('session total goal value: '.$sum);
        return $sum;
    }
}

?>