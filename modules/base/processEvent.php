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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/ini_db.php'); // needed?
require_once(OWA_BASE_CLASS_DIR.'trackingEventHelpers.php'); // needed?
/**
 * Generic Event Processor Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processEventController extends owa_controller {
	
	var $event;
	var $eq;
	
	function __construct($params) {
	
		if (array_key_exists('event', $params) && !empty($params['event'])) {
			
			$this->event = $params['event'];
				
		} else {
			owa_coreAPI::debug("No event object was passed to controller.");
			$this->event = owa_coreAPI::supportClassFactory('base', 'event');
		}
				
		$this->eq = owa_coreAPI::getEventDispatch();
		
		return parent::__construct($params);
	
	}
	
	/**
	 * Main Control Logic
	 *
	 * @return unknown
	 */
	function action() {
			
		return;
		
	}
	
	/**
	 * Must be called before all other event property setting functions
	 */
	function pre() {
		
		$teh = owa_trackingEventHelpers::getInstance();
		
		$s = owa_coreAPI::serviceSingleton();
		
		// STAGE 1 - set environmental properties
	
		$environmentals = $s->getMap( 'tracking_properties_environmental' );
		$teh->setTrackerProperties( $this->event, $environmentals );
		
		// STAGE 2 - process incomming properties
		
		$properties = $s->getMap( 'tracking_properties_regular' );
		
		// add custom var properties
		$properties = $teh->addCustomVariableProperties( $properties );
		// translate custom var properties
		$teh->translateCustomVariables( $this->event );
		
		$teh->setTrackerProperties( $this->event, $properties );	
		
		// STAGE 3 - derived properties
		
		$derived_properties = $s->getMap( 'tracking_properties_derived' );
		$teh->setTrackerProperties( $this->event, $derived_properties );
	}
	
	function post() {
			
		return $this->addToEventQueue();
	}
		
	/**
	 * Log request properties of the first hit from a new visitor to a special cookie.
	 * 
	 * This is used to determine if the request is made by an actual browser instead 
	 * of a robot with spoofed or unknown user agent.
	 * 
	 * @access 	public
	 */
	function log_first_hit() {
			
		$state_name = owa_coreAPI::getSetting('base', 'first_hit_param');
		$this->event->set('event_type', 'base.first_page_request');
		return owa_coreAPI::setState($state_name, '', $this->event->getProperties(), 'cookie', true);	
	}
	
	function addToEventQueue() {
	
		// check to see if IP should be excluded
		if ( owa_coreAPI::isIpAddressExcluded( $this->event->get('ip_address') ) ) {
			owa_coreAPI::debug("Not dispatching event. IP address found in exclusion list.");
			return;
		}
	
		if (!$this->event->get('do_not_log')) {
			
			//filter event
			$this->event = $this->eq->filter( 'post_processed_tracking_event', $this->event );
			
			// queue for later or notify listeners
			if ( owa_coreAPI::getSetting( 'base', 'queue_events' ) || 
				 owa_coreAPI::getSetting( 'base', 'queue_incoming_tracking_events' ) ) {
				
				$q = owa_coreAPI::getEventQueue( 'incoming_tracking_events' );
				owa_coreAPI::debug('Queuing '.$this->event->getEventType().' event with properties: '.print_r($this->event->getProperties(), true ) );
				$q->sendMessage( $this->event );
				
			} else {
			
				owa_coreAPI::debug('Dispatching '.$this->event->getEventType().' event with properties: '.print_r($this->event->getProperties(), true ) );
				$this->eq->notify( $this->event );
			}
			
		} else {
			
			owa_coreAPI::debug("Not dispatching event due to 'do not log' flag being set.");
		}

	}
	
	function setDaysSinceFirstSession() {
		
		$fsts = $this->event->get( 'fsts' );
		if ( $fsts ) {
			$this->event->set('days_since_first_session', round(($this->event->get('timestamp') - $fsts)/(3600*24)));	
		} else {
			// this means that first session timestamp was not set in the cookie even though it's not a new user...so we set it. 
			// This can happen with users prior to 1.3.0. when this value was introduced into the cookie.
			$this->event->set('days_since_first_session', 0);
		}
	}
	
	function setCustomVariables() {
		
		$maxCustomVars = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );
		
		for ($i = 1; $i <= $maxCustomVars; $i++) {
		
			$cvar = $this->event->get( 'cv'.$i );
			
			if ( $cvar ) {
				//split the string
				$pieces = explode('=', trim( $cvar ) );
				if ( isset( $pieces[1] ) ) {
					$this->event->set( 'cv'.$i.'_name', $pieces[0] );
					$this->event->set( 'cv'.$i.'_value', $pieces[1] );
				}
			} else {
				$this->event->set( 'cv'.$i.'_name', '(not set)' );
				$this->event->set( 'cv'.$i.'_value', '(not set)' );
			}
		}
	}
	
	function setGeolocation() {
		
		if ( ! $this->event->get( 'country' ) ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $this->event->get( 'ip_address' ) );
			owa_coreAPI::debug( 'geolocation: ' .print_r( $location, true ) );
			$this->event->set( 'country', $location->getCountry() );
			$this->event->set( 'city', $location->getCity() );
			$this->event->set( 'latitude', $location->getLatitude() );
			$this->event->set( 'longitude', $location->getLongitude() );
			$this->event->set( 'country_code', $location->getCountryCode() );
			$this->event->set( 'state', $location->getState() );
			$location_id = $location->generateId();
			
		} else {
			$s = owa_coreAPI::serviceSingleton();
			$location_id = $s->geolocation->generateId($this->event->get( 'country' ), $this->event->get( 'state' ), $this->event->get( 'city' ) );
		}
		
		if ( $location_id ) {
			$this->event->set( 'location_id', $location_id );
		}
	}
	
/*
	private function isIpAddressExcluded( $ip_address ) {
		
		// do not log if ip address is on the do not log list
		$ips = owa_coreAPI::getSetting( 'base', 'excluded_ips' );
		owa_coreAPI::debug('excluded ips: '.$ips);
		if ( $ips ) {
		
			$ips = trim( $ips );
			
			if ( strpos( $ips, ',' ) ) {
				$ips = explode( ',', $ips );
			} else {
				$ips = array( $ips );
			}
			
			foreach( $ips as $ip ) {
				$ip = trim( $ip );
				if ( $ip_address === $ip ) {
					owa_coreAPI::debug("Request is from excluded ip address: $ip.");
					return true;
				}
			}
		}
	}
*/
	
	private function anonymizeIpAddress( $ip_address ) {
		
		if ( $ip_address && strpos($ip_address, '.' ) ) {
		
			$ip = explode( '.', $ip_address );
			array_pop($ip);
			$ip = implode('.', $ip);
			
			return $ip;
		}
	}
	
	function setVisitorType ( $event ) {
		
		// set repeat visitor type flag visitor is not new.		
		if ( ! $event->get( 'is_new_visitor' ) ) {
			$event->set( 'is_repeat_visitor', true );
		} else {
			// properly cast this to a bool.
			$event->set( 'is_new_visitor', true );
		}
	}
	
	function setTimeProperties ( $event ) {
		
		$timestamp = $event->get('timestamp');
		
		$event->set('year', date("Y", $timestamp));
		$event->set('month', date("Ym", $timestamp));
		$event->set('day', date("d", $timestamp));
		$event->set('yyyymmdd', date("Ymd", $timestamp));
		$event->set('dayofweek', date("D", $timestamp));
		$event->set('dayofyear', date("z", $timestamp));
		$event->set('weekofyear', date("W", $timestamp));
		$event->set('hour', date("G", $timestamp));
		$event->set('minute', date("i", $timestamp));
		$event->set('second', date("s", $timestamp));
		
		//epoc time
		list( $msec, $sec ) = explode( " ", $event->get('microtime') );
		$event->set('sec', $sec);
		$event->set('msec', $msec);
	}
	
	function derivePageUri( $page_url ) {
				
		$page_parse = parse_url( $page_url );
		
		if ( ! array_key_exists( 'path', $page_parse ) || empty( $page_parse['path'] ) ) {
			
			$page_parse['path'] = '/';
		}
	
		if ( array_key_exists( 'query', $page_parse ) || ! empty( $page_parse['query'] ) ) {
			
			return sprintf( '%s?%s', $page_parse['path'], $page_parse['query'] );	
			
		} else {
			
			return $page_parse['path'] ;
		}
	}
}

?>