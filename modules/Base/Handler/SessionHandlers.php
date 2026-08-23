<?php
namespace OWA\Module\Base\Handler;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006-2010 Peter Adams. All rights reserved.
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
 * OWA Session Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SessionHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        // add derived params to event

        // set properties on entity

        // persist entity

        // dispatch new event based on properties of entity


        /*
         * 'is_new_session_start' marks the one REQUEST that created the
         * session. 'is_new_session' is PAGE scoped -- every event from the page
         * the session started on carries it -- so it answers a different
         * question and cannot decide create-vs-update on its own.
         *
         * The fallback is for trackers cached from before the two were split,
         * which send only the page-scoped flag. It is safe to be imprecise
         * here: logSession() now falls through to logSessionUpdate() when the
         * session already exists, so a wrong 'yes' costs a lookup rather than a
         * dropped hit.
         */
        $starts_session = $event->get('is_new_session_start');

        if ( ! $starts_session ) {
            $starts_session = $event->get('is_new_session');
        }

        if ( $starts_session ) {
            return $this->logSession($event);
        }

        return $this->logSessionUpdate($event);
    }
    
    function logSession($event) {

        if ( $event->get('session_id') ) {

            $s = \OWA\Core\CoreAPI::entityFactory('base.session');

            $s->load( $event->get('session_id'), 'id', \OWA\Core\Db::factDateConstraint( $event->get('yyyymmdd') ) );

            if ( ! $s->wasPersisted() ) {

                $s->setProperties($event->getProperties());

                // Set Primary Key
                $s->set( 'id', $event->get('session_id') );

                // set initial number of page views
                $s->set('num_pageviews', 1);
                $s->set('is_bounce', true);

                // set prior session time properties
                $s->set('prior_session_lastreq', $event->get('last_req'));

                $s->set('prior_session_id', $event->get('prior_session_id'));

                /*
                 * time_sinse_priorsession is no longer computed. It fed the
                 * timeSinceLastVisit dimension, which has been retired: a
                 * continuous seconds value gives one bucket per distinct
                 * second, so it was a metric wearing a dimension's clothes.
                 * Days bucket; seconds do not.
                 *
                 * "How long since the last visit" is now answered in days, by
                 * days_since_prior_session, derived from the two dates the
                 * tracker sends -- see
                 * TrackingEventHelpers::deriveDaysSincePriorSession().
                 *
                 * The prior_session_* date parts went with it. They were
                 * formatted from last_req, the one CLIENT-clock value reaching
                 * the schema, and nothing read them.
                 */
                $s->set('prior_session_lastreq', $event->get('last_req'));

                // set last_req to be the timestamp of the event that triggered this session.
                $s->set('last_req', $event->get('timestamp'));
                //$s->set('days_since_first_session', $event->get('days_since_first_session'));
                //$s->set('days_since_prior_session', $event->get('days_since_prior_session'));
                //$s->set('num_prior_sessions', $event->get('num_prior_sessions'));

                // set medium
                //$s->set('medium', $event->get('medium'));

                // set campaign touches
                $s->set( 'latest_attributions' , $event->get( 'attribs' ) );

                // Make document ids
                $s->set('first_page_id', \OWA\Core\Lib::setStringGuid($event->get('page_url')));

                $s->set('last_page_id', $s->get('first_page_id'));

                // Generate Referer id
                // external referer does not exist anymore so i think we can take this out.
                if ($event->get('external_referer')) {
                    $s->set('referer_id', \OWA\Core\Lib::setStringGuid($event->get('HTTP_REFERER')));
                }

                // this should already be set by the request handler.
                //$s->set( 'location_id', $event->get( 'location_id' ) );

                $ret = $s->create();

                // create event message
                $session = $s->_getProperties();
                $properties = array_merge($event->getProperties(), $session);
                $properties['request_id'] = $event->get('guid');
                $ne = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
                $ne->setProperties($properties);
                $ne->setEventType('base.new_session');

                // log the new session event to the event queue
                $eq = \OWA\Core\CoreAPI::getEventDispatch();
                $eq->notify($ne);

                if ($ret) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }
            } else {

                /*
                 * The session already exists, so this request did not create it
                 * -- whatever its flag said. Returning HANDLED here DROPPED the
                 * hit: a second trackPageView() in the same page load still
                 * carries the page-scoped is_new_session, arrived here, found
                 * the row, and was silently discarded, so num_pageviews never
                 * counted it and the session never stopped being a bounce.
                 *
                 * It is an ordinary hit in an existing session. Count it.
                 */
                \OWA\Core\CoreAPI::debug('Session already exists; handling as an update.');
                return $this->logSessionUpdate($event);
            }
        } else {

            \OWA\Core\CoreAPI::debug('Not persisting new session. No session_id present.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    function logSessionUpdate($event) {

        if ( $event->get('session_id') ) {

            // Make entity
            $s = \OWA\Core\CoreAPI::entityFactory('base.session');

            // Fetch from session from database
            $s->getByPk('id', $event->get('session_id'), \OWA\Core\Db::factDateConstraint( $event->get('yyyymmdd') ));

            $id = $s->get('id');
            // fail safe for when there is no existing session in DB
            if (empty($id)) {

                \OWA\Core\CoreAPI::debug("Aborting session update as no existing session was found");
                return OWA_EHS_EVENT_FAILED;
            }

            // idempotent check needed in case updates are processed out of order.
            // dont update the database if the event timestamp is older that the last_req
            // timestamp that is already set on the session object.
            $last_req_time = $s->get('last_req');
            $event_req_time = $event->get('timestamp');

            $ret = false;

            if ($event_req_time > $last_req_time) {

                // increment number of page views
                $s->set( 'num_pageviews', $this->summarizePageviews( $id, $s->get( 'yyyymmdd' ) ) );

                // set bounce flag to false as there must have been 2 page views
                // 0, not the string 'false'.
                //
                // 'false' was a workaround for Entity::set() dropping falsy
                // values ("if ( $value )"), so a plain 0 here is silently
                // ignored. It only ever worked because MySQL coerced the
                // non-numeric string to 0 in this TINYINT column; strict mode
                // rejects it outright, and the string is TRUTHY in PHP, so any
                // code reading is_bounce back would see a bounce where there is
                // none.
                //
                // setValue() writes the property directly, bypassing that falsy
                // guard. The guard itself is worth removing, but that changes
                // every set() call in the codebase and needs its own change.
                // set bounce flag to false as there must have been 2 page views
                //
                // A real 0, not the string 'false' this used to assign. That
                // string existed only because Entity::set() discarded every
                // falsy value, so a truthy one was the only kind that survived
                // -- and MySQL then coerced the non-numeric string to 0 on the
                // way in. It was also TRUTHY in PHP, so reading is_bounce back
                // reported a bounce that had not happened.
                $s->set( 'is_bounce', 0 );

                // update timestamp of latest request that triggered the session update
                $s->set( 'last_req', $event->get( 'timestamp' ) );

                // update last page id
                $s->set( 'last_page_id', $event->get( 'document_id' ) );

                // set medium
                if ( $event->get( 'medium' ) ) {
                    $s->set( 'medium', $event->get( 'medium') );
                }

                // set source
                if ( $event->get( 'source_id' ) ) {
                    $s->set( 'source_id', $event->get( 'source_id' ) );
                }

                // set search terms
                if ($event->get('referring_search_term_id')) {
                    $s->set('referring_search_term_id',  $event->get('referring_search_term_id') );
                }

                // set campaign
                if ($event->get('campaign_id')) {
                    $s->set('campaign_id', $event->get('campaign_id') );
                }

                // set ad
                if ($event->get('ad_id')) {
                    $s->set( 'ad_id', $event->get( 'ad_id' ) );
                }

                // set campaign touches
                if ( $event->get( 'attribs' ) ) {
                    $s->set( 'latest_attributions' , $event->get( 'attribs' ) );
                }

                // update user name if changed.
                if ( $event->get( 'user_name' ) ||  $event->get( 'user_email' ) ) {

                    if ( \OWA\Core\CoreAPI::getSetting( 'base', 'update_session_user_name' ) ) {

                        // check for different user_name
                        $user_name = $event->get( 'user_name' );
                        $old_user_name = $s->get( 'user_name' );

                        if ( $user_name != $old_user_name ) {
                            $s->set( 'user_name', $user_name );
                        }

                        // check for different email address
                        // check for different user_name
                        $email = $event->get( 'user_email' );
                        $old_email = $s->get( 'user_email' );

                        if ( $email != $old_email ) {
                            $s->set( 'user_email', $email );
                        }
                    }
                }

                // Persist to database
                $ret = $s->update();
            }

            // setup event message
            $session = $s->_getProperties();
            $properties = array_merge($event->getProperties(), $session);
            $properties['request_id'] = $event->get('guid');
            $ne = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
            $ne->setProperties($properties);
            $ne->setEventType('base.session_update');
            // Log session update event to event queue
            $eq = \OWA\Core\CoreAPI::getEventDispatch();
            $ret = $eq->notify( $ne );

            if ( $ret ) {
                return OWA_EHS_EVENT_HANDLED;
            } else {
                return OWA_EHS_EVENT_FAILED;
            }
        } else {

            \OWA\Core\CoreAPI::debug('Not persisting new session. No session_id present.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    function summarizePageviews($id, $session_yyyymmdd = null) {

        $constraints = array( 'session_id' => $id );

        // Bound the scan by the session's own date. A request cannot be older
        // than the session holding it, so this excludes no valid row -- but on
        // a partitioned table it is the difference between visiting every
        // partition and visiting the few the session can be in. Left off where
        // the date is unusable.
        $range = \OWA\Core\Db::factDateRange( $session_yyyymmdd );

        if ( $range ) {

            $constraints['yyyymmdd'] = array( 'value' => $range, 'operator' => 'between' );
        }

        $ret = \OWA\Core\CoreAPI::summarize(array(
                'entity'        => 'base.request',
                'columns'        => array('id' => 'count_distinct'),
                'constraints'    => $constraints ) );

        return $ret['id_dcount'];
    }
}

?>