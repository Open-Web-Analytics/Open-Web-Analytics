<?php

class owa_trackingEventHelpers {
	
	// incoming tracking event control flow:
	// 0. create event
	// 0. translate request property keys
	// 0. set all properties from request
	// 0. set environmental properties if not present
	// 0. queue or notify event
	
	// in event handler...
	// 0. register all filters callbacks for required, derived, and optional properties
	// 0. deconstruct custom variable properties
	// 0. filter existing event properties
	// 0. filter/set required event properties
	// 0. filter/set derived event properties
	// 0. handler specific logic
	
	
	/*
	
	event comes in
	
	
	*/
		
	// check for no value
	// clean
	// apply default if necessary
	// filter
	public $environmentals = array(
					
		// DEFAULTS	
			
		// is this used in either client?
		'REMOTE_HOST'		=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::remoteHostDefault' ),
			'required'			=> true,
			'data_type'			=> 'string',
			'filter'			=> true
		),
			
		'HTTP_USER_AGENT'	=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::userAgentDefault' ),
			'required'			=> true,
			'data_type'			=> 'string',
			'filter'			=> true
		),
			
		'HTTP_HOST'			=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::httpHostDefault' ),
			'required'			=> true,
			'data_type'			=> 'string',
			'filter'			=> true
		),
			
		'language'			=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::languageDefault' ),
			'required'			=> true,
			'data_type'			=> 'string',
			'filter'			=> true
		),
			
		'ip_address'			=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::ipAddressDefault' ),
			'required'			=> true,
			'data_type'			=> 'string',
			'filter'			=> true
		),
			
		'timestamp'			=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::timestampDefault' ),
			'required'			=> true,
			'data_type'			=> 'integer',
			'filter'			=> false
		),
			
		'microtime'			=> array(
			'default_value'		=> array( 'owa_trackingEventHelpers::microtimeDefault' ),
			'required'			=> true,
			'data_type'			=> 'string',
			'filter'			=> false
		)
		
	);
	
	// must be added to event if not already present and must have a default value
	public $required = array(
	
		'page_type'						=> array(
			'default_value'					=> '(not set)',
			'required'						=> true,
			'data_type'						=> 'string'
		),
			
		'page_url'						=> array(
			'default_value'					=> '(not set)',
			'required'						=> true,
			'data_type'						=> 'string',
			'callbacks'						=> array( 'owa_trackingEventHelpers::makeUrlCanonical' )
		),
		
		'page_title' 					=> array(
			'required'						=> true,
			'callbacks'						=> array( 'owa_trackingEventHelpers::utfEncodeProperty' ),
			'data_type'						=> 'string',
			'default_value'					=> '(not set)'
		),

		'days_since_first_session' 		=> array(
			'required'						=> true,
			'callbacks'						=> array( ),
			'data_type'						=> 'integer',
			'default_value'					=> false,
			'alternative_key'				=> 'dsfs'
		),
		
		'days_since_prior_session' 		=> array(
			'required'						=> true,
			'callbacks'						=> array( ),
			'data_type'						=> 'integer',
			'default_value'					=> false,
			'alternative_key'				=> 'dsps'
		),
		
		'num_prior_sessions' 		=> array(
			'required'						=> true,
			'callbacks'						=> array( ),
			'data_type'						=> 'integer',
			'default_value'					=> false,
			'alternative_key'				=> 'nps'
		),
			
		'is_new_visitor'				=> array (
			'required'						=> true,
			'data_type'						=> 'boolean',
			' default_value'				=> false			
		),
		
		'user_name'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::setUserName' ),
			'default_value'		=> '(not set)'
		),
		
		'email_address'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::setEmailAddress' ),
			'default_value'		=> '(not set)'
		),
		
		'HTTP_REFERER'	=> array(
			'required'		=> false,
			'data_type'		=> 'string',
			'callbacks'		=> array()
		),

		'target_url'	=> array(
			
			'required'		=> false,
			'data_type'		=> 'string',
			'callbacks'			=> array( 'owa_trackingEventHelpers::makeUrlCanonical' )
		),
		
		'source'		=> array(
			'required'		=> true,
			'data_type'		=> 'string',
			'callbacks'			=> array( 'owa_trackingEventHelpers::lowercaseString' ),
			'default_value'		=> '(not set)'
		),
		
		'medium'		=> array(
			'required'		=> true,
			'data_type'		=> 'string',
			'callbacks'			=> array( 'owa_trackingEventHelpers::lowercaseString' ),
			'default_value'		=> '(not set)'
		),
		
		'session_referer'	=> array(
			'required'		=> false,
			'data_type'		=> 'string',
			'callbacks'		=> array()
		),
		// @todo investigate if this should be a required property so that a proper join can occur.
		'search_terms'		=> array(
			'required'		=> false,
			'callbacks'		=> array( 'owa_trackingEventHelpers::setSearchTerms' ),
			'default_value'		=> '(not set)'
		
		)		
	);
				
	// new properties devired from existing event properties	
	public $derived = array(
	
		'year' 				=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveYear')
		),
			
		'month' 			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveMonth')
		),
			
		'day' 				=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveDay')
		),
			
		'yyyymmdd' 			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveYyyymmdd')
		),
			
		'dayofweek' 		=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveDayOfWeek')
		),
		
		'dayofyear' 		=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveDayOfYear')
		),
			
		'weekofyear' 		=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveWeekOfYear')
		),
			
		'hour' 				=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveHour')
		),
			
		'minute' 			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveMinute')
		),
			
		'second' 			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveSecond')
		),
			
		'sec' 				=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveSec')
		),
			
		'msec' 				=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::deriveMsec')
		),

		'page_uri' 			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::derivePageUri', 'owa_trackingEventHelpers::makeUrlCanonical')
		),
	
		'is_repeat_visitor' => array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::setRepeatVisitorFlag')
		),
			
		'full_host'			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::resolveFullHost'),
			'default_value'		=> '(not set)'
		),
		
		'host'				=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::getHostDomain'),
			'default_value'		=> '(not set)'
		),
		
		'browser_type'		=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::resolveBrowserType')
		),
		
		'is_browser'		=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::isBrowser'),
			'default_value'		=> false
		),
		
		'browser'			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::resolveBrowserVersion'),
			'default_value'		=> '(unknown)'
		),
		
		'is_robot'			=> array(
			'required'			=> true,
			'callbacks'			=> array('owa_trackingEventHelpers::isRobot'),
			'default_value'		=> false
		),
		
		'os'				=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveOs' ),
			'default_value'		=> '(unknown)'
		),
		
		'is_entry_page'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveEntryPage' ),
			'default_value'		=> false
		),
		
		'country'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveCountry' ),
			'default_value'		=> false
		),
		
		'city'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveCity' ),
			'default_value'		=> false
		),
		
		'state'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveState' ),
			'default_value'		=> false
		),
		
		'latitude'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveLatitude' ),
			'default_value'		=> false
		),

		'longitude'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveLongitude' ),
			'default_value'		=> false
		),
		
		'country_code'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::resolveCountryCode' ),
			'default_value'		=> false
		),
		
		'prior_page'		=> array(
			'required'			=> true,
			'callbacks'			=> array( 'owa_trackingEventHelpers::setPriorPage', 'owa_trackingEventHelpers::makeUrlCanonical' ),
			'default_value'		=> false
		),	
		//related object IDs
		/* @todo these should really be moved to handlers and logic encoded in entity objects.*/
	
		'document_id' 		=> array(
		
			'alternative_key'				=> 'page_url',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),
		
		'ua_id' 			=> array(
		
			'alternative_key'				=> 'HTTP_USER_AGENT',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),
		
		'location_id' 		=> array(
		
			'alternative_key'				=> 'country',
			'callbacks'			=> 'owa_trackingEventHelpers::generateLocationId'
		),
		
		'host_id' 			=> array(
		
			'alternative_key'				=> 'host',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),
		
		'os_id' 			=> array(
		
			'alternative_key'				=> 'os',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),
		
		'campaign_id' 		=> array(
		
			'alternative_key'				=> 'campaign',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),
		
		'ad_id' 			=> array(
		
			'alternative_key'				=> 'ad',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),
		
		'source_id' 		=> array(
		
			'alternative_key'				=> 'source',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),

		'referer_id' 		=> array(
		
			'alternative_key'				=> 'session_referer',
			'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
		),		
		
		'referring_search_term_id' => array(
		
			'alternative_key'						=> 'search_terms',
			'callbacks'					=> 'owa_trackingEventHelpers::generateDimensionId'
		)
	);
	
	static function getInstance() {
		
		static $o;
		
		if ( ! $o ) {
			
			$o = new owa_trackingEventHelpers();
		}
		
		return $o;
	}
	
	public function __construct() {
		
	
		
		
	}
	
	public function translateKeys( $event ) {
		
		foreach ( $this->translations as $k => $v ) {
			
			$event->set( $v, $event->get( $k ));
			$event->delete( $k );
		}
	}
	
	public function setEnvironmentals( $event ) {
		
		foreach ( $this->environmentals as $k => $v ) {
			// loop and execute call backs.
			$event->set( $k, call_user_func( $this->environmentals[ $k ][ 'default_value' ][0] ) );
		}
		
	}
	
	public function registerCallbacks( $items, $priority = 0 ) {
		
		foreach ($items as $name => $item ) {
		
			if ( isset( $item['callbacks'] ) && ! empty($item['callbacks'] ) ) {
				
				if ( is_array(  $item['callbacks'] ) ) {
					
				
					foreach ($item['callbacks'] as $callback ) {
						
						owa_coreAPI::registerFilter( $name, $callback,'', $priority);		
					}
				} else {
					
					owa_coreAPI::registerFilter( $name, $item['callbacks'],'', $priority);	
				}
			}
		}
	}
	
/*
	public function generateIds( $event, $properties ) {
		
		$this->registerCallbacks( $properties, 0 );
		
	}
*/
	
	public function setTrackerProperties( $event, $properties ) {
		
		$this->registerCallbacks( $properties, 0 );
		
		$eq = owa_coreAPI::getEventDispatch();
		
		foreach ( $properties as $name => $property ) {
			
			$value = $event->get( $name );
			/*
			if ( isset( $property['data_type'] ) && $property['data_type'] ) {
				$data_type = $property['data_type'];
			}
			
			
			$value = $this->setDataType( $value, $data_type );
			*/
			$required = false;
			
			if ( isset( $property['required'] ) ) {
				
				$required = $property['required'];
			}
			
			if ( ! $value && $value !== 0 && $value !== "0" ) {
				
				if ( isset( $property['alternative_key'] ) &&  $property['alternative_key'] ) {
						
					$value = $event->get( $property['alternative_key'] );
					// should we delete the original key on the event? if so:
					//$event->delete( $name );
				}
			}
			
			// filter value
			$value = $eq->filter( $name, $value, $event );
			
			//set default value
			if ( $required && ! $value && $value !== 0 && $value !== "0") {
			
				if ( isset( $property['default_value'] ) && $property['default_value'] ) { 	
			
					$value = $property['default_value'];
				}
			}
			
			// set value on the event
				
			if ( $required || $value || $value === 0 || $value === "0" ) {
				
				$event->set( $name,  $value );
			}
		}
	}
	
	static function setDataType( $var, $type = 'string' ) {
		
		switch( $type ) {
			
			case "integer":
			
				$var = $var + 0;
				break;
		}
		
		return $var;
	}
		
	function addCustomVariableProperties( $properties ) {
		
		$maxCustomVars = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );
		
		for ($i = 1; $i <= $maxCustomVars; $i++) {
		
			$properties[ 'cv'.$i.'_name' ] = array(
						
				'required'		=> true,
				'data_type'		=> 'string',
				'callbacks'		=> array( 'owa_trackingEventHelpers::lowercaseString'),
				'default_value'	=> '(not set)'
			);
					
			$properties[ 'cv'.$i.'_value' ] = array(
						
				'required'		=> true,
				'data_type'		=> 'string',
				'callbacks'		=> array( 'owa_trackingEventHelpers::lowercaseString' ),
				'default_value'	=> '(not set)'
			);

		}
		
		return $properties;
	}
	
	function translateCustomVariables( $event ) {
		
		$maxCustomVars = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );
		
		for ($i = 1; $i <= $maxCustomVars; $i++) {
		
			$cvar = $event->get( 'cv'.$i );
			
			if ( $cvar ) {
				//split the string
				$pieces = explode( '=' , trim( $cvar ) );
				if ( isset( $pieces[1] ) ) {
					$event->set( 'cv'.$i.'_name', $pieces[0] );
					$event->set( 'cv'.$i.'_value', $pieces[1] );
				}
			}
		}
	}
	
		static function remoteHostDefault() {
	
		return owa_coreAPI::getServerParam('REMOTE_HOST');	
	}
	
	static function userAgentDefault() {
		
		return owa_coreAPI::getServerParam('HTTP_USER_AGENT');	
	}
	
	static function httpHostDefault() {
		
		return owa_coreAPI::getServerParam('HTTP_HOST');	
	}
	
	static function languageDefault() {
		
		return substr( owa_coreAPI::getServerParam( 'HTTP_ACCEPT_LANGUAGE' ), 0, 5 );	
	}
	
	static function ipAddressDefault() {
		
		return owa_coreAPI::getServerParam('REMOTE_ADDR');	
	}
	
	static function timestampDefault() {
		
		return owa_coreAPI::getRequestTimestamp();	
	}
	
	static function microtimeDefault() {
		
		return microtime();	
	}
	
	static function generateLocationId( $property_name, $event ) {
		
		if ( $event->get( 'country' ) ) {
			$s = owa_coreAPI::serviceSingleton();	
			return $s->geolocation->generateId( $event->get( 'country' ), $event->get( 'state' ), $event->get( 'city' ) );	
		}
	}
	
	static function generateDimensionId ( $property_value, $event ) {
		
		if ( $property_value ) {
			
			return owa_lib::setStringGuid( $property_value );
		}
		 
	}
		
	static function setRepeatVisitorFlag( $flag, $event ) {
		
		// set repeat visitor type flag visitor is not new.		
		if ( ! $event->get( 'is_new_visitor' ) ) {
		
			return true;
		}
	}
	
	static function deriveYear( $year, $event ) {
		
		return date( "Y", $event->get('timestamp') );
		
	}
	
	static function deriveMonth( $month, $event ) {
		
		return date("Ym", $event->get('timestamp') );
		
	}
	
	static function deriveDay( $day, $event ) {
		
		return date("d", $event->get('timestamp') );
		
	}
	
	static function deriveYyyymmdd( $yyyymmdd, $event ) {
		
		return date("Ymd", $event->get('timestamp') );
		
	}
	
	static function deriveDayOfWeek( $dayofweek, $event ) {
		
		return date("D", $event->get('timestamp') );
		
	}
	
	static function deriveDayOfYear( $dayofyear, $event ) {
		
		return date("z", $event->get('timestamp') );
		
	}
	
	static function deriveWeekOfYear( $weekofyear, $event ) {
		
		return date("W", $event->get('timestamp') );
		
	}
	
	static function deriveHour( $hour, $event ) {
		
		return date("G", $event->get('timestamp') );
		
	}
	
	static function deriveMinute( $minute, $event ) {
		
		return date("i", $event->get('timestamp') );
		
	}
	
	static function deriveSecond( $second, $event ) {
		
		return date("s", $event->get('timestamp') );
		
	}
	
	static function deriveSec( $sec, $event ) {
		
		list( $msec, $sec ) = explode( " ", $event->get( 'microtime' ) );
		return $sec;
	}
	
	static function deriveMsec( $msec, $event ) {
		
		list( $msec, $sec ) = explode( " ", $event->get( 'microtime' ) );
		return $msec;
	}
	
	static function derivePageUri( $page_uri, $event ) {
				
		$page_parse = parse_url( $event->get( 'page_url' ) );
		
		if ( ! array_key_exists( 'path', $page_parse ) || empty( $page_parse['path'] ) ) {
			
			$page_parse['path'] = '/';
		}
	
		if ( array_key_exists( 'query', $page_parse ) || ! empty( $page_parse['query'] ) ) {
			
			return sprintf( '%s?%s', $page_parse['path'], $page_parse['query'] );	
			
		} else {
			
			return $page_parse['path'] ;
		}
	}

	/**
	 * Filter function Strips a URL of certain defined session or tracking params
	 *
	 * @return string
	 */
	static function makeUrlCanonical( $url, $event ) {
		
		$site_id = $event->getSiteId();
		
		if ( ! $site_id ) {
			owa_coreAPI::debug('no site_id passed to make makeUrlCanonical. Returning URL as is.');
			return $url;
		} 			
			
		// remove port, pass, user, and fragment
		$url = owa_lib::unparseUrl( parse_url( $url ), array( 'port', 'user', 'pass', 'fragment' ) );
		
		owa_coreAPI::debug('makeUrlCanonical using site_id: '.$site_id);
		$site = owa_coreAPI::entityFactory('base.site');
		$site->load( $site->generateId( $site_id ) );
		
		$filter_string = $site->getSiteSetting( 'query_string_filters' );
		
		if ($filter_string) {
			$filters = str_replace(' ', '', $filter_string);
			$filters = explode(',', $filter_string);
		} else {
			$filters = array();
		}
		
		// merge global filters
		$global_filters = owa_coreAPI::getSetting('base', 'query_string_filters');
		if ($global_filters) {
			$global_filters = str_replace(' ', '', $global_filters);
			$global_filters = explode(',', $global_filters);
			$filters = array_merge($global_filters, $filters);
		}
			
		// OWA specific params to filter
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'source');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'medium');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'campaign');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'ad');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'ad_type');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'overlay');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'state');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').owa_coreAPI::getSetting('base', 'feed_subscription_param'));
		
		//print_r($filters);
		
		foreach ($filters as $filter => $value) {
			
		  $url = preg_replace(
			'#\?' .
			$value .
			'=.*$|&' .
			$value .
			'=.*$|' .
			$value .
			'=.*&#msiU',
			'',
			$url
		  );
		  
		}
	        
	        
	    //check for dangling '?'. this might occure if all params are stripped.
	        
	    // returns last character of string
		$test = substr($url, -1);   		
		
		// if dangling '?' is found clean up the url by removing it.
		if ($test == '?') {
			$url = substr($url, 0, -1);
		}
		
		//check and remove default page
		$default_page = $site->getSiteSetting( 'default_page' );
		
		if ($default_page) {
		
			$default_length = strlen($default_page);
			
			if ($default_length) {
				
				//test for string
				$default_test = substr($url, 0 - $default_length, $default_length);
				if ($default_test === $default_page) {
					$url = substr($url, 0, 0 - $default_length);
				}
			}
		}
				
		// check and remove trailing slash
		if (substr($url, -1) === '/') {
			
			$url = substr($url, 0, -1);
		}
		
		// check for domain aliases
		$das = $site->getSiteSetting( 'domain_aliases' );
		
		if ( $das ) {
			
			$site_domain = $site->getDomainName();
			
			if ( ! strpos( $url, '://'. $site_domain ) ) {
			
				$das = explode(',', $das);
				
				foreach ($das as $da) {
					owa_coreAPI::debug("Checking URL for domain alias: $da");
					$da = trim($da);
					if ( strpos( $url, $da ) ) {
						$url = str_replace($da, $site_domain, $url);
						break;
					}
				}
			}
		}
		
     	return $url;
		
	}
	
	static function utfEncodeProperty( $string, $event ) {
		
		return owa_lib::utf8Encode( trim( $string ) );
	}
	
	/**
	 * Resolve hostname from IP address
	 * 
	 * @access public
	 */
	static function resolveFullHost( $full_host, $event ) {
		
		// See if host is already resolved
		if ( ! $event->get('REMOTE_HOST') 
			 && $event->get( 'ip_address' ) 
			 && owa_coreAPI::getSetting('base', 'resolve_hosts') 
		) {
			
			// Do the host lookup
			$ip_address = $event->get( 'ip_address' );
			
			// Do the host lookup
			
			if ( ! strpos( $ip_address, '.' ) ) {
				
				 $result = @dns_get_record($host_name,DNS_AAAA);
				 
				 if ( is_array( $result ) && isset( $result[0] ) && isset( $result[0]['host'] ) ) {
					 
					 $remote_host = $result[0]['host'];
				 }
				 
			} else {
			
				$remote_host = @gethostbyaddr( $ip_address );
			}
			
			if ( $remote_host 
				 && $remote_host != $ip_address 
				 && $remote_host != 'unknown' 
			) {
				
				return $remote_host;	
			}
		} else {
			
			return $event->get('REMOTE_HOST'); 
		}
	}
	
	static function getHostDomain( $host, $event ) {
		
		$fullhost = $event->get( 'full_host' );
					
		if ( $fullhost ) {
			
			// Sometimes gethostbyaddr returns 'unknown' or the IP address if it can't resolve the host
			if ($fullhost === 'localhost') {
			
				$host = 'localhost';
				
			} else {
			
				// lookup the registered domain using the Public Suffix List.
				$host = owa_coreAPI::getRegisteredDomain( $fullhost );
				owa_coreAPI::debug("Registered domain is: $host");
			}	
			
			return $host;	
		}
	}
	
	static function resolveBrowserType( $browser_type, $event ) {
		
		$service = owa_coreAPI::serviceSingleton();
		
		$bcap = $service->getBrowscap();
		
		return $bcap->getUaFamily();
	}
	
	static function isBrowser( $is_browser , $event ) {
		
		if ( $event->get( 'browser_type' ) ) {
			
			return true;
		}
	}
	
	static function resolveBrowserVersion( $version, $event ) {
		
		$service = owa_coreAPI::serviceSingleton();
		
		$bcap = $service->getBrowscap();
		
		return $bcap->getUaVersion();
	}
	
	static function isRobot ( $is_robot, $event ) {
		
		$service = owa_coreAPI::serviceSingleton();
		
		$bcap = $service->getBrowscap();
		
		return $bcap->isRobot();
	}
	
	static function resolveOs ( $os, $event ) {
		
		$service = owa_coreAPI::serviceSingleton();
		
		$bcap = $service->getBrowscap();
		
		return $bcap->getOsFamily();
	
	}
	
	static function resolveEntryPage( $is_entry_page, $event ) {
		
		if ( $event->get( 'is_new_session' ) ) {
			
			return true;	
		}
	}
	
	static function resolveCountry ( $country, $event ) {
		
		if ( ! $country ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
			
			return $location->getCountry();
		}
	}
	
	static function resolveCity ( $city, $event ) {
		
		if ( ! $city ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
			
			return $location->getCity();
		}
	}
	
	static function resolveLatitude ( $latitude, $event ) {
		
		if ( ! $latitude ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
			
			return $location->getLatitude();
		}
	}
	
	static function resolveLongitude ( $longitude, $event ) {
		
		if ( ! $longitude ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
			
			return $location->getLongitude();
		}
	}
	
	static function resolveCountryCode ( $country_code, $event ) {
		
		if ( ! $country_code ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
			
			return $location->getCountryCode();
		}
	}
	
	static function resolveState ( $state, $event ) {
		
		if ( ! $state ) {
			
			$location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );
			
			return $location->getState();
		}
	}
	
	static function lowercaseString ( $string, $event ) {
		
		return strtolower( trim( $string ) );
	}
	
	static function setPriorPage ( $prior_page, $event ) {
		
		if ( ! $prior_page ) {
		
			if ( $event->get( 'HTTP_REFERER' ) ) {
				// @todo is this parse done somewhere else already? source?	
				$referer_parse = owa_lib::parse_url( $event->get('HTTP_REFERER') );
				
				$http_host = $event->get( 'HTTP_HOST' );
	
				if ( isset($referer_parse['host'] ) && $referer_parse['host'] === $http_host ) {
					
					return $event->get('HTTP_REFERER');	
				}
			}
		}
	}
	
	static function setSearchTerms ( $search_terms, $event ) {
		
		if ( $search_terms && $search_terms != '(not set)' ) {
		
			return trim( strtolower( $search_terms ) );
		}
	}
	
	static function setUserName( $user_name, $event ) {
		
		// record and filter personally identifiable info (PII)		
		if ( owa_coreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {
			
			// set user name if one does not already exist on event
			if ( ! $user_name && owa_coreAPI::getSetting( 'base', 'log_owa_user_names' ) ) {
			
				$cu = owa_coreAPI::getCurrentUser();
				
				return $cu->user->get( 'user_id' );
			}
		}
	}
	
	static function setEmailAddress ( $email_address, $event ) {
		
		if ( owa_coreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {
		
			$cu = owa_coreAPI::getCurrentUser();
			
			return $cu->user->get( 'email_address' );
		}
		
	}
		
}

?>