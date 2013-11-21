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
//require_once(OWA_BASE_CLASS_DIR.'trackingEventHelpers.php'); // needed?
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
		
		// ENVIRONMENTAL PARAMS
		
		// check for no value
		// clean
		// apply default if necessary
		// filter
		
		// these should really be pulled from the service layer	
		$this->event->set( 'timestamp', owa_coreAPI::getRequestTimestamp() );
		$this->event->set( 'microtime', microtime() );
		
		//set user agent
		if ( ! $this->event->get('HTTP_USER_AGENT')) {
			$this->event->set('HTTP_USER_AGENT', owa_coreAPI::getServerParam('HTTP_USER_AGENT'));
		} 
		// filter user agent
		$this->event->set( 'HTTP_USER_AGENT', $this->eq->filter( 'user_agent', $this->event->get( 'HTTP_USER_AGENT' ) ) );
		
		// set http_host
		if ( ! $this->event->get( 'HTTP_HOST' ) ) {
			$this->event->set( 'HTTP_HOST', owa_coreAPI::getServerParam( 'HTTP_HOST' ) );
		}
		//filter http_host
		$this->event->set( 'HTTP_HOST', $this->eq->filter( 'HTTP_HOST', $this->event->get( 'HTTP_HOST' ) ) );

		// set language
		if ( ! $this->event->get( 'language' ) ) {
			$this->event->set( 'language', substr( owa_coreAPI::getServerParam( 'HTTP_ACCEPT_LANGUAGE' ), 0, 5 ) );
		}
		// filter language
		$this->event->set( 'language', $this->eq->filter( 'language', $this->event->get( 'language' ) ) );
		
		// Set Ip Address
		if ( ! $this->event->get('ip_address') ) {
			$this->event->set('ip_address', owa_coreAPI::getServerParam('REMOTE_ADDR'));
		}
		
		$this->event->set('ip_address', $this->eq->filter('ip_address', $this->event->get('ip_address') ) );
		
		// Set host related properties
		if (!$this->event->get('REMOTE_HOST')) {
			$this->event->set('REMOTE_HOST', owa_coreAPI::getServerParam('REMOTE_HOST'));
		}
		
		//$teh = owa_trackingEventHelpers::getInstance();
		//$teh->setEnvironmentals( $this->event );
		
		/// REQUIRED PARAMS
		
		// check for value
		// clean based on type
		// filter
		// apply default value if necessary
		
		
		
		// set page type
		// moved
		if ( ! $this->event->get( 'page_type' ) ) {
			$this->event->set( 'page_type', '(not set)' );
		} 
		//filter page type
		//moved
		$this->event->set( 'page_type', $this->eq->filter( 'page_type', $this->event->get( 'page_type' ) ) );
		
		// filter page_url
		//moved
		$this->event->set( 'page_url', $this->eq->filter( 'page_url', $this->event->get( 'page_url' ), $this->event->get( 'site_id' ) ) );
		
		
		// Page title
		// moved
		if ( $this->event->get( 'page_title' ) ) {
			$page_title = owa_lib::utf8Encode( trim( $this->event->get( 'page_title' ) ) );
		} else {
			$page_title = '(not set)';
		}
		
		$this->event->set('page_title', $this->eq->filter( 'page_title', $page_title ) );
		
		// needs key translation
		// translated,
		$this->event->set( 'days_since_first_session', $this->event->get( 'dsfs' ) );
		$this->event->set( 'days_since_prior_session', $this->event->get( 'dsps' ) );
		$this->event->set( 'num_prior_sessions', $this->event->get( 'nps' ) );
		
		// OPTIONAL PARAMS
		
		// filter http referer
		/* moved */
		if ( $this->event->get( 'HTTP_REFERER' ) ) {
		
			$this->event->set( 'HTTP_REFERER', $this->eq->filter( 'HTTP_REFERER', $this->event->get( 'HTTP_REFERER' ) ) );
		}
		
		// Filter the target url of clicks
		/* moved */
		if ( $this->event->get( 'target_url' ) ) {
		
			$this->event->set( 'target_url', $this->eq->filter( 'target_url', $this->event->get( 'target_url' ), $this->event->get( 'site_id' ) ) );
		}
		
		/* moved */
		if ( $this->event->get( 'source' ) ) {
		
			$this->event->set( 'source', $this->eq->filter( 'source', trim( strtolower( $this->event->get( 'source' ) ) ) ) );
		}
		
		/* moved */
		if ( $this->event->get( 'medium' ) ) {
			
			$this->event->set( 'medium', $this->eq->filter( 'medium', trim( strtolower( $this->event->get( 'medium' ) ) ) ) );		
		}
		
		// set session referer (the site that originally referer the visit)
		/* moved */
		if ( $this->event->get( 'session_referer' ) ) {
		
			$this->event->set( 'session_referer', $this->eq->filter( 'session_referer', $this->event->get( 'session_referer' ) ) );
		}

		
		$this->setCustomVariables();
		
		
		// DEPENDANT SETS
		
		// evaluate
		// produce value
		// filter
		// add default if required
		
		// Set all time related properties
		// depends on 'timestamp'
		/* moved */
		$this->setTimeProperties( $this->event );
		
		/* moved */
		// set repeat visitor type flag visitor is not new.		
		if ( ! $this->event->get( 'is_new_visitor' ) ) {
		
			$this->event->set( 'is_repeat_visitor', true );
		
		} else {
			// properly cast this to a bool.
			$this->event->set( 'is_new_visitor', true );
		}
		
		/* moved */
		if ( ! $this->event->get ('page_uri') ) {
		
			$page_uri = $this->derivePageUri($this->event->get( 'page_url' ) );
		}
		
		$this->event->set('page_uri', $this->eq->filter( 'page_uri', $page_uri ) );
		
		// host properties
		/* mooved */
		$this->event->set( 'full_host', $this->eq->filter( 'full_host', 
				$this->event->get( 'REMOTE_HOST' ), 
				$this->event->get( 'ip_address' ) ) );
		
		// required
		if ( ! $this->event->get( 'full_host' ) ) {
		
			$this->event->set('full_host', '(not set)');
		}
		
		/* moved */		
		$this->event->set( 'host', $this->eq->filter( 'host', $this->event->get( 'full_host' ), $this->event->get( 'ip_address' ) ) );
		
		//required
		if ( ! $this->event->get( 'host' ) ) {
			$this->event->set( 'host', '(not set)' );
		}
		
		// Browser related properties
		// required
		$service = owa_coreAPI::serviceSingleton();
		$bcap = $service->getBrowscap();
		
		// Assume browser untill told otherwise
		/* moved */
		$this->event->set('is_browser',true);
		
		/* moved */
		$this->event->set('browser_type', $this->eq->filter( 'browser_type', $bcap->getUaFamily() ) );
		
		/* moved */
		if ( $bcap->getUaVersion() ) {
		
			$this->event->set('browser', $this->eq->filter('browser', $bcap->getUaVersion() ) );
		
		} else {
		
			$this->event->set('browser', $this->eq->filter('browser', '(unknown)'));
		}
		
		//Check for what kind of page request this is
		/* moved */
		if ( $bcap->isRobot() ) {
			$this->event->set('is_robot', true);
			$this->event->set('is_browser', false);
		}
		
		/* moved */
		$this->event->set( 'os', $this->eq->filter( 'operating_system', $bcap->getOsFamily(), $this->event->get( 'HTTP_USER_AGENT' ) ) );
		
		/*moved */
		if ( $this->event->get('is_new_session') ) {
			//mark entry page flag on current request
			$this->event->set( 'is_entry_page', true );			
		}
		
		/* moved */
		$this->setGeolocation();
		
		// set prior page properties
		/* moved */
		if ( $this->event->get( 'HTTP_REFERER' ) ) {

			$referer_parse = owa_lib::parse_url( $this->event->get('HTTP_REFERER') );

			if ( $referer_parse['host'] === $page_parse['host'] ) {
				$this->event->set('prior_page', $this->eq->filter('prior_page', $this->event->get('HTTP_REFERER'), $this->event->get( 'site_id' ) ) );	
			}
		}
		
		// set  search terms
		/* moved */	
		$search_terms = $this->event->get( 'search_terms' );
		
		if ( $search_terms && $search_terms != '(not set)' ) {
		
			$this->event->set( 'search_terms', $this->eq->filter('search_terms', trim( strtolower( $this->event->get( 'search_terms' ) ) ) ) );
		}

						
		// ID GENERATION
		
		//set user agent id
		/* moved */
		$this->event->set( 'ua_id', owa_lib::setStringGuid( $this->event->get( 'HTTP_USER_AGENT' ) ) );
		
				
		// set document/page id
		/* moved */
		if ( $this->event->get('page_url') ) {
			$this->event->set( 'document_id', owa_lib::setStringGuid( $this->event->get( 'page_url' ) ) );
		}
		
		// Generate host_id
		/* moved */
		$this->event->set( 'host_id',  owa_lib::setStringGuid( $this->event->get( 'host' ) ) );
		
		// generate os_id
		/* moved */
		$this->event->set( 'os_id', owa_lib::setStringGuid( $this->event->get( 'os' ) ) );
		
		/* moved */
		if ( $this->event->get( 'campaign' ) ) {
			$this->event->set( 'campaign_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get( 'campaign' ) ) ) ) );
		}
		
		/* moved */
		if ( $this->event->get( 'ad' ) ) {
			$this->event->set( 'ad_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get( 'ad' ) ) ) ) );
		}
		
		/* moved */
		if ( $this->event->get( 'source' ) ) {
			
			$this->event->set( 'source_id', owa_lib::setStringGuid( $this->event->get( 'source' ) ) );
		}
		
		// set session referer (the site that originally referer the visit)
		/* moved */
		if ( $this->event->get( 'session_referer' ) ) {
			// generate referer_id for downstream handlers
			$this->event->set( 'referer_id',  owa_lib::setStringGuid( $this->event->get('session_referer' ) ) );
		}
		
		/* moved */		
		if ( $this->event->get( 'search_terms' ) ) {
		
			$this->event->set( 'referring_search_term_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get( 'search_terms' ) ) ) ) );
		}				
				
		// SHOULD BE A FILTER
		
		// record and filter personally identifiable info (PII)		
		if ( owa_coreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {
			
			// set user name if one does not already exist on event
			/* moved */
			if ( ! $this->event->get( 'user_name' ) && owa_coreAPI::getSetting( 'base', 'log_owa_user_names' ) ) {
			
				$cu = owa_coreAPI::getCurrentUser();
				$this->event->set( 'user_name',  $cu->user->get( 'user_id' ) );
			
			}
			
			$this->event->set( 'user_name', $this->eq->filter( 'user_name', $this->event->get( 'user_name' ) ) );
			
			// set email_address if one does not already exist on event
			/* moved */
			if ( ! $this->event->get( 'email_address' ) ) {
				
				$cu = owa_coreAPI::getCurrentUser();
				$this->event->set( 'email_address', $cu->user->get( 'email_address' ) );
			}
			
			$this->event->set( 'user_email', $this->eq->filter( 'user_email', $this->event->get( 'email_address' ) ) );
		}
		
		// anonymize Ip address
		if ( owa_coreAPI::getSetting( 'base', 'anonymize_ips' ) ) {
			$this->event->set('ip_address', $this->anonymizeIpAddress($this->event->get('ip_address')));
			$this->event->set('full_host', '(not set)');
		}
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