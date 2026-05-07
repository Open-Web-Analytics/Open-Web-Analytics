<?php

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

if(!class_exists('owa_observer')) {
    require_once(OWA_BASE_DIR.'owa_observer.php');
}

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

class owa_sessionHandlers extends owa_observer {

    /**
     * Notify Event Handler
     *
     * @param     unknown_type $event
     * @access     public
     */
    function notify($event) {

        // add derived params to event

        // set properties on entity

        // persist entity

        // dispatch new event based on properties of entity


        if ($event->get('is_new_session')) {
            return $this->logSession($event);
        } else {
            return $this->logSessionUpdate($event);
        }
    }
    
    function logSession($event) {

        if ( $event->get('session_id') ) {

            $s = owa_coreAPI::entityFactory('base.session');

            $s->load( $event->get('session_id') );

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

                if ($s->get('prior_session_lastreq') > 0) {
                    $s->set('time_sinse_priorsession', $s->get('timestamp') - $event->get('last_req'));
                    $s->set('prior_session_year', date("Y", $event->get('last_req')));
                    $s->set('prior_session_month', date("M", $event->get('last_req')));
                    $s->set('prior_session_day', date("d", $event->get('last_req')));
                    $s->set('prior_session_hour', date("G", $event->get('last_req')));
                    $s->set('prior_session_minute', date("i", $event->get('last_req')));
                    $s->set('prior_session_dayofweek', date("w", $event->get('last_req')));
                }

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
                $s->set('first_page_id', owa_lib::setStringGuid($event->get('page_url')));

                $s->set('last_page_id', $s->get('first_page_id'));

                // Generate Referer id
                // external referer does not exist anymore so i think we can take this out.
                if ($event->get('external_referer')) {
                    $s->set('referer_id', owa_lib::setStringGuid($event->get('HTTP_REFERER')));
                }

                // this should already be set by the request handler.
                //$s->set( 'location_id', $event->get( 'location_id' ) );

                $ret = $s->create();

                // create event message
                $session = $s->_getProperties();
                $properties = array_merge($event->getProperties(), $session);
                $properties['request_id'] = $event->get('guid');
                $ne = owa_coreAPI::supportClassFactory('base', 'event');
                $ne->setProperties($properties);
                $ne->setEventType('base.new_session');

                // log the new session event to the event queue
                $eq = owa_coreAPI::getEventDispatch();
                $eq->notify($ne);

                if ($ret) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }
            } else {
                owa_coreAPI::debug('Not persisting new session. Session already exists.');
                return OWA_EHS_EVENT_HANDLED;
            }
        } else {

            owa_coreAPI::debug('Not persisting new session. No session_id present.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    function logSessionUpdate($event) {

        if ( $event->get('session_id') ) {

            // Make entity
            $s = owa_coreAPI::entityFactory('base.session');

            // Fetch from session from database
            $s->getByPk('id', $event->get('session_id'));

            $id = $s->get('id');
            // fail safe for when there is no existing session in DB
            if (empty($id)) {

                owa_coreAPI::debug("Aborting session update as no existing session was found");
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
                $s->set( 'num_pageviews', $this->summarizePageviews( $id ) );

                // set bounce flag to false as there must have been 2 page views
                $s->set( 'is_bounce', 'false' );

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

                    if ( owa_coreAPI::getSetting( 'base', 'update_session_user_name' ) ) {

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
            $ne = owa_coreAPI::supportClassFactory('base', 'event');
            $ne->setProperties($properties);
            $ne->setEventType('base.session_update');
            // Log session update event to event queue
            $eq = owa_coreAPI::getEventDispatch();
            $ret = $eq->notify( $ne );

            if ( $ret ) {
                return OWA_EHS_EVENT_HANDLED;
            } else {
                return OWA_EHS_EVENT_FAILED;
            }
        } else {

            owa_coreAPI::debug('Not persisting new session. No session_id present.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    function summarizePageviews($id) {

        $ret = owa_coreAPI::summarize(array(
                'entity'        => 'base.request',
                'columns'        => array('id' => 'count_distinct'),
                'constraints'    => array( 'session_id' => $id ) ) );

        return $ret['id_dcount'];
    }
}

?>