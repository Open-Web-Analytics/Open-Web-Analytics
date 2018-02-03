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

require_once( OWA_BASE_CLASSES_DIR . 'owa_caller.php' );

/**
 * OWA Client Class
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
	
	var $global_event_properties = array();
	
	var $stateInit;
	
	var $organicSearchEngines;
	
	// set one traffic has been attributed.
	var $isTrafficAttributed;

	public function __construct($config = null) {
			
		parent::__construct($config);
		
		$this->pageview_event = $this->makeEvent();
		$this->pageview_event->setEventType('base.page_request');
		// Set the page url from environmental vars
		$this->setGlobalEventProperty( 'page_url', owa_coreAPI::getCurrentUrl() );
		owa_coreAPI::registerStateStore('v', time()+3600*24*365*10, '', 'assoc', 'cookie', true);
		owa_coreAPI::registerStateStore('s', time()+3600*24*365*10, '', 'assoc', 'cookie', true);
		owa_coreAPI::registerStateStore('b', null, '', 'json', 'cookie', true);
		$cwindow = owa_coreAPI::getSetting( 'base', 'campaignAttributionWindow' );
		owa_coreAPI::registerStateStore('c', time() + 3600 * 24 * $cwindow , '', 'json', 'cookie', true);
		
		$this->organicSearchEngines = array(
		
			array('d' => 'google', 'q' => 'q'),
			array('d' => 'yahoo', 'q' => 'p'),
			array('d' => 'msn', 'q' => 'q'),
			array('d' => 'bing', 'q' => 'q'),
			array('d' => 'images.google', 'q' => 'q'),
			array('d' => 'images.search.yahoo.com', 'q' => 'p'),
			array('d' => 'aol', 'q' => 'query'),
			array('d' => 'aol', 'q' => 'encquery'),
			array('d' => 'aol', 'q' => 'q'),
			array('d' => 'lycos', 'q' => 'query'),
			array('d' => 'ask', 'q' => 'q'),
			array('d' => 'altavista', 'q' => 'q'),
			array('d' => 'netscape', 'q' => 'query'),
			array('d' => 'cnn', 'q' => 'query'),
			array('d' => 'about', 'q' => 'terms'),
			array('d' => 'mamma', 'q' => 'q'),
			array('d' => 'daum', 'q' => 'q'),
			array('d' => 'eniro', 'q' => 'search_word'),
			array('d' => 'naver', 'q' => 'query'),
			array('d' => 'pchome', 'q' => 'q'),
			array('d' => 'alltheweb', 'q' => 'q'),
			array('d' => 'voila', 'q' => 'rdata'),
			array('d' => 'virgilio', 'q' => 'qs'),
			array('d' => 'live', 'q' => 'q'),
			array('d' => 'baidu', 'q' => 'wd'),
			array('d' => 'alice', 'q' => 'qs'),
			array('d' => 'yandex', 'q' => 'text'),
			array('d' => 'najdi', 'q' => 'q'),
			array('d' => 'mama', 'q' => 'query'),
			array('d' => 'seznam', 'q' => 'q'),
			array('d' => 'search', 'q' => 'q'),
			array('d' => 'wp', 'q' => 'szukaj'),
			array('d' => 'onet', 'q' => 'qt'),
			array('d' => 'szukacz', 'q' => 'q'),
			array('d' => 'yam', 'q' => 'k'),
			array('d' => 'kvasir', 'q' => 'q'),
			array('d' => 'sesam', 'q' => 'q'),
			array('d' => 'ozu', 'q' => 'q'),
			array('d' => 'terra', 'q' => 'query'),
			array('d' => 'mynet', 'q' => 'q'),
			array('d' => 'ekolay', 'q' => 'q'),
			array('d' => 'rambler', 'q' => 'query'),
			array('d' => 'rambler', 'q' => 'words')
		);
	}
	
	public function setPageTitle($value) {
		$this->pageview_event->set('page_title', $value);
	}
	
	public function setPageType($value) {
		$this->pageview_event->set('page_type', $value);
	}
	
	public function setProperty($name, $value) {
		$this->setGlobalEventProperty($name, $value);
	}
	
	private function setGlobalEventProperty($name, $value) {
		
		$this->global_event_properties[$name] = $value;
	}
	
	private function getGlobalEventProperty($name) {
		
		if ( array_key_exists($name, $this->global_event_properties) ) {
			return $this->global_event_properties[$name];
		}
	}
	
	private function deleteGlobalEventProperty( $name ) {
		
		if ( array_key_exists($name, $this->global_event_properties) ) {
			unset($this->global_event_properties[$name]);
		}
	}
				
	private function manageState( &$event ) {
		
		if ( ! $this->stateInit ) {
		
			$this->setVisitorId( $event );
			$this->setFirstSessionTimestamp( $event );
			$this->setLastRequestTime( $event );
			$this->setSessionId( $event );
			$this->setNumberPriorSessions( $event );
			$this->setDaysSinceLastSession( $event );
			$this->setTrafficAttribution( $event );
			$this->stateInit = true;
		}
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
			$this->setGlobalEventProperty( 'is_new_visitor', true );
			owa_coreAPI::setState( 'v', 'vid', $visitor_id, 'cookie', true );
		}
		// set property on event object
		$this->setGlobalEventProperty( 'visitor_id', $visitor_id );
	}
	
	private function setNumberPriorSessions( &$event ) {
		// if check for nps value in vistor cookie.
		$nps = owa_coreAPI::getStateParam('v', 'nps');
		// set value to 0 if not found.
		if (!$nps) {
			$nps = 0;
		}
		
		// if new session, increment visit count and persist to state store
		if ( $this->getGlobalEventProperty('is_new_session' ) ) {
			owa_coreAPI::setState('v', 'nps', $nps + 1, 'cookie', true);
		}
		
		// set property on the event object
		$this->setGlobalEventProperty('nps', $nps);
	}
	
	private function setFirstSessionTimestamp( &$event ) {
		
		$fsts = owa_coreAPI::getStateParam( 'v', 'fsts' );
		
		if ( ! $fsts ) {
			$fsts = $event->get('timestamp');
			owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), 'fsts', $fsts , 'cookie', true);	
		}
		
		$this->setGlobalEventProperty( 'fsts', $fsts );
		
		// calc days since first session
		$dsfs = round( ( $fsts - $event->get( 'timestamp' ) ) / ( 3600 * 24 ) ) ;
		owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), 'dsfs', $dsfs , 'cookie', true);
		$this->setGlobalEventProperty( 'dsfs', $dsfs );
	}
	
	private function setDaysSinceLastSession( &$event ) {
		
		owa_coreAPI::debug('setting days since last session.');
		$dsps = '';
		if ( $this->getGlobalEventProperty( 'is_new_session' ) ) {
			owa_coreAPI::debug( 'timestamp: ' . $event->get( 'timestamp' ) );
			$last_req = $this->getGlobalEventProperty( 'last_req' );
			if ( ! $last_req ) {
				$last_req = $event->get( 'timestamp' );
			}
			owa_coreAPI::debug( 'last_req: ' . $last_req );
			$dsps = round( ( $event->get( 'timestamp' ) - $last_req ) / ( 3600*24 ) );
			owa_coreAPI::setState('s', 'dsps', $dsps , 'cookie', true);
		}
		
		if ( ! $dsps ) {
			$dsps = owa_coreAPI::getState( 's', 'dsps' );
			
			if ( ! $dsps ) {
				$dsps = 0;
			}
		}
		
		$this->setGlobalEventProperty( 'dsps', $dsps );
	}
	
	private function setSessionId( &$event ) {
	
		$is_new_session = $this->isNewSession( $event->get( 'timestamp' ),  $this->getGlobalEventProperty( 'last_req' ) ); 
		if ( $is_new_session ) {
			//set prior_session_id
			$prior_session_id = owa_coreAPI::getStateParam('s', 'sid');
			if ( ! $prior_session_id ) {
				$state_store_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $this->site_id);		
				$prior_session_id = owa_coreAPI::getStateParam($state_store_name, 's');
			}
			if ($prior_session_id) {
				$this->setGlobalEventProperty( 'prior_session_id', $prior_session_id );
			}
			
			$this->resetSessionState();
			
			$session_id = $event->getSiteSpecificGuid( $this->site_id );
			
	   		//mark new session flag on current request
			$this->setGlobalEventProperty( 'is_new_session', true );
			owa_coreAPI::setState( 's', 'sid', $session_id, 'cookie', true );
			
		} else {
			// Must be an active session so just pull the session id from the state store
			$session_id = owa_coreAPI::getStateParam('s', 'sid');
			
			// support for old style cookie
			if ( ! $session_id ) {
				$state_store_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $this->site_id);		
				$session_id = owa_coreAPI::getStateParam($state_store_name, 's');
				
			}
			
			// fail-safe just in case there is no session_id 
			if ( ! $session_id ) {
				$session_id = $event->getSiteSpecificGuid( $this->site_id );
				//mark new session flag on current request
				$this->setGlobalEventProperty( 'is_new_session', true );
				owa_coreAPI::debug('setting failsafe session id');
			}
		}
		
		// set global event property 
	   	$this->setGlobalEventProperty( 'session_id', $session_id );
		// set sid state
		owa_coreAPI::setState( 's', 'sid', $session_id, 'cookie', true );
	}
	
	private function setLastRequestTime( &$event ) {
	
		$last_req = owa_coreAPI::getStateParam('s', 'last_req');
		
		// suppport for old style cookie
		if ( ! $last_req ) {
			$state_store_name = sprintf( '%s_%s', owa_coreAPI::getSetting( 'base', 'site_session_param' ), $this->site_id );		
			$last_req = owa_coreAPI::getStateParam( $state_store_name, 'last_req' );	
		}
		// set property on event object
		$this->setGlobalEventProperty( 'last_req', $last_req );
		owa_coreAPI::debug("setting last_req value of $last_req as global event property.");
		// store new state value
		owa_coreAPI::setState( 's', 'last_req', $event->get( 'timestamp' ), 'cookie', true );
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
	 * Logs tracking event from url params taken from request scope.
	 * Takes event type from url.
	 *
	 * @return unknown
	 */
	function logEventFromUrl($manage_state = false) {
		
		// keeps php executing even if the client closes the connection
		ignore_user_abort(true);
		$service = owa_coreAPI::serviceSingleton();
		$service->request->decodeRequestParams();
		$event = owa_coreAPI::supportClassFactory('base', 'event');
		$event->setEventType(owa_coreAPI::getRequestParam('event_type'));
		$event->setProperties($service->request->getAllOwaParams());
		
		// check for third party cookie mode.
		$mode = owa_coreAPI::getRequestParam('thirdParty');
		if ( $mode ) {
			return $this->trackEvent($event);
		} else {
			return owa_coreAPI::logEvent($event->getEventType(), $event);
		}
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
		
		// do not track anything if user is in overlay mode
		if (owa_coreAPI::getStateParam('overlay')) {
			return false;
		}
		
		$this->setGlobalEventProperty( 'HTTP_REFERER', owa_coreAPI::getServerParam('HTTP_REFERER') );
		
		// needed by helper page tags function so it can append to first hit tag url	
		if (!$this->getSiteId()) {
			$this->setSiteId($event->get('site_id'));
		}
		
		if (!$this->getSiteId()) {
			$this->setSiteId(owa_coreAPI::getRequestParam('site_id'));
		}
		
		// set various state properties.
		$this->manageState( $event );
		
		$event = $this->setAllGlobalEventProperties( $event );
		
		// send event to log API for processing.
		return owa_coreAPI::logEvent($event->getEventType(), $event);
	}
	
	public function setAllGlobalEventProperties( $event ) {
		
		if ( ! $event->get('site_id') ) {
			$event->set( 'site_id', $this->getSiteId() );
		}
		
		// add custom variables to global properties if not there already
    	for ( $i=1; $i <= owa_coreAPI::getSetting('base', 'maxCustomVars'); $i++ ) {
    		$cv_param_name = 'cv' . $i;
    		$cv_value = '';
    		
    		// if the custom var is not already a global property
    		if ( ! $this->getGlobalEventProperty( $cv_param_name ) ) {
    			// check to see if it exists
    			$cv_value = $this->getCustomVar( $i );
    			// if so add it
    			if ( $cv_value ) {
    				$this->setGlobalEventProperty( $cv_param_name, $cv_value );
    			}
    		}
    	}
		
		// merge global event properties
		$event->setNewProperties( $this->global_event_properties );
		
		return $event;
		
	}
	
	public function getAllEventProperties( $event ) {
		
		$event = $this->setAllGlobalEventProperties( $event );
		return $event->getProperties();
	}
		
	public function trackPageview($event = '') {
		
		if ($event) {
			$event->setEventType('base.page_request');
			$this->pageview_event = $event;
		}
		return $this->trackEvent($this->pageview_event);
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
	 * Creates a ecommerce Transaction event
	 *
	 * Creates a parent commerce.transaction event
	 */
	public function addTransaction( 
			$order_id, 
			$order_source = '', 
			$total = 0, 
			$tax = 0, 
			$shipping = 0, 
			$gateway = '', 
			$country = '', 
			$state = '', 
			$city = '',
			$page_url = '', 
			$session_id = ''
		) {
		
		$this->commerce_event = $this->makeEvent();
		$this->commerce_event->setEventType( 'ecommerce.transaction' );
		$this->commerce_event->set( 'ct_order_id', $order_id );
		$this->commerce_event->set( 'ct_order_source', $order_source );
		$this->commerce_event->set( 'ct_total', $total );
		$this->commerce_event->set( 'ct_tax', $tax );
		$this->commerce_event->set( 'ct_shipping', $shipping );
		$this->commerce_event->set( 'ct_gateway', $gateway );
		$this->commerce_event->set( 'page_url', $page_url );
		$this->commerce_event->set( 'ct_line_items', array() );
		$this->commerce_event->set( 'country', $country );
		$this->commerce_event->set( 'state', $state );
		$this->commerce_event->set( 'city', $city );
		if ( $session_id ) {
			$this->commerce_event->set( 'original_session_id', $session_id );
			// tells the client to NOT manage state properties as we are
			// going to look them up from the session later.
			$this->commerce_event->set( 'is_state_set', true );
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
		
		$li = array();
		$li['li_order_id'] = $order_id ;
		$li['li_sku'] = $sku ;
		$li['li_product_name'] = $product_name ;
		$li['li_category'] = $category ;
		$li['li_unit_price'] = $unit_price ;
		$li['li_quantity'] = $quantity ;
		
		$items = $this->commerce_event->get( 'ct_line_items' );
		$items[] = $li;
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
	
	public function setCampaignNameKey( $key ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_params[ 'campaign' ] = $key;
		owa_coreAPI::setSetting('base', 'campaign_params', $campaign_params);
	}
	
	public function setCampaignMediumKey( $key ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_params[ 'medium' ] = $key;
		owa_coreAPI::setSetting('base', 'campaign_params', $campaign_params);
	}
	
	public function setCampaignSourceKey( $key ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_params[ 'source' ] = $key;
		owa_coreAPI::setSetting('base', 'campaign_params', $campaign_params);
	}
	
	public function setCampaignSearchTermsKey( $key ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_params[ 'search_terms' ] = $key;
		owa_coreAPI::setSetting('base', 'campaign_params', $campaign_params);
	}
	
	public function setCampaignAdKey( $key ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_params[ 'ad' ] = $key;
		owa_coreAPI::setSetting('base', 'campaign_params', $campaign_params);
	}
	
	public function setCampaignAdTypeKey( $key ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_params[ 'ad_type' ] = $key;
		owa_coreAPI::setSetting('base', 'campaign_params', $campaign_params);
	}
	
	public function setUserName( $value ) {
		
		$this->setGlobalEventProperty( 'user_name', $value );
	}
	
	function getCampaignProperties( $event ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_properties = array();
		$campaign_state = array();
		$request = owa_coreAPI::getRequest();
		foreach ( $campaign_params as $k => $param ) {
			//look for property on the event
			$property = $event->get( $param );
			
			// look for property on the request scope.
			if ( ! $property ) {
				$property = $request->getRequestParam( $param );	
			}
			if ( $property ) {
				$campaign_properties[ $k ] = $property;
			}
		}
	
		// backfill values for incomplete param combos
		
		if (array_key_exists('ad_type', $campaign_properties) && !array_key_exists('ad', $campaign_properties)) {
			$campaign_properties['ad'] = '(not set)';
		}
		
		if (array_key_exists('ad', $campaign_properties) && !array_key_exists('ad_type', $campaign_properties)) {
			$campaign_properties['ad_type'] = '(not set)';
		}
		
		if (!empty($campaign_properties)) {
			//$campaign_properties['ts'] = $event->get('timestamp');
		}
		
		owa_coreAPI::debug('campaign properties: '. print_r($campaign_properties, true));
		
		return $campaign_properties;
	}
	
	private function setCampaignSessionState( $properties ) {
		
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		foreach ($campaign_params as $k => $v) {
			
			if (array_key_exists( $k, $properties ) ) {
			
				owa_coreAPI::setState( 's', $k, $properties[$k] );
			}
		}
	}
	
	function directAttributionModel( &$campaign_properties ) {
	
		// add new campaign info to existing campaign cookie.
		if ( $campaign_properties ) {
			
			// get prior campaing touches from c cookie			
			$campaign_state = $this->getCampaignState();
			
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
			$this->isTrafficAttributed = true;
			
			// persist state to session store
			$this->setCampaignSessionState( $campaign_properties );
		}

	}
	
	function originalAttributionModel( &$campaign_properties ) {
	
		$campaign_state = $this->getCampaignState();
		// orignal touch was set previously. jus use that.
		if (!empty($campaign_state)) {
			// do nothing
			// set the attributes from the first campaign touch
			$campaign_properties = $campaign_state[0];
			$this->isTrafficAttributed = true;
	
		// no orginal touch, set one if it's a new campaign touch
		} else {
			
			if (!empty($campaign_properties)) {
				// add timestamp
				//$campaign_properties['ts'] = $event->get('timestamp');
				owa_coreAPI::debug('Setting original Campaign attrbution.');
				$campaign_state[] = $campaign_properties;
				// set cookie
				$this->setCampaignCookie($campaign_state);
				$this->isTrafficAttributed = true;
			}
		}
		
		// persist state to session store
		$this->setCampaignSessionState( $campaign_properties );
	}
	
	function getCampaignState() {
		
		$campaign_state = owa_coreAPI::getState( 'c', 'attribs' );
		if ( ! $campaign_state ) {

			$campaign_state = array();
		}
		
		return $campaign_state;
	}
	
	function setTrafficAttribution( &$event ) {
		
		// if not then look for individual campaign params on the request. 
		// this happens when the client is php and the params are on the url
		$campaign_properties = $this->getCampaignProperties( $event );
		if ( $campaign_properties ) {
			$campaign_properties['ts'] = $event->get('timestamp');			
		}

		// choose attribution model.	
		$model = owa_coreAPI::getSetting('base', 'trafficAttributionMode');
		switch ( $model ) {
			
			case 'direct':
				owa_coreAPI::debug( 'Applying "Direct" Traffic Attribution Model' );
				$this->directAttributionModel( $campaign_properties );
				break;
			case 'original':
				owa_coreAPI::debug( 'Applying "Original" Traffic Attribution Model' );
				$this->originalAttributionModel( $campaign_properties );
				break;
			default:
				owa_coreAPI::debug( 'Applying Default (Direct) Traffic Attribution Model' );
				$this->directAttributionModel( $campaign_properties );
		}
		
		// if one of the attribution methods attributes the traffic them
		// set attribution properties on the event object	
		if ( $this->isTrafficAttributed ) {
			
			owa_coreAPI::debug( 'Attributing Traffic to: %s', print_r($campaign_properties, true ) );
					
		} else {
			// infer the attribution from the referer
			// if the request is the start of a new session
			if ( $this->getGlobalEventProperty( 'is_new_session' ) ) {
				owa_coreAPI::debug( 'Infering traffic attribution.' );
				$this->inferTrafficAttribution();
			}
		}
		
		// apply traffic attribution realted properties to events
		// all properties should be set in the state store by this point.
		$campaign_params = owa_coreAPI::getSetting('base', 'campaign_params');
		foreach( $campaign_params as $k => $v ) {
		
			$value = owa_coreAPI::getState( 's', $k );
			
			if ( $value ) { 
				$this->setGlobalEventProperty( $k, $value );
			}
		}
		
		// set sesion referer
		$session_referer = owa_coreAPI::getState('s', 'referer'); 
		if ( $session_referer ) {
			
			$this->setGlobalEventProperty( 'session_referer', $session_referer );
		}

		// set campaign touches
		$campaign_state = owa_coreAPI::getState('c', 'attribs');
		if ( $campaign_state ) {
			
			$this->setGlobalEventProperty( 'attribs', json_encode( $campaign_state ));
		}
	}
	
	private function inferTrafficAttribution() {
	
		$ref = owa_coreAPI::getServerParam('HTTP_REFERER');
		$medium = 'direct';
		$source = '(none)';
		$search_terms = '(none)';
		$session_referer = '(none)';
		
		if ( $ref ) {
			$uri = owa_lib::parse_url( $ref );
			
			// check for external referer
			$host = owa_coreAPI::getServerParam('HTTP_HOST');
			if ( $host != $uri['host'] ) {			
						
				$medium = 'referral';
				$source = owa_lib::stripWwwFromDomain( $uri['host'] );
				$engine = $this->isRefererSearchEngine( $uri );
				$session_referer = $ref;
				if ( $engine ) {
					$medium = 'organic-search';
					$search_terms = $engine['t'];
				} 
			}
		}
		
		owa_coreAPI::setState('s', 'referer', $session_referer);
		owa_coreAPI::setState('s', 'medium', $medium);
		owa_coreAPI::setState('s', 'source', $source);
		owa_coreAPI::setState('s', 'search_terms', $search_terms);
	}
	
	private function isRefererSearchEngine( $uri ) {
		
		if ( isset( $uri['host'] ) ) {
			$host = $uri['host'];
		} else {
			return;
		}
		
		foreach ( $this->organicSearchEngines as $engine ) {
			
			$domain = $engine['d'];
			$query_param = $engine['q'];
			$term = '';
			
			if ( isset ($uri['query_params'][$query_param] ) ) {
				$term = $uri['query_params'][$query_param];
			}
			
			if ( strpos($host, $domain) ) {
				owa_coreAPI::debug( 'Found search engine: %s with query param %s:, query term: %s', $domain, $query_param, $term);
				
				return array('d' => $domain, 'q' => $query_param, 't' => $term );
			}
		}
	}
	
	function setCampaignCookie($values) {
		// reset state
		owa_coreAPI::setState('c', 'attribs', $values);
	}
	
	// sets cookies domain
	function setCookieDomain($domain) {
		
		if (!empty($domain)) {
			$c = owa_coreAPI::configSingleton();
			// sanitizes the domain
			$c->setCookieDomain($domain);
		}
	}
	
	/**
	 * Set a custom variable
	 *
	 * @param	slot	int		the identifying number for the custom variable. 1-5.
	 * @param	name	string	the key of the custom variable.
	 * @param	value	string	the value of the varible
	 * @param	scope	string	the scope of the variable. can be page, session, or visitor
	 */
	public function setCustomVar( $slot, $name, $value, $scope = '' ) {
		
		$cv_param_name = 'cv' . $slot;
		$cv_param_value = $name . '=' . $value;
		
		if ( strlen( $cv_param_value ) > 65 ) {
			owa_coreAPI::debug('Custom variable name + value is too large. Must be less than 64 characters.');
			return;
		}
				
		switch ( $scope ) {
			
			case 'session':
				
				// store in session cookie
				owa_coreAPI::setState( 'b', $cv_param_name, $cv_param_value );
				owa_coreAPI::debug( 'just set custom var on session.' );
				break;
				
			case 'visitor':
				
				// store in visitor cookie
				owa_coreAPI::setState( 'v', $cv_param_name, $cv_param_value );
				// remove slot from session level cookie
				owa_coreAPI::clearState( 'b', $cv_param_name );
				break;
		}
		
		$this->setGlobalEventProperty( $cv_param_name, $cv_param_value );	
	}
	
	public function getCustomVar( $slot ) {
		
		$cv_param_name = 'cv' . $slot;
		$cv = '';
		// check request/page level
		$cv = $this->getGlobalEventProperty( $cv_param_name );
		//check session store
		if ( ! $cv ) {
			$cv = owa_coreAPI::getState( 'b', $cv_param_name );
		}
		// check visitor store
		if ( ! $cv ) {
			$cv = owa_coreAPI::getState( 'v', $cv_param_name );
		}
		
		return $cv;
		
	}
	
	public function deleteCustomVar( $slot ) {
		
		$cv_param_name = 'cv' . $slot;
		//clear session level
		owa_coreAPI::clearState( 'b', $cv_param_name );
		//clear visitor level
		owa_coreAPI::clearState( 'v', $cv_param_name );
		// clear page level
		$this->deleteGlobalEventProperty( $cv_param_name );
		
		owa_coreAPI::debug("Deleting custom variable named $cv_param_name in slot $slot.");
	}
	
	private function resetSessionState() {
		
		$last_req = owa_coreAPI::getState( 's', 'last_req' );
		owa_coreAPI::clearState( 's' );
		owa_coreAPI::setState( 's', 'last_req', $last_req);
	}
	
	public function addOrganicSearchEngine( $domain, $query_param, $prepend = '' ) {
		
		$engine = array('d' => $domain, 'q' => $query_param);
		if ( $prepend) {
			array_unshift($this->organicSearchEngines, $engine );
		} else {
				$this->organicSearchEngines[] = $engine;
		}
	}
}

?>
