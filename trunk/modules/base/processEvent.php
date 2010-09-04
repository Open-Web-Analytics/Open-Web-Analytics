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
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'ini_db.php');

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
			
		// set site id if not already set. 
		if (!$this->event->get('site_id')) {
			$this->event->set('site_id', owa_coreAPI::getSetting('base', 'site_id'));	
		}
		
		// Set all time related properties
		$this->event->setTime(owa_coreAPI::getServerParam('REQUEST_TIME'));
		
		// extract site specific state from session store
		$state = $this->loadSiteSessionState($this->event->get('site_id'));
		
		// TODO:Map standard params to standard event property names so we can do a merge of the entire site session state store
		$this->event->set('inbound_session_id', $state[owa_coreAPI::getSetting('base', 'session_param')]);
		
		// last request timestamp
		$this->event->set('last_req', $state[owa_coreAPI::getSetting('base', 'last_request_param')]);
		
		// set inbound visitor id
		$vstate = owa_coreAPI::getStateParam(owa_coreAPI::getSetting('base', 'visitor_param'), 'vid');
		
		if (!$vstate) {
			// look for old style cookie
			$vstate = owa_coreAPI::getStateParam(owa_coreAPI::getSetting('base', 'visitor_param'));
			// if we find one, then rewrite cookie to new format using vid param
			if (!empty($vstate)) {
				// reset the cookie to new style		
				owa_coreAPI::clearState(owa_coreAPI::getSetting('base', 'visitor_param'));
				owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), 'vid', $vstate, 'cookie', true);
			}			
		}
		
		$this->event->set('inbound_visitor_id', $vstate);
		
		// set visitor type flag if inbound visitor ID is found.		
		if ($this->event->get('inbound_visitor_id')) {
			$this->event->set('is_repeat_visitor', true);
			$this->event->set('visitor_id', $this->event->get('inbound_visitor_id'));
		} else {
			$this->event->set('is_new_visitor', true);
		}
		
		//set user agent
		if (!$this->event->get('HTTP_USER_AGENT')) {
			$this->event->set('HTTP_USER_AGENT', owa_coreAPI::getServerParam('HTTP_USER_AGENT'));
		} 
		
		$this->event->set( 'HTTP_USER_AGENT', $this->eq->filter( 'user_agent', $this->event->get( 'HTTP_USER_AGENT' ) ) );
		//set user agent id
		$this->event->set( 'ua_id', owa_lib::setStringGuid( $this->event->get( 'HTTP_USER_AGENT' ) ) );
		
		// set referer
		// needed in case javascript logger sets the referer variable but is blank
		if ($this->event->get('referer')) {
			//TODO: STANDARDIZE NAME to avoid doing this map
			$referer = $this->event->get('referer');
		} else {
			owa_coreAPI::debug('ref: '.owa_coreAPI::getServerParam('HTTP_REFERER'));
			$referer = owa_coreAPI::getServerParam('HTTP_REFERER');
		}
		
		$this->event->set('HTTP_REFERER', $this->eq->filter('http_referer', $referer));
		
		// set host
		if (!$this->event->get('HTTP_HOST')) {
			$this->event->set('HTTP_HOST', owa_coreAPI::getServerParam('HTTP_HOST'));
		}
		
		// set language
		if (!$this->event->get( 'language' ) ) {
			$this->event->set( 'language', $this->eq->filter('language', substr(owa_coreAPI::getServerParam( 'HTTP_ACCEPT_LANGUAGE' ),0,5 ) ) );
		}
		
		$this->event->set('HTTP_HOST', $this->eq->filter('http_host', $this->event->get('HTTP_HOST')));
		
		// set page type to unknown if not already set by caller
		if (!$this->event->get('page_type')) {
			$this->event->set('page_type', $this->getMsg(3600));
		} 
		
		$this->event->set('page_type', $this->eq->filter('page_type', $this->event->get('page_type')));
		
		// Set the page url or else construct it from environmental vars
		if (!$this->event->get('page_url')) {
			$this->event->set('page_url', owa_lib::get_current_url());
		}
		
		$this->event->set( 'page_url', $this->eq->filter( 'page_url', $this->event->get( 'page_url' ) ) );
		// set document/page id
		$this->event->set( 'document_id', owa_lib::setStringGuid( $this->event->get( 'page_url' ) ) );
		// needed?
		$this->event->set('inbound_page_url', $this->event->get('page_url'));
		
		// Filter page title if set
		if ($this->event->get('page_title')) {
			$this->event->set('page_title', $this->eq->filter('page_title', trim($this->event->get('page_title'))));
		}
		
		$page_parse = parse_url($this->event->get('page_url'));
		
		if (!array_key_exists('path', $page_parse) || empty($page_parse['path'])) {
			$page_parse['path'] = '/';
		}
		
		if (!$this->event->get('page_uri')) {
		
			if (array_key_exists('query', $page_parse) || !empty($page_parse['query'])) {
				$this->event->set('page_uri', $this->eq->filter('page_uri', sprintf('%s?%s', $page_parse['path'], $page_parse['query'])));	
			} else {
				$this->event->set('page_uri', $this->eq->filter('page_uri', $page_parse['path']));
			}
			
		}
				
		// set internal referer
		if ($this->event->get('HTTP_REFERER')) {

			$referer_parse = parse_url($this->event->get('HTTP_REFERER'));

			if ($referer_parse['host'] === $page_parse['host']) {
				$this->event->set('prior_page', $this->eq->filter('prior_page', $this->event->get('HTTP_REFERER')));	
			} else {
				
				$this->event->set('external_referer', true);
				$this->event->set('referer_id', owa_lib::setStringGuid($this->event->get('HTTP_REFERER' ) ) );
			
				if ( ! $this->event->get( 'search_terms' ) ) {
					// set query terms
					$qt = $this->extractSearchTerms($this->event->get('HTTP_REFERER'));
					
					if ($qt) {
						$this->event->set('search_terms', trim( strtolower( $qt ) ) );	
					}
				}				
			}
		}
		
		// set referring search term id		
		if ($this->event->get( 'search_terms' ) ) {
			$this->event->set('referring_search_term_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get('search_terms') ) ) ) );
		}
				
		// Filter the target url of clicks
		if ($this->event->get('target_url')) {
			$this->event->set('target_url', $this->eq->filter('target_url', $this->event->get('target_url')));
		}
		
		// Set Ip Address
		if (!$this->event->get('ip_address')) {
			$this->event->set('ip_address', owa_coreAPI::getServerParam('REMOTE_ADDR'));
		}
		
		$this->event->set('ip_address', $this->eq->filter('ip_address', $this->event->get('ip_address')));
		
		// Set host related properties
		if (!$this->event->get('REMOTE_HOST')) {
			$this->event->set('REMOTE_HOST', owa_coreAPI::getServerParam('REMOTE_HOST'));
		}
		// host properties
		$this->event->set( 'full_host', $this->eq->filter( 'full_host', 
				$this->event->get( 'REMOTE_HOST' ), 
				$this->event->get( 'ip_address' ) ) );
				
		$this->event->set( 'host', $this->eq->filter( 'host', $this->event->get( 'full_host' ), $this->event->get( 'ip_address' ) ) );
		// Generate host_id
		$this->event->set( 'host_id',  owa_lib::setStringGuid( $this->event->get( 'full_host' ) ) );
		
		// Browser related properties
		$service = owa_coreAPI::serviceSingleton();
		$bcap = $service->getBrowscap();
		
		// Assume browser untill told otherwise
		$this->event->set('is_browser',true);
		
		$this->event->set('browser_type', $this->eq->filter('browser_type', $bcap->get('Browser')));
		
		if ($bcap->get('Version')) {
			$this->event->set('browser', $this->eq->filter('browser', $bcap->get('Browser') . ' ' . $bcap->get('Version')));
		} else {
			$this->event->set('browser', $this->eq->filter('browser', $bcap->get('Browser')));
		}
	
		// Set Operating System
		$this->event->set( 'os', $this->eq->filter( 'operating_system', $bcap->get( 'Platform' ), $this->event->get( 'HTTP_USER_AGENT' ) ) );
		$this->event->set( 'os_id', owa_lib::setStringGuid( $this->event->get( 'os' ) ) );
		
		//Check for what kind of page request this is
		if ($bcap->get('Crawler')) {
			$this->event->set('is_robot', true);
			$this->event->set('is_browser', false);

		}
		
		// feed request properties
		$et = $this->event->getEventType();
		if ($et === 'base.feed_request') {
			
			// Feed subscription tracking code
			if (!$this->event->get('feed_subscription_id')) {
				$this->event->set('feed_subscription_id', $this->getParam(owa_coreAPI::getSetting('base', 'feed_subscription_param')));
			}
			
			// needed??
			$this->event->set('feed_reader_guid', $this->event->setEnvGUID());
			// set feedreader flag to true, browser flag to false
			$this->event->set('is_feedreader', true);
			$this->event->set('is_browser', false);
		}
		
		// record and filter visitor personally identifiable info (PII)		
		if (owa_coreAPI::getSetting('base', 'log_visitor_pii')) {
			
			$cu = owa_coreAPI::getCurrentUser();
			
			// set user name
			$this->event->set('user_name', $this->eq->filter('user_name', $cu->user->get('user_id')));
			
			// set email_address
			if ($this->event->get('email_address')) {
				$email_adress = $this->event->get('email_address');
			} else {
				$email_address = $cu->user->get('email_address');
			}
			
			$this->event->set('user_email', $this->eq->filter('user_email', $email_address));
		}
		
		//read the num prior session value from cookie. set to 0 if false.
		$nps = owa_coreAPI::getStateParam('v', 'nps');
		if (!$nps) {
			$nps = 0;
		}
		//set the value on the event
		$this->event->set('num_prior_sessions', $nps);
	}
	
	function post() {
			
		return $this->addToEventQueue();
	
	}
	
	function loadSiteSessionState($site_id) {
		
		$state_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $site_id);		
		return owa_coreAPI::getStateParam($state_name);

	}
	
	function setSiteSessionState($site_id, $name, $value) {
		
		$state_name = sprintf('%s_%s', owa_coreAPI::getSetting('base', 'site_session_param'), $site_id);		
		return owa_coreAPI::setState($state_name, $name, $value, 'cookie', true);
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
			
		if (!$this->event->get('do_not_log')) {
			// pass event to handlers but filter it first
			$this->eq->asyncNotify($this->eq->filter('post_processed_tracking_event', $this->event));
			return owa_coreAPI::debug('Logged '.$this->event->getEventType().' to event queue with properties: '.print_r($this->event->getProperties(), true));
		} else {
			owa_coreAPI::debug("Not logging event due to 'do not log' flag being set.");
		}

	}
	
	/**
	 * Creates new visitor
	 * 
	 * @access 	private
	 *
	 */
	function setNewVisitor() {
		
		// Create guid
        $this->event->set('visitor_id', $this->getSiteSpecificGuid());
		
        // Set visitor state
        // state for this must be maintained in a cookie
        owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), 'vid', $this->event->get('visitor_id'), 'cookie', true);
        owa_coreAPI::setState(owa_coreAPI::getSetting('base', 'visitor_param'), 'fsts', $this->event->get('timestamp'), 'cookie', true);
        
        
	}
	
	function getSiteSpecificGuid() {
		
		return crc32(getmypid().time().rand().$this->event->get('site_id'));
		
	}
	
	/**
	 * Parses query terms from referer
	 *
	 * @param string $referer
	 * @return string
	 * @access private
	 */
	function extractSearchTerms($referer) {
	
		/*	Look for query_terms */
		$db = new ini_db(owa_coreAPI::getSetting('base', 'query_strings.ini'));
		
		$match = $db->match($referer);
		
		if (!empty($match[1])) {
		
			return trim(strtolower(urldecode($match[1])));
		
		}
	}
	
	function attributeTraffic() {
		
		// if not then look for individual campaign params on the request. 
		// this happens when the client is php and the params are on the url
		$campaign_params = owa_coreAPI::getSetting( 'base', 'campaign_params' );
		$campaign_properties = array();
		$campaign_state = array();
		foreach ($campaign_params as $k => $param) {
			if ( $this->getParam( $param ) ) {
				$campaign_properties[$k] = $this->getParam( $param );
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
				$this->event->set('is_attributed', true);
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
				$this->event->set('is_attributed', true);
		
			// no orginal touch, set one if it's a new campaign touch
			} else {
				
				if (!empty($campaign_properties)) {
					owa_coreAPI::debug('Setting original Campaign attrbution.');
					$campaign_state[] = $campaign_properties;
					// set cookie
					$this->setCampaignCookie($campaign_state);
					$this->event->set('is_attributed', true);
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
			}		
		}
			
		// if no campaign attribution look for standard medium/source:
		// organic-search, referral, direct
		if (!$this->event->get('is_attributed')) {
		
			// if there is an external referer
			if ( $this->event->get( 'external_referer' ) ) {
		
				// see if its from a search engine
				if ($this->event->get( 'search_terms' ) ) {
					$attribution['medium'] = 'organic-search';
					// put the domain here.
					$attribution['source'] = $this->getDomainFromUrl($this->get('external_referer')); //????
				} else {
					// assume its a plain old referral
					$attribution['medium'] = 'referral';
					// put the domain here.
					$attribution['source'] = $this->getDomainFromUrl($this->get('external_referer')); //????
				}
			} else {
				// set as direct
				$attribution['medium'] = 'direct';
			}
			
			$this->event->set('is_attributed', true);
		}
		
		$this->event->set('medium', $attribution['medium']);
		$this->event->set('source', $attribution['source']);
		
		if (!empty($attribution['campaign'])) {
			$this->event->set('campaign', $attribution['campaign']);
		}
		
		if (!empty($attribution['ad'])) {
			$this->event->set('ad', $attribution['ad']);
		}
		
		if (!empty($attribution['ad_type'])) {
			$this->event->set('ad_type', $attribution['ad_type']);
		}
		
		if (!empty($attribution['search_terms'])) {
			$this->event->set('search_terms', $attribution['search_terms']);
		}
		
		$this->event->set('attribs', owa_coreAPI::getStateParam('c'));
	}
	
	function setCampaignCookie($values) {
		// reset state
		owa_coreAPI::setState('c', '', 
				json_encode( $values ), 
				'cookie', 
				owa_coreAPI::getSetting( 'base', 'campaign_attribution_window' ) );
	}
	
	function getDomainFromUrl($url, $strip_www = true) {
		
		$split_url = preg_split('/\/+/g', $url);
		$domain = $split_url[1];
		if ($strip_www === true) {
			$domain_parts = explode('.', $domain);
			$fp = $domain_parts[0];
			if ($fp === 'www') {
				return substring($domain, 4);
			} else {
				return $domain;
			}
			
		} else {
			return $domain;
		}
	}	
}


?>