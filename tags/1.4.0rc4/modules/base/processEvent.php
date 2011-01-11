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
			
		// Set all time related properties
		$this->event->setTime();
			
		// set repeat visitor type flag visitor is not new.		
		if ( ! $this->event->get( 'is_new_visitor' ) ) {
			$this->event->set( 'is_repeat_visitor', true );
		} else {
			// properly cast this to a bool.
			$this->event->set( 'is_new_visitor', true );
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
			$this->event->set('page_type', '(not set)');
		} 
		
		$this->event->set('page_type', $this->eq->filter('page_type', $this->event->get('page_type')));
		
		// Set the page url or else construct it from environmental vars
		if (!$this->event->get('page_url')) {
			$this->event->set('page_url', owa_lib::get_current_url());
		}
		
		$this->event->set( 'page_url', $this->eq->filter( 'page_url', $this->event->get( 'page_url' ), $this->event->get( 'site_id' ) ) );
		// set document/page id
		$this->event->set( 'document_id', owa_lib::setStringGuid( $this->event->get( 'page_url' ) ) );
		// needed?
		$this->event->set('inbound_page_url', $this->event->get('page_url'));
		
		// Page title
		if ( $this->event->get( 'page_title' ) ) {
			$page_title = owa_lib::utf8Encode( trim( $this->event->get( 'page_title' ) ) );
		} else {
			$page_title = '(not set)';
		}
		
		$this->event->set('page_title', $this->eq->filter( 'page_title', $page_title ) );
				
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
				$this->event->set('prior_page', $this->eq->filter('prior_page', $this->event->get('HTTP_REFERER'), $this->event->get( 'site_id' ) ) );	
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
			$this->event->set('target_url', $this->eq->filter('target_url', $this->event->get('target_url'), $this->event->get( 'site_id' ) ) );
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
		} else {
			// remove ip address from event
			$this->event->set('ip_address', '(not set)');
		}
		
		// calc days since first session
		$this->setDaysSinceFirstSession();
		
		if ( $this->event->get('is_new_session') ) {
			//mark entry page flag on current request
			$this->event->set( 'is_entry_page', true );
			
			// mark event type as first_page_request. Necessary?????
			//$this->event->setEventType('base.first_page_request');
	
			// if this is not the first sessio nthen calc days sisne last session
			if ($this->event->get('last_req')) {
				$this->event->set('days_since_prior_session', round(($this->event->get('timestamp') - $this->event->get('last_req'))/(3600*24)));
			}
			
			if ( ! $this->event->get('medium') ) {
				$this->setMedium();
			}
			
			if ( ! $this->event->get('source') ) {
				$this->setSource();
			}
			
		}
		
		if ( $this->event->get( 'source' ) ) {
				$this->event->set( 'source_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get( 'source' ) ) ) ) );
			}
			
		if ( $this->event->get( 'campaign' ) ) {
			$this->event->set( 'campaign_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get( 'campaign' ) ) ) ) );
		}
		
		if ( $this->event->get( 'ad' ) ) {
			$this->event->set( 'ad_id', owa_lib::setStringGuid( trim( strtolower( $this->event->get( 'ad' ) ) ) ) );
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
			
		if (!$this->event->get('do_not_log')) {
			// pass event to handlers but filter it first
			$this->eq->asyncNotify($this->eq->filter('post_processed_tracking_event', $this->event));
			return owa_coreAPI::debug('Logged '.$this->event->getEventType().' to event queue with properties: '.print_r($this->event->getProperties(), true));
		} else {
			owa_coreAPI::debug("Not logging event due to 'do not log' flag being set.");
		}

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
	
	// if no campaign attribution look for standard medium/source:
	// organic-search, referral, direct
	function setMedium() {
				
		// if there is an external referer
		if ( $this->event->get( 'external_referer' ) ) {
	
			// see if its from a search engine
			if ( $this->event->get( 'search_terms' ) ) {
				$medium = 'organic-search';
			} else {
				// assume its a plain old referral
				$medium = 'referral';
			}
		} else {
			// set as direct
			$medium = 'direct';
		}
		
		$this->event->set( 'medium', $medium );
	}
	
	function setSource() {
		
		$ref = $this->event->get( 'external_referer' );
		
		if ( $ref ) {
			$source = $this->getDomainFromUrl( $ref );
		} else {
			$source = '(none)';
		}
		
		$this->event->set( 'source', $source);
	}
	
	function getDomainFromUrl($url, $strip_www = true) {
		
		$split_url = preg_split('/\/+/', $url);
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
}


?>