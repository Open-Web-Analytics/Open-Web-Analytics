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

/*
    public function setEnvironmentals( $event ) {

        foreach ( $this->environmentals as $k => $v ) {
            // loop and execute call backs.
            if (! $event->get( $k ) ) {
	            
            	$event->set( $k, call_user_func( $this->environmentals[ $k ][ 'default_value' ][0] ) );
            }
        }

    }
*/

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

            // if no value try alternate key

            if ( ! $value && $value !== 0 && $value !== "0" ) {

                if ( isset( $property['alternative_key'] ) &&  $property['alternative_key'] ) {

                    $value = $event->get( $property['alternative_key'] );
                    // should we delete the original key on the event? if so:
                    //$event->delete( $name );
                    owa_coreAPI::debug('alt key value: '.$value);
                }
            }


            // sanitize properties by datatype
            $data_type = '';

            if ( isset( $property['data_type'] ) && $property['data_type'] ) {

                $data_type = $property['data_type'];
            }

            $value = $this->setDataType( $value, $data_type );

            $required = false;

            if ( isset( $property['required'] ) ) {

                $required = $property['required'];
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
            case "string":

                $var = owa_sanitize::cleanInput( $var, array('remove_html' => true) );
                break;
            case "url":

                $var = owa_sanitize::cleanUrl( $var );
                break;
            case "json":

                $var = owa_sanitize::cleanJson( $var );
                break;
            case "boolean":
                $var = boolval( $var );
                break;
            default:

                $var = owa_sanitize::cleanInput( $var, array('remove_html' => true) );
        }

        return $var;
    }

    function addCustomVariableProperties( $properties ) {

        $maxCustomVars = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );

        for ($i = 1; $i <= $maxCustomVars; $i++) {

            $properties[ 'cv'.$i.'_name' ] = array(

                'required'        => true,
                'data_type'        => 'string',
                'callbacks'        => array( 'owa_trackingEventHelpers::lowercaseString'),
                'default_value'    => '(not set)'
            );

            $properties[ 'cv'.$i.'_value' ] = array(

                'required'        => true,
                'data_type'        => 'string',
                'callbacks'        => array( 'owa_trackingEventHelpers::lowercaseString' ),
                'default_value'    => '(not set)'
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

                $event->delete( 'cv'.$i );
            }
        }
    }

    static function remoteHostDefault() {

        return owa_coreAPI::getServerParam('REMOTE_HOST');
    }

    static function userAgentDefault( $ua = '') {
		
		if (! $ua ) {
			
			$ua = owa_coreAPI::getServerParam('HTTP_USER_AGENT');
		}
        return $ua;
    }

    static function httpHostDefault() {

        return owa_coreAPI::getServerParam('HTTP_HOST');
    }

    static function languageDefault() {

        return substr( owa_coreAPI::getServerParam( 'HTTP_ACCEPT_LANGUAGE' ), 0, 5 );
    }

    static function ipAddressDefault() {

        $ip = '';
        $chosen_ip = '';

        // array of SERVER params that could possibly contain the IP address
        // ordered by probability of relevant match
        $possible_ip_params = array(

            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'REMOTE_ADDR'

        );

        // check for IP address, break when found.
        foreach ( $possible_ip_params as $param ) {

            if ( owa_coreAPI::getServerParam( $param ) ) {

                 $ip = owa_coreAPI::getServerParam( $param );
                 owa_coreAPI::debug("ip address $ip found in $param");
                 
                 break;
             }
        }

         // check to see if there are multiple ips possibly passed from a poxy
         if ( strpos( $ip, ',' ) ) {

             owa_coreAPI::debug('multiple ip addresses found');
             // evaluate each IP to make sure it's valid and that it's not a private IP
             $candidate_ips = explode( ',', $ip );

             foreach ( $candidate_ips as $candidate_ip ) {

                 $candidate_ip = trim( $candidate_ip );

                 if ( owa_lib::isNotPrivateIp( $candidate_ip ) ) {

                     $chosen_ip = $candidate_ip;
                     owa_coreAPI::debug("Candidate IP address $candidate_ip was chosen.");

                     break;
                     
                 } else {
	                 
	                 owa_coreAPI::debug("Candidate IP address $candidate_ip was private.");
                 }
             }
             
         } else {
	         
	         if ( owa_lib::isNotPrivateIp( $ip ) ) {
		     	
		     	$chosen_ip = $ip;
		     	owa_coreAPI::debug("IP address $ip was chosen.");
		     	
		     } else {
			     
			     owa_coreAPI::debug("IP address $ip was private.");
		     }
         }

        // Anonymize IP if needed.
        if ( $chosen_ip && owa_coreAPI::getSetting( 'base', 'anonymize_ips' ) ) {
			
			$chosen_ip = owa_lib::anonymizeIp( $chosen_ip );
			owa_coreAPI::debug("IP address was anonymized.");
        }

        return $chosen_ip;
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
    
    static function deriveMedium( $medium, $event ) {
	    
	    // respect what was already set by the tracker
	    if ( $medium ) {
		    
		    return $medium;
	    }

	    if ( $event->get( 'session_referer' ) ) {
	    		    
		    // check for referrer url
		    $ref = $event->get('session_referer');
		    
		    if ( $ref ) {
			    
			    // parse the referrer url
	            $uri = self::parse_url( $ref );
	
	            $host = $uri['host'];
	            
                $medium = 'referral';
                
                // check if referral is a search engine
                $engine = self::isSearchEngine( $host );
               
                if ( $engine ) {
                    
                    $medium = 'organic-search';
                }
                
                if ( ! $engine ) {
	                
	                // check if referral is a social network
	                $network = self::isSocialNetwork( $host );
	                
	                if ( $network ) {
		                
		                $medium = 'social-network';
	                }
                }
	        }
	        
	        return $medium;
	    }
    }
    
    /**
     *  Use this function to parse out the url and query array element from
     *  a url.
     */
    public static function parse_url( $url ) {

        $url = parse_url($url);

        if ( isset( $url['query'] ) ) {
            $var = $url['query'];

            $var  = html_entity_decode($var);
            $var  = explode('&', $var);
            $arr  = array();

              foreach( $var as $val ) {

                if ( strpos($val, '=') ) {
                    $x = explode('=', $val);

                    if ( isset( $x[1] ) ) {
                        $arr[$x[0]] = urldecode($x[1]);
                    }
                } else {
                    $arr[$val] = '';
                }
               }
              unset($val, $x, $var);

              $url['query_params'] = $arr;

        }

          return $url;
    }

    
    static function deriveSource( $source, $event ) {
	    
	    // respect what was already set by the tracker
	    if ( $source ) {
		    
		    return $source;
	    }

	    
	    if ( $event->get( 'session_referer' ) ) {
			
			$ref = $event->get( 'session_referer' );
			$uri = self::parse_url( $ref );
			
			$host = $uri['host'];
			
			if ($host) {
			
				$source = self::stripWwwFromDomain( $host );
				return $source;
			}
		}
    }
    
    static function stripWWWFromDomain( $domain ) {

        $done = false;
        $part = substr( $domain, 0, 5 );
        if ($part === '.www.') {
            //strip .www.
            $domain = substr( $domain, 5);
            // add back the leading period
            $domain = '.'.$domain;
            $done = true;
        }

        if ( ! $done ) {
            $part = substr( $domain, 0, 4 );
            if ($part === 'www.') {
                //strip .www.
                $domain = substr( $domain, 4);
                $done = true;
            }

        }

        return $domain;
    }
    
    static function isSearchEngine( $host ) {
		
        if ( ! $host ) {
	        
            return;
        }

        $searchEngine = [];
        
        $organicSearchEngines = self::getSearchEngineList();

        foreach ( $organicSearchEngines as $engine ) {
            
            $domain = $engine['domain'];

            if ( stripos( $host, $domain ) !== false ) {
                
                owa_coreAPI::debug( 'Found search engine: '. $domain);
                
                return true;
            }
        }
    }
    
    static function extractSearchTerm( $term, $event ) {
	    
	    if ( $term ) {
			    
			return $term;
		}
		    
	    if ( $event->get( 'session_referer' ) ) {
	    
		    // check for referrer url
		    $ref = $event->get( 'session_referer' );
		    
		    $uri = self::parse_url( $ref );
		    owa_coreAPI::debug($uri);
		    // check for query params, search engine might have sent them under https
		    if ( array_key_exists('query_params', $uri) && ! empty( $uri['query_params'] ) ) {
		    
	            $host = $uri['host'];
			    
			    $organicSearchEngines = self::getSearchEngineList();
			    
			    foreach ( $organicSearchEngines as $engine ) {
				    
		            $domain = $engine['domain'];
		
		            if ( stripos( $host, $domain ) !== false ) {
			            
			            $query_param = $engine['query_param'];
			            $term = '';
			
			            if (isset($uri['query_params'][$query_param])) {
				            
			                $term = $uri['query_params'][$query_param];
			                owa_coreAPI::debug( 'Found search term: ' . $term);
			                			                
			            } else {
				            
				            $term = '(not provided)';
			            }
			            // need urldecode here ot clean up the "+" characters in the term
			            return trim( urldecode( strtolower( $term ) ) );
		            }
		        }
		    }
	    }
    }
    
    static function isSocialNetwork( $host ) {
	    
	    $social_networks = self::getSocialNetworkList();

        foreach ( $social_networks as $network ) {
            
            if ( stripos( $host, $network['domain'] ) !== false ) {
                
                owa_coreAPI::debug( 'Found social network: %s', $network['domain'] );
                
                return true;
            }
        }
    }
    
    static function getSearchEngineList() {
	    
	    return owa_coreAPI::loadConf( 'searchengines.php', 'tracking.search_engine_registry' );
    }
    
    static function getSocialNetworkList() {
	    
	    return owa_coreAPI::loadConf( 'socialnetworks.php', 'tracking.social_network_registry' );
    }

    /**
     * Filter function Strips a URL of certain defined session or tracking params
     *
     * @return string
     */
    static function makeUrlCanonical( $url, $event ) {
	if(is_null($url)){
	    return $url;
	}

        $site_id = $event->getSiteId();

        if ( ! $site_id ) {
            owa_coreAPI::debug('no site_id passed to make makeUrlCanonical. Returning URL as is.');
            return $url;
        }

        $url = html_entity_decode( $url );
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
	if(is_null($string)){
            return $string;
        }

        return owa_lib::utf8Encode( trim( $string ) );
    }

    /**
     * Resolve hostname from IP address
     *
     * @access public
     */
    static function resolveFullHost( $full_host, $event ) {

        if (
        		( $event->get('REMOTE_HOST') === '(not set)' || $event->get('REMOTE_HOST') === 'localhost' )
				&& $event->get( 'ip_address' )
				&& owa_coreAPI::getSetting('base', 'resolve_hosts')

        ) {
			
			$remote_host = '';
            // get ip address
            $ip_address = $event->get( 'ip_address' );
            
            if ( owa_lib::isNotPrivateIp( $ip_address ) ) {
	            
	            // valid v4 or v6 IP address
	            
	            if ( owa_lib::isValidIpv6( $ip_address ) ) {
		            
		            // is v6 format
		            $result = @dns_get_record( $ip_address, DNS_AAAA );

	                if ( is_array( $result ) && isset( $result[0] ) && isset( $result[0]['host'] ) ) {
	
	                    $remote_host = $result[0]['host'];
	                }
		            
	            } else {
		            
		            // must be v4.
		            $remote_host = @gethostbyaddr( $ip_address );
	            }
	        }
 
            // if we get a host back that is not an ip address or unknown
            if ( $remote_host && $remote_host != $ip_address && $remote_host != 'unknown' ) {

                return $remote_host;
            }
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
	    
        return $event->get('is_new_session') ? true : false;
    }

    static function resolveCountry ( $country, $event ) {

        // if country is set manually, use it
        if ($country) {
            return $country;
        }

        $location = owa_coreAPI::getGeolocationFromIpAddress($event->get('ip_address'));

        return $location->getCountry();
    }

    static function resolveCity ( $city, $event ) {

        // if city is set manually, use it
        if ($city) {
            return $city;
        }

        $location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getCity();
    }

    static function resolveLatitude ( $latitude, $event ) {

        // if latitude is set manually, use it
        if ($latitude) {
            return $latitude;
        }

        $location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getLatitude();
    }

    static function resolveLongitude ( $longitude, $event ) {

        // if longitude is set manually, use it
        if ($longitude) {
            return $longitude;
        }

        $location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getLongitude();
    }

    static function resolveCountryCode ( $country_code, $event ) {

        // if country_code is set manually, use it
        if ($country_code) {
            return $country_code;
        }

        $location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getCountryCode();
    }

    static function resolveState ( $state, $event ) {

        // if state is set manually, use it
        if ($state) {
            return $state;
        }

        $location = owa_coreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getState();
    }

    static function lowercaseString ( $string, $event ) {
	if(is_null($string)){
            return($string);
        }

        return strtolower( trim( $string ) );
    }

    static function setPriorPage ( $prior_page, $event ) {

        // if prior_page is set manually, use it
        if ($prior_page) {
            return $prior_page;
        }

        if ( $event->get( 'HTTP_REFERER' ) ) {
            // @todo is this parse done somewhere else already? source?
            $referer_parse = owa_lib::parse_url( $event->get('HTTP_REFERER') );

            $http_host = $event->get( 'HTTP_HOST' );

            if ( isset($referer_parse['host'] ) && $referer_parse['host'] === $http_host ) {

                return $event->get('HTTP_REFERER');
            }
        }

        return null;
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

                $user_name = $cu->user->get( 'user_id' );
            }

            return $user_name;
        }
    }

    static function setEmailAddress ( $email_address, $event ) {

        if ( owa_coreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {

            if ( ! $email_address && owa_coreAPI::getSetting( 'base', 'log_owa_user_names' ) ) {

                $cu = owa_coreAPI::getCurrentUser();

                $email_address = $cu->user->get( 'email_address' );
            }

            return $email_address;
        }
    }

}

?>
