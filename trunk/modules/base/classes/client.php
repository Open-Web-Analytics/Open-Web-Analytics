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

require_once(OWA_BASE_CLASSES_DIR.'owa_caller.php');

/**
 * OWA Client
 * 
 * Abstract Client Class for use in php based applications
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_client extends owa_caller {

	var $commerce_event;
	
	var $pageview_event;

	public function __construct($config = null) {
		
		return parent::__construct($config);
	}
			
	private function manageState( &$event ) {
		
		$this->setVisitorId( $event );
		$this->setFirstSessionTimestamp( $event );
		$this->setLastRequestTime( $event );
		$this->setSessionId( $event );
		$this->setNumberPriorSessions( $event );
		$this->setTrafficAttribution( $event );
		
		// clear old style session cookie
		$session_store_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $this->site_id);
		owa_coreAPI::clearState( $session_store_name );
	}
	
	private function setVisitorId( &$event ) {
		
		$visitor_id =  owa_coreAPI::getStateParam( 'v', 'vid' );
		
		if ( ! $visitor_id ) {
			$visitor_id =  owa_coreAPI::getStateParam( 'v' );
			owa_coreAPI::clearState( 'v' );
			owa_coreAPI::setState( 'v', 'vid', $visitor_id, 'cookie', true );
			
		}
		
		if ( ! $visitor_id ) {
			$visitor_id = $event->getSiteSpecificGuid( $this->site_id );
			$event->set( 'is_new_visitor', true );
			owa_coreAPI::setState( 'v', 'vid', $visitor_id, 'cookie', true );
		}
		// set property on event object
		$event->set( 'visitor_id', $visitor_id );
	}
	
	private function setNumberPriorSessions( &$event ) {
		// if check for nps value in vistor cookie.
		$nps = owa_coreAPI::getStateParam('v', 'nps');
		// set value to 0 if not found.
		if (!$nps) {
			$nps = 0;
		}
		// set property on the event object
		$event->set('num_prior_sessions', $nps);
		// if new session, increment visit count and persist to state store
		if ( $event->get('is_new_session' ) ) {
			owa_coreAPI::setState('v', 'nps', $nps + 1, 'cookie', true);
		}
	}
	
	private function setFirstSessionTimestamp( &$event ) {
		
		$fsts = owa_coreAPI::getStateParam( 'v', 'fsts' );
		
		if ( ! $fsts ) {
			$fsts = $event->get('timestamp');
			owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), 'fsts', $fsts , 'cookie', true);	
		}
		
		$event->set( 'fsts', $fsts );
	}
	
	private function setSessionId( &$event ) {
	
		$is_new_session = $this->isNewSession( $event->get( 'timestamp' ),  $event->get( 'last_req' ) ); 
		if ( $is_new_session ) {
			//set prior_session_id
			$prior_session_id = owa_coreAPI::getStateParam('s', 'sid');
			if ( ! $prior_session_id ) {
				$state_store_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $this->site_id);		
				$session_id = owa_coreAPI::getStateParam($state_store_name, 's');
			}
			if ($prior_session_id) {
				$event->set( 'prior_session_id', $prior_session_id );
			}
			$session_id = $event->getSiteSpecificGuid( $this->site_id );
			// it's a new session. generate new session ID 
	   		$event->set( 'session_id', $session_id );
	   		//mark new session flag on current request
			$event->set( 'is_new_session', true );
			owa_coreAPI::setState( 's', 'sid', $session_id );
		} else {
			// Must be an active session so just pull the session id from the state store
			$session_id = owa_coreAPI::getStateParam('s', 'sid');
			// support for old style cookie
			if ( ! $session_id ) {
				$state_store_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $this->site_id);		
				$session_id = owa_coreAPI::getStateParam($state_store_name, 's');
				owa_coreAPI::setState( 's', 'sid', $session_id );	
			}
		
			$event->set('session_id', $session_id);
		}
		
		// fail-afe just in case there is no session_id 
		if ( ! $event->get( 'session_id' ) ) {
			$session_id = $event->getSiteSpecificGuid( $this->site_id );
			$event->set( 'session_id', $session_id );
			//mark new session flag on current request
			$event->set( 'is_new_session', true );
			owa_coreAPI::setState( 's', 'sid', $session_id );
		}
		
		
	}
	
	private function setLastRequestTime( &$event ) {
	
		$last_req = owa_coreAPI::getStateParam('s', 'last_req');
		
		// suppport for old style cookie
		if ( ! $last_req ) {
			$state_store_name = sprintf( '%s_%s', owa_coreAPI::getSetting( 'base', 'site_session_param' ), $this->site_id );		
			$last_req = owa_coreAPI::getStateParam( $state_store_name, 's' );	
		}
		// set property on event object
		$event->set( 'last_req', $last_req );
		// store new state value
		owa_coreAPI::setState( 's', 'last_req', $event->get( 'timestamp' ) );
	}
	
	/**
	 * Check to see if request is a new or active session 
	 *
	 * @return boolean
	 */
	private function isNewSession($timestamp = '', $last_req = 0) {
		
		$is_new_session = false;
		
		if ( ! $timestamp ) {
			$timestamp = time();
		}
				
		$time_since_lastreq = $timestamp - $last_req;
		$len = owa_coreAPI::getSetting( 'base', 'session_length' );
		if ( $time_since_lastreq < $len ) {
			owa_coreAPI::debug("This request is part of a active session.");
			return false;		
		} else {
			//NEW SESSION. prev session expired, because no requests since some time.
			owa_coreAPI::debug("This request is the start of a new session. Prior session expired.");
			return true;
		}
	}
	
	/**
	 * Logs event params taken from request scope.
	 * Takes event type from url.
	 *
	 * @return unknown
	 */
	function logEventFromUrl() {
		
		// keeps php executing even if the client closes the connection
		ignore_user_abort(true);
		$service = &owa_coreAPI::serviceSingleton();
		$service->request->decodeRequestParams();
		$event = owa_coreAPI::supportClassFactory('base', 'event');
		$event->setEventType(owa_coreAPI::getRequestParam('event_type'));
		$event->setProperties($service->request->getAllOwaParams());
		return $this->trackEvent( $event );
	}
	
	/**
	 * Logs tracking event
	 * 
	 * This function fires a tracking event that will be processed and then dispatched
	 *
	 * @param object $event
	 * @return boolean
	 */
	public function trackEvent($event) {
		
		// needed by helper page tags function so it can append to first hit tag url	
		if (!$this->getSiteId()) {
			$this->setSiteId($event->get('site_id'));
		}
		
		if (!$this->getSiteId()) {
			$this->setSiteId(owa_coreAPI::getRequestParam('site_id'));
		}
		
		if ( ! $event->get('site_id') ) {
			$event->set( 'site_id', $this->getSiteId() );
		}
		
		// flag used to identify is state was managed by an upstream client
		if ( ! $event->get('is_state_set') ) {
			$this->manageState( $event );
		}
		
		return owa_coreAPI::logEvent($event->getEventType(), $event);
	}
		
	public function trackPageview($event) {
	
		$event->setEventType('base.page_request');
		$this->pageview_event = $event;
		return $this->trackEvent($event);
	}
	
	public function trackAction($action_group = '', $action_name, $action_label = '', $numeric_value = 0) {
		
		$event = $this->makeEvent();
		$event->setEventType('track.action');
		$event->set('action_group', $action_group);
		$event->set('action_name', $action_name);
		$event->set('action_label', $action_label);
		$event->set('numeric_value', $numeric_value);
		$event->set('site_id', $this->getSiteId());
		return $this->trackEvent($event);
	}
	
	/** 
	 * Creates a commerce Transaction event
	 *
	 * Creates a parent commerce.transaction event
	 */
	public function addTransaction( $order_id, $order_source = '', 
			$total = 0, $tax = 0, $shipping = 0, $gateway = '', 
			$page_url = '', $session_id = '') {
		
		$this->commerce_event = $this->makeEvent();
		$this->commerce_event->setEventType( 'commerce.transaction' );
		$this->commerce_event->set( 'ct_order_id', $order_id );
		$this->commerce_event->set( 'ct_order_source', $order_source );
		$this->commerce_event->set( 'ct_total', $total );
		$this->commerce_event->set( 'ct_tax', $tax );
		$this->commerce_event->set( 'ct_shipping', $shipping );
		$this->commerce_event->set( 'ct_gateway', $gateway );
		$this->commerce_event->set( 'page_url', $page_url );
		$this->commerce_event->set( 'ct_line_items', array() );
		
		if ( $session_id ) {
			$this->commerce_event->set( 'session_id', $session_id );
			// tells the client to NOT manage state properties as we are
			// going to look them up from the session later.
			$this->commerce_event->set( 'is_state_set', true );
			$this->commerce_event->set( 'lookup_state_from_session', true );
		}
	}
	
	/** 
	 * Adds a line item to a commerce transaction
	 *
	 * Creates and a commerce.line_item event and adds it to the parent transaction event
	 */
	public function addTransactionLineItem($order_id, $sku = '', $product_name = '', $category = '', $unit_price = 0, $quantity = 0) {
		
		if ( empty( $this->commerce_event ) ) {
			$this->addTransaction('none set');
		}
		
		$event = $this->makeEvent();
		$event->setEventType( 'commerce.line_item' );
		$event->set( 'li_order_id', $order_id );
		$event->set( 'li_sku', $sku );
		$event->set( 'li_product_name', $product_name );
		$event->set( 'li_category', $category );
		$event->set( 'li_unit_price', $unit_price );
		$event->set( 'li_quantity', $quantity );
		
		$items = $this->commerce_event->get( 'ct_line_items' );
		$items[] = $event;
		$this->commerce_event->set( 'ct_line_items', $items );
	}
	
	/** 
	 * tracks a commerce events
	 *
	 * Tracks a parent transaction event by sending it to the event queue
	 */
	public function trackTransaction() {
		
		if ( ! empty( $this->commerce_event ) ) {
			$this->trackEvent( $this->commerce_event );
			$this->commerce_event = '';
		}
	}
	
	public function createSiteId($value) {
	
		return md5($value);
	}
	
	function setTrafficAttribution( &$event ) {
		
		// if not then look for individual campaign params on the request. 
		// this happens when the client is php and the params are on the url
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_properties = array();
		$campaign_state = array();
		foreach ($campaign_params as $k => $param) {
			if ( $event->get( $param ) ) {
				$campaign_properties[$k] = $event->get( $param );
			}
		}
		
		owa_coreAPI::debug('campaign properties: '. print_r($campaign_properties, true));
		
		// backfill values for incomplete param combos
		
		if (array_key_exists('at', $campaign_properties) && !array_key_exists('ad', $campaign_properties)) {
			$campaign_properties['ad'] = '(not set)';
		}
		
		if (array_key_exists('ad', $campaign_properties) && !array_key_exists('at', $campaign_properties)) {
			$campaign_properties['at'] = '(not set)';
		}
		
		// load existings campaing state
		$campaign_state = owa_coreAPI::getStateParam( 'c' );
		if ( $campaign_state ) {
			$campaign_state = json_decode( $campaign_state );
		} else {
			$campaign_state = array();
		}
		
				
		$mode = owa_coreAPI::getSetting('base', 'trafficAttributionMode');
		$attribution = array(
				'medium' 	=> '',
				'source' 	=> '',
				'campaign' 	=> '',
				'ad_type' 	=> '',
				'ad' 	=> '',
				'search_terms' 	=> ''
		);
		
		if ($mode === 'direct') {
			
			// add new campaign info to existing campaign cookie.
			if ( !empty( $campaign_properties ) ) {
				
				// add timestamp
				$campaign_properties['ts'] = $event->get('timestamp');
				// add new campaign into state array
				$campaign_state[] = (object) $campaign_properties;
				
				// if more than x slice the first one off to make room
				$count = count( $campaign_state );
				$max = owa_coreAPI::getSetting( 'base', 'max_prior_campaigns');
				if ($count > $max ) {
					array_shift( $campaign_state );
				}
					
				// reset state
				$this->setCampaignCookie($campaign_state);
				
				// set flag
				$event->set('is_attributed', true);
			}
		}
		
		// if attribution mode is 'original' then only add the touch if
		// there is no prior touch in the cookie	
		if ($mode === 'original') {
			
			$orginal = false;
			
			// orignal touch was set previously. jus use that.
			if (!empty($campaign_state)) {
				// do nothing
				owa_coreAPI::debug('Original attribution detected.');
				// set the attributes from the first campaign touch
				$campaign_properties = $campaign_state[0];
				$event->set('is_attributed', true);
		
			// no orginal touch, set one if it's a new campaign touch
			} else {
				
				if (!empty($campaign_properties)) {
					// add timestamp
					$campaign_properties['ts'] = $event->get('timestamp');
					owa_coreAPI::debug('Setting original Campaign attrbution.');
					$campaign_state[] = $campaign_properties;
					// set cookie
					$this->setCampaignCookie($campaign_state);
					$event->set('is_attributed', true);
				}
			}
			
		}
		
		// set the attributes
		if (!empty($campaign_properties)) {
		
			foreach ($campaign_properties as $k => $v) {
									
				if ($k === 'md') {
					$attribution['medium'] = $campaign_properties[$k];
				}
				
				if ($k === 'sr') {
					$attribution['source'] = $campaign_properties[$k];
				}
				
				if ($k === 'cn') {
					$attribution['campaign'] = $campaign_properties[$k];
				}
					
				if ($k === 'at') {
					$attribution['ad_type'] = $campaign_properties[$k];
				}
				
				if ($k === 'ad') {
					$attribution['ad'] = $campaign_properties[$k];
				}
				
				if ($k === 'tr') {
					$attribution['search_terms'] = $campaign_properties[$k];
				}
				
				if ($k === 'ts') {
					$attribution['timestamp'] = $campaign_properties[$k];
				}
			}		
		}
		
		if (!empty($attribution['medium'])) {
			$event->set('medium', $attribution['medium']);
		}
		
		if (!empty($attribution['source'])) {
			$event->set('source', $attribution['source']);
		}
		
		if (!empty($attribution['campaign'])) {
			$event->set('campaign', $attribution['campaign']);
		}
		
		if (!empty($attribution['ad'])) {
			$event->set('ad', $attribution['ad']);
		}
		
		if (!empty($attribution['ad_type'])) {
			$event->set('ad_type', $attribution['ad_type']);
		}
		
		if (!empty($attribution['search_terms'])) {
			$event->set('search_terms', $attribution['search_terms']);
		}
		
		if (!empty($attribution['timestamp'])) {
			$event->set('campaign_timestamp', $attribution['timestamp']);
		}
		
		$event->set('attribs', owa_coreAPI::getStateParam('c'));
	}
	
	function setCampaignCookie($values) {
		// reset state
		owa_coreAPI::setState('c', '', 
				json_encode( $values ), 
				'cookie', 
				owa_coreAPI::getSetting( 'base', 'campaign_attribution_window' ) );
	}
	
}

?>