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

if(!class_exists('owa_observer')) {
	require_once(OWA_BASE_DIR.'owa_observer.php');
}	

/**
 * OWA user management Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sessionHandlers extends owa_observer {
    	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
    	if ($event->get('is_new_session')) {
    		$this->logSession($event);
    	} else {
    		$this->logSessionUpdate($event);
    	}
    }
    
    function logSession($event) {
    	
    	// Control logic
		
		$s = owa_coreAPI::entityFactory('base.session');
	
		$s->setProperties($event->getProperties());
	
		// Set Primary Key
		$s->set('id', $event->get('session_id'));
		 
		// set initial number of page views
		$s->set('num_pageviews', 1);
		$s->set('is_bounce', true);

		// set prior session time properties		
		$s->set('prior_session_lastreq', $event->get('last_req'));
				
		$s->set('prior_session_id', $event->get('inbound_session_id'));
		
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
		$s->set('days_since_first_session', $event->get('days_since_first_session'));
		$s->set('days_since_prior_session', $event->get('days_since_prior_session'));
		$s->set('num_prior_sessions', $event->get('num_prior_sessions'));
				
		// set medium
		$s->set('medium', $event->get('medium'));
		
		// set source
		if ($event->get('source')) {
			$s->set('source_id', $s->generateId( 
				trim( strtolower( $event->get('source') ) ) ) );		
		}
			
		// set search terms
		if ($event->get('search_terms')) {
			$s->set('referring_search_term_id', $s->generateId( 
				trim( strtolower( $event->get('search_terms') ) ) ) );		
		}
		
		// set campaign
		if ($event->get('campaign')) {
			$s->set('campaign_id', $s->generateId( 
				trim( strtolower( $event->get('campaign') ) ) ) );		
		}
		
		// set ad
		if ($event->get('ad')) {
			$s->set('ad_id', $s->generateId( 
				trim( strtolower( $event->get('ad') ) ) ) );		
		}
		
		// set campaign touches
		$s->set( 'latest_attributions' , $event->get( 'attribs' ) );
		
		// Make ua id
		$s->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));
		
		// Make OS id
		$s->set('os_id', owa_lib::setStringGuid($event->get('os')));
	
		// Make document ids	
		$s->set('first_page_id', owa_lib::setStringGuid($event->get('page_url')));
			
		$s->set('last_page_id', $s->get('first_page_id'));
	
		// Generate Referer id
		
		if ($event->get('external_referer')) {
			$s->set('referer_id', owa_lib::setStringGuid($event->get('HTTP_REFERER')));
		}	
		// Generate Host id
		$s->set('host_id', owa_lib::setStringGuid($event->get('full_host')));
				
		$s->create();

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
    }
    
    function logSessionUpdate($event) {
    	
    	// Make entity
		$s = owa_coreAPI::entityFactory('base.session');
		
		// Fetch from session from database
		$s->getByPk('id', $event->get('session_id'));
		
		$id = $s->get('id');
		// fail safe for when there is no existing session in DB
		if (empty($id)) {
			
			owa_coreAPI::error("Aborting session update as no existing session was found");
			return false;
		}
		
		// increment number of page views
		$s->set('num_pageviews', $s->get('num_pageviews') + 1);
		$s->set('is_bounce', 'false');
		
		// update timestamp of latest request that triggered the session update
		$s->set('last_req', $event->get('timestamp'));
		
		// update last page id
		$s->set('last_page_id', owa_lib::setStringGuid($event->get('page_url')));
		
		// Persist to database
		$s->update();
		
		// setup event message
		$session = $s->_getProperties();
		$properties = array_merge($event->getProperties(), $session);
		$properties['request_id'] = $event->get('guid');
		$ne = owa_coreAPI::supportClassFactory('base', 'event');
		$ne->setProperties($properties);
		$ne->setEventType('base.session_update');
		// Log session update event to event queue
		$eq = owa_coreAPI::getEventDispatch();
		$eq->notify($ne);
    }
    
}

?>