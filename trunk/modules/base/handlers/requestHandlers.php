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
 * Request Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_requestHandlers extends owa_observer {

    /**
     * Notify Handler
     *
     * @access 	public
     * @param 	object $event
     */
    function notify($event) {
    
    	$r = owa_coreAPI::entityFactory('base.request');
    	
    	$r->load( $event->get('guid') );
    	
    	if ( ! $r->wasPersisted() ) {
    	
			$r->setProperties($event->getProperties());
		
			// Set Primary Key
			$r->set('id', $event->get('guid'));
			
			// Make ua id
			$r->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));
		
			// Make OS id
			$r->set('os_id', owa_lib::setStringGuid($event->get('os')));
		
			// Make document id	
			$r->set('document_id', owa_lib::setStringGuid($event->get('page_url')));
			
			// Make prior document id	
			$r->set('prior_document_id', owa_lib::setStringGuid($event->get('prior_page')));
			
			// Generate Referer id
			$r->set('referer_id', owa_lib::setStringGuid($event->get('HTTP_REFERER')));
			
			// Generate Host id
			$r->set('host_id', owa_lib::setStringGuid($event->get('full_host')));
			
			// Generate Host id
			$r->set('num_prior_sessions', $event->get('num_prior_sessions'));
			
			$r->set('language', $event->get('language'));
			
			if ( ! $event->get( 'country' ) ) {
			
				$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
				owa_coreAPI::debug( 'geolocation: ' .print_r( $location, true ) );
				$event->set( 'country', $location->getCountry() );
				$event->set( 'city', $location->getCity() );
				$event->set( 'latitude', $location->getLatitude() );
				$event->set( 'longitude', $location->getLongitude() );
				$event->set( 'country_code', $location->getCountryCode() );
				$event->set( 'state', $location->getState() );
				$location_id = $location->generateId();
				
			} else {
				$s = owa_coreAPI::serviceSingleton();
				$location_id = $s->geolocation->generateId($event->get( 'country' ), $event->get( 'state' ), $event->get( 'city' ) );
			}
			
			if ($location_id) {
				$event->set( 'location_id', $location_id );
				$r->set( 'location_id',  $event->get( 'location_id' ) );
			}
			
			$result = $r->create();
			
			if ($result == true) {
			
				$eq = owa_coreAPI::getEventDispatch();
				$nevent = $eq->makeEvent($event->getEventType().'_logged');
				$nevent->setProperties($event->getProperties());
				$eq->asyncNotify($nevent);
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
		} else {
			owa_coreAPI::debug('Not persisting. Request already exists.');
			return OWA_EHS_EVENT_HANDLED;
		}
	}
}

?>