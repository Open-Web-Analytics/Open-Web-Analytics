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
	require_once(OWA_DIR.'owa_observer.php');
}	
require_once(OWA_DIR.'owa_lib.php');

/**
 * Location Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_locationHandlers extends owa_observer {
    	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
		if ( $event->get( 'location_id' ) || $event->get( 'ip_address' ) ) {

	    	$h = owa_coreAPI::entityFactory('base.location_dim');
	    	
	    	// look for location id on the event. This happens when
	    	// another event has already created it.
	    	if ( $event->get( 'location_id' ) ) {
	    		
	    		$location_id = $event->get('location_id');
	    	// else look to see if he event has the minimal geo properties
	    	// if it does then assume that geo properties are set.
			} elseif ( $event->get('country') ) {
				$key = $event->get('country').$event->get('city');
				$location_id = $h->generateId($key);
			// load the geo properties from the geo service.
			} else {
				$location = owa_coreAPI::getGeolocationFromIpAddress($event->get('ip_address'));
				owa_coreAPI::debug('geolocation: ' .print_r($location, true));			
				//set properties of the session
				$event->set('country', $location->getCountry());
				$event->set('city', $location->getCity());
				$event->set('latitude', $location->getLatitude());
				$event->set('longitude', $location->getLongitude());
				$event->set('country_code', $location->getCountryCode());
				$event->set('state', $location->getState());
				$key = $event->get('country').$event->get('city');
				$location_id = $h->generateId($key);
			}
			
			// look up the county code if it's missing
			if ( ! $event->get('country_code') && $event->get('country') ) {
				$event->set( 'country_code', $this->lookupCountryCodeFromName( $event->get('country') ) );
			}
			
			$h->getByPk('id', $location_id );
			$id = $h->get('id'); 
			
			if (!$id) {
				
				$location = owa_coreAPI::getGeolocationFromIpAddress($event->get('ip_address'));
				owa_coreAPI::debug('geolocation: ' .print_r($location, true));
				
				//set properties of the session
				$h->set('country', $event->get('country'));
				$h->set('city', $event->get('city'));
				$h->set('latitude', $event->get('latitude'));
				$h->set('longitude', $event->get('longitude'));
				$h->set('country_code', $event->get('country_code'));
				$h->set('state', $event->get('state'));
				$h->set('id', $location_id); 
				$ret = $h->create();
				
				if ( $ret ) {
					return OWA_EHS_EVENT_HANDLED;
				} else {
					return OWA_EHS_EVENT_FAILED;
				}
				
			} else {
			
				owa_coreAPI::debug('Not Logging. Location already exists');
				return OWA_EHS_EVENT_HANDLED;
			}
		} else {
			
			owa_coreAPI::notice('Not persisting location dimension. Location id or ip address missing from event.');
			
			return OWA_EHS_EVENT_HANDLED;
		}	
    }
    
    function lookupCountryCodeFromName($name) {
    	include_once(OWA_DIR.'conf/countryNames2Codes.php');
    	$name = trim(strtolower($name));
    	if (array_key_exists($name, $countryName2Code)) {
        	return $countryName2Code[$name];
    	}
    	return false;
	}
}

?>