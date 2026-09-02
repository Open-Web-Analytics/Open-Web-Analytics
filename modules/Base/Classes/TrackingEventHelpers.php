<?php
namespace OWA\Module\Base\Classes;


class TrackingEventHelpers {

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

            $o = new \OWA\Module\Base\Classes\TrackingEventHelpers();
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

    /**
     * @var array<string, bool> guard against re-registering the same property
     * derivation filter. setTrackerProperties() runs on every logEvent() (3x
     * per event: environmental/regular/derived maps), but those maps are static
     * config, so a given (property, callback) filter must be attached only ONCE
     * per process. Re-attaching chains the derivation into itself: because
     * eventDispatch::filter() feeds each listener's output into the next, a
     * derived dimension id (generateDimensionId) would get setStringGuid()'d
     * once per copy, corrupting the fact row's FK columns on the 2nd+ event of
     * any process that logs more than one (queue/batch workers, CLI, tests).
     */
    private $registeredCallbacks = array();

    /**
     * The properties a tracking request is allowed to set.
     *
     * A request reaches log.php with arbitrary owa_* parameters and they used
     * to be copied onto the event wholesale, so anything whose name matched a
     * column was written to that column -- owa_is_browser=ludhiana put a city
     * name in a boolean, and owa_ip_address would have replaced the observed
     * one.
     *
     * Membership is DECLARED, not inferred from which map a property lives in.
     * `regular` is client-set by definition, but the classification is not a
     * clean proxy in both directions: LocationHandlers deliberately accepts a
     * client-supplied country and city and skips the IP lookup when it sees
     * them, and those are registered as derived. So a derived property opts in
     * with 'client_settable' => true rather than the endpoint guessing.
     *
     * Anything a module has not registered at all is still accepted -- it is
     * sanitised in ProcessEvent and carried as a custom property, which is how
     * custom variables and event parameters work.
     *
     * @return array property name => true
     */
    /** @var array|null the parsed config, read once per process */
    private static $property_config;

    /**
     * The property definitions, read from modules/Base/config/tracking_properties.json.
     *
     * The file is the single enumeration of what a tracking event may carry:
     * every property a handler expects by name, which of the three scopes owns
     * it, and how the pipeline should treat it. Anything not named there is a
     * custom property and is passed through without a contract.
     *
     * ORDER IS PART OF THE CONTRACT. setTrackerProperties() applies properties
     * in the order they appear here, and a callback may read what earlier ones
     * wrote -- the date parts read timestamp, the geo callbacks read
     * ip_address, source and medium read session_referer. Reordering entries
     * does not fail; the dependant silently derives from a value that has not
     * been computed yet. TrackingPropertyOrderTest holds this.
     *
     * @param string $scope one of request, client, server
     * @return array
     */
    private static function propertyConfig( $scope ) {

        if ( self::$property_config === null ) {

            $path = OWA_DIR . 'modules/Base/config/tracking_properties.json';
            $config = json_decode( file_get_contents( $path ), true );

            if ( ! is_array( $config ) ) {

                throw new \RuntimeException(
                    'Could not read the tracking property config at ' . $path . ': '
                    . json_last_error_msg() );
            }

            /* 'note' documents the entry for whoever edits the file; it is not
               part of the definition the pipeline consumes. */
            foreach ( $config as $name => $properties ) {

                foreach ( $properties as $property => $definition ) {

                    unset( $config[ $name ][ $property ]['note'] );
                }
            }

            self::$property_config = $config;
        }

        if ( ! isset( self::$property_config[ $scope ] ) ) {

            throw new \InvalidArgumentException(
                "There is no '$scope' scope in the tracking property config." );
        }

        return self::$property_config[ $scope ];
    }

    /**
     * Properties the server reads off the HTTP request.
     *
     * The request is the source: the user agent, the host, the address it came
     * from, and the moment it arrived. A tracking request cannot set these --
     * it would be choosing what it is observed to be.
     *
     * Moved here from Module::setupTrackingProperties(), because every
     * callback these definitions name is a method of this class -- the
     * declaration and the behaviour were about 440 lines apart in two files.
     *
     * @return array
     */
    public static function requestProperties() {

        return self::propertyConfig( 'request' );

    }

    /**
     * Properties the tracker sends.
     *
     * The client is the source, so a request may set them. ORDER IS PART OF THE
     * CONTRACT here: deriveSource, deriveMedium and extractSearchTerm all read
     * session_referer, so those three must stay after it. Nothing enforces
     * that ordering, which is why TrackingPropertyOrderTest exists.
     *
     * Moved here from Module::setupTrackingProperties(), because every
     * callback these definitions name is a method of this class -- the
     * declaration and the behaviour were about 440 lines apart in two files.
     *
     * @return array
     */
    public static function clientProperties() {

        return self::propertyConfig( 'client' );

    }

    /**
     * Properties the server computes from other properties.
     *
     * Date parts from the timestamp, geolocation from the address, browser from
     * the user agent, and the dimension ids. A request cannot set these either:
     * a supplied value would replace a derivation, which is exactly the defect
     * that let a city name reach a boolean column.
     *
     * Moved here from Module::setupTrackingProperties(), because every
     * callback these definitions name is a method of this class -- the
     * declaration and the behaviour were about 440 lines apart in two files.
     *
     * @return array
     */
    public static function serverProperties() {

        return self::propertyConfig( 'server' );

    }

    public static function clientSettableProperties() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $allowed = (array) $service->getMap( 'tracking_properties_regular' );

        foreach ( array( 'tracking_properties_derived', 'tracking_properties_environmental' ) as $map ) {

            foreach ( (array) $service->getMap( $map ) as $name => $property ) {

                if ( ! empty( $property['client_settable'] ) ) {

                    $allowed[ $name ] = $property;
                }
            }
        }

        return $allowed;
    }

    /**
     * Drop parameters naming a property the server computes for itself.
     *
     * Unregistered names pass through: this refuses to let a request OVERWRITE
     * a derivation, it does not restrict what a site may send.
     */
    /**
     * Properties only the server may set: derived and environmental, less
     * anything that declared itself client-settable.
     *
     * One definition, used both to filter the wire and to decide what
     * ProcessEvent may re-apply over a derivation. Two definitions would drift,
     * and the symptom of drift is a value silently landing in the wrong column.
     *
     * @return array property name => definition
     */
    public static function serverOwnedProperties() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        return array_diff_key(
            (array) $service->getMap( 'tracking_properties_derived' )
                + (array) $service->getMap( 'tracking_properties_environmental' ),
            self::clientSettableProperties() );
    }

    public static function rejectServerOwnedParams( array $params ) {

        $serverOwned = self::serverOwnedProperties();

        $refused = array_intersect_key( $params, $serverOwned );

        if ( $refused ) {

            \OWA\Core\CoreAPI::debug( 'Refused client-supplied values for server-owned properties: '
                . implode( ', ', array_keys( $refused ) ) );
        }

        return array_diff_key( $params, $serverOwned );
    }

    public function registerCallbacks( $items, $priority = 0 ) {

        foreach ($items as $name => $item ) {

            if ( isset( $item['callbacks'] ) && ! empty($item['callbacks'] ) ) {

                $callbacks = is_array( $item['callbacks'] )
                    ? $item['callbacks']
                    : array( $item['callbacks'] );

                foreach ( $callbacks as $callback ) {

                    // Attach each property filter at most once per process.
                    $key = $name . '|' . ( is_array( $callback )
                        ? implode( '::', $callback )
                        : (string) $callback );

                    if ( isset( $this->registeredCallbacks[ $key ] ) ) {
                        continue;
                    }

                    $this->registeredCallbacks[ $key ] = true;

                    \OWA\Core\CoreAPI::registerFilter( $name, $callback, $priority );
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

        $eq = \OWA\Core\CoreAPI::getEventDispatch();

        foreach ( $properties as $name => $property ) {

            $value = $event->get( $name );

            // if no value try alternate key

            if ( ! $value && $value !== 0 && $value !== "0" ) {

                if ( isset( $property['alternative_key'] ) &&  $property['alternative_key'] ) {

                    $value = $event->get( $property['alternative_key'] );
                    // should we delete the original key on the event? if so:
                    //$event->delete( $name );
                    \OWA\Core\CoreAPI::debug('alt key value: '.$value);
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

            /*
             * Re-apply the declared type AFTER filtering.
             *
             * setDataType() above runs on the value as it arrived, so it only
             * ever sanitised input. A callback runs after it and its return
             * value went to the database untouched -- which is how a derivation
             * that falls off the end (returning null) wrote NULL into a column
             * whose declared type is boolean. setRepeatVisitorFlag did exactly
             * that from 2015 until 8d24fc65.
             *
             * Only when a value actually came back null: a callback that
             * deliberately returns 0, '' or false keeps what it returned.
             */
            if ( $data_type && $value === null ) {

                $value = $this->setDataType( $value, $data_type );
            }

            //set default value
            if ( $required && ! $value && $value !== 0 && $value !== "0") {

                /*
                 * array_key_exists, not isset() && truthiness.
                 *
                 * The old test could never apply a FALSY default -- `false` and
                 * `0` are the only defaults a boolean or counter would want, and
                 * both failed the truthy check. So the one case a default exists
                 * for was the one case it was skipped.
                 */
                if ( array_key_exists( 'default_value', $property ) ) {

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

                /*
                 * Guarded, because this is reached from an unauthenticated
                 * tracking beacon. PHP 8 raises
                 * "TypeError: Unsupported operand types: string + int" for a
                 * non-numeric string, so a beacon carrying ?owa_dsps=abc -- or
                 * any garbage in any integer-typed property -- fataled event
                 * processing. During a queue drain that is a poison pill.
                 *
                 * is_numeric() rather than a cast, so valid input keeps exactly
                 * the value it had before: (int) would truncate "1.9" to 1,
                 * where + 0 yields 1.9.
                 */
                $var = is_numeric( $var ) ? $var + 0 : 0;
                break;
            case "string":

                $var = \OWA\Module\Base\Classes\Sanitize::cleanInput( $var, array('remove_html' => true) );
                break;
            case "url":

                $var = \OWA\Module\Base\Classes\Sanitize::cleanUrl( $var );
                break;
            case "json":

                $var = \OWA\Module\Base\Classes\Sanitize::cleanJson( $var );
                break;
            case "boolean":
                $var = boolval( $var );
                break;
            default:

                $var = \OWA\Module\Base\Classes\Sanitize::cleanInput( $var, array('remove_html' => true) );
        }

        return $var;
    }

    /**
     * Top up the custom variable properties for any slot the config does not
     * declare.
     *
     * The pairs live in tracking_properties.json like everything else, but the
     * number of them is the maxCustomVars SETTING rather than a constant --
     * FactTable builds its cv columns from the same setting -- so an install
     * that raises it would otherwise have slots with columns and no property
     * definition. The config covers the shipped slots; this covers the rest,
     * and skips anything already declared so the config stays authoritative.
     */
    function addCustomVariableProperties( $properties ) {

        $maxCustomVars = \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );

        for ( $i = 1; $i <= $maxCustomVars; $i++ ) {

            foreach ( array( 'name', 'value' ) as $half ) {

                $key = 'cv' . $i . '_' . $half;

                if ( array_key_exists( $key, $properties ) ) {

                    continue;
                }

                $properties[ $key ] = array(

                    'required'        => true,
                    'data_type'        => 'string',
                    'callbacks'        => array( 'owa_trackingEventHelpers::lowercaseString' ),
                    'default_value'    => '(not set)'
                );
            }
        }

        return $properties;
    }

    function translateCustomVariables( $event ) {

        $maxCustomVars = \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );

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

        return \OWA\Core\CoreAPI::getServerParam('REMOTE_HOST');
    }

    static function userAgentDefault( $ua = '') {
		
		if (! $ua ) {
			
			$ua = \OWA\Core\CoreAPI::getServerParam('HTTP_USER_AGENT');
		}
        return $ua;
    }

    static function httpHostDefault() {

        return \OWA\Core\CoreAPI::getServerParam('HTTP_HOST');
    }

    static function languageDefault() {

        return substr( \OWA\Core\CoreAPI::getServerParam( 'HTTP_ACCEPT_LANGUAGE' ), 0, 5 );
    }

    /*
     * Registered as a FILTER, so the dispatcher hands it ( $value, $event )
     * even though the old signature named neither. $event is named now
     * because anonymisation is a per-Profile decision; $value stays ignored,
     * as this callback always recomputes the address from the request.
     */
    static function ipAddressDefault( $value = '', $event = null ) {

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

            if ( \OWA\Core\CoreAPI::getServerParam( $param ) ) {

                 $ip = \OWA\Core\CoreAPI::getServerParam( $param );
                 \OWA\Core\CoreAPI::debug("ip address $ip found in $param");
                 
                 break;
             }
        }

         // check to see if there are multiple ips possibly passed from a poxy
         if ( strpos( $ip, ',' ) ) {

             \OWA\Core\CoreAPI::debug('multiple ip addresses found');
             // evaluate each IP to make sure it's valid and that it's not a private IP
             $candidate_ips = explode( ',', $ip );

             foreach ( $candidate_ips as $candidate_ip ) {

                 $candidate_ip = trim( $candidate_ip );

                 if ( \OWA\Core\Lib::isNotPrivateIp( $candidate_ip ) ) {

                     $chosen_ip = $candidate_ip;
                     \OWA\Core\CoreAPI::debug("Candidate IP address $candidate_ip was chosen.");

                     break;
                     
                 } else {
	                 
	                 \OWA\Core\CoreAPI::debug("Candidate IP address $candidate_ip was private.");
                 }
             }
             
         } else {
	         
	         if ( \OWA\Core\Lib::isNotPrivateIp( $ip ) ) {
		     	
		     	$chosen_ip = $ip;
		     	\OWA\Core\CoreAPI::debug("IP address $ip was chosen.");
		     	
		     } else {
			     
			     \OWA\Core\CoreAPI::debug("IP address $ip was private.");
		     }
         }

        // Anonymize IP if needed.
        $site_id = $event ? $event->get('site_id') : '';

        if ( $chosen_ip && \OWA\Core\CoreAPI::getSetting(
                 'base', 'anonymize_ips', 'profile', $site_id ) ) {
			
			$chosen_ip = \OWA\Core\Lib::anonymizeIp( $chosen_ip );
			\OWA\Core\CoreAPI::debug("IP address was anonymized.");
        }

        return $chosen_ip;
    }

    static function timestampDefault() {

        return \OWA\Core\CoreAPI::getRequestTimestamp();
    }

    static function microtimeDefault() {

        return microtime();
    }

    static function generateLocationId( $property_name, $event ) {

        if ( $event->get( 'country' ) ) {
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            return $s->geolocation->generateId( $event->get( 'country' ), $event->get( 'state' ), $event->get( 'city' ) );
        }
    }

    static function generateDimensionId ( $property_value, $event ) {

        if ( $property_value ) {

            return \OWA\Core\Lib::setStringGuid( $property_value );
        }

    }

    /**
     * Days since the prior session, derived from the interval the tracker
     * measured.
     *
     * STRAIGHT 24-HOUR PERIODS, not calendar days, and that is forced rather
     * than chosen: this value must be IDENTICAL on every event sharing a
     * session_id. Calendar days cannot be, because counting date boundaries
     * needs two absolute times and the events carry only one -- their own
     * timestamp -- plus a session-scoped interval. Anchoring
     * `then = timestamp - elapsed` is exact on the event that OPENED the
     * session but lands later for every event after it, so two hits either side
     * of a midnight would disagree about the same session. Depending only on
     * the interval, which is the same on all of them, is what makes the value
     * stable.
     *
     * Identical arithmetic to what the tracker used to do, so nothing about the
     * daysSinceLastVisit dimension changes meaning -- only where it is computed.
     * One value on the wire now feeds both this and timeSinceLastVisit, instead
     * of the same interval being measured twice on the client.
     *
     * $days already holds whatever an older tracker sent as 'dsps' -- see the
     * alternative_key on this property -- so returning it unchanged is the
     * fallback for trackers cached from before the interval existed.
     */
    /**
     * Days since the visitor's first session, from the date they sent.
     *
     * The tracker sends first_session_date as YYYYMMDD, not a timestamp and not
     * an elapsed count. The anchor is stamped by the VISITOR's clock, and
     * coarsening to a day is what limits the damage: a clock wrong by minutes or
     * hours yields the same date, so only an error crossing midnight costs
     * anything, and only ever one day, once. GA exposes firstSessionDate the
     * same way and for the same reason.
     *
     * Counted against the SERVER's calendar, the one every other date part on
     * the row uses. The two calendars can differ by a day at the edges; that is
     * the bounded cost of the anchor being the visitor's.
     *
     * This value is per-EVENT, not per-session: it is "days since first visit as
     * of this event", so it legitimately ticks over at midnight during a long
     * session. That is why the registry declares it page-scoped.
     *
     * $days already holds whatever an older tracker sent as 'dsfs' -- see the
     * alternative_key -- so returning it unchanged is the fallback for trackers
     * cached from before the date existed.
     */
    /**
     * A unix timestamp as YYYYMMDD, or null if it is not usable.
     *
     * The tracker sends the raw anchors -- fsts, psts, sts -- rather than dates,
     * so no granularity is lost on the way and anything else that wants the
     * precise instant still has it. Everything downstream of here works at DAY
     * level, which is what limits the damage: these anchors are stamped by the
     * VISITOR's clock, and a date absorbs anything short of an error that
     * crosses midnight.
     *
     * Converted with the SERVER's timezone, so these day boundaries are the
     * same ones every other date part on the row uses.
     */
    static function dateFromTimestamp( $timestamp ) {

        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date( 'Ymd', $timestamp ) : null;
    }

    /**
     * Whole days between two YYYYMMDD dates, or null if either is unusable.
     *
     * Day-level arithmetic on purpose: converting first and subtracting days is
     * what makes midnight a non-event, where subtracting the timestamps and
     * dividing would make a two-hour gap spanning midnight look like no days at
     * all.
     */
    static function daysBetweenDates( $from, $to ) {

        if ( ! preg_match( '/^\d{8}$/', (string) $from ) || ! preg_match( '/^\d{8}$/', (string) $to ) ) {

            return null;
        }

        $a = strtotime( substr( $from, 0, 4 ) . '-' . substr( $from, 4, 2 ) . '-' . substr( $from, 6, 2 ) );
        $b = strtotime( substr( $to, 0, 4 ) . '-' . substr( $to, 4, 2 ) . '-' . substr( $to, 6, 2 ) );

        if ( $a === false || $b === false ) {

            return null;
        }

        // round, not floor: a day is 23 or 25 hours across a DST boundary.
        return (int) round( ( $b - $a ) / 86400 );
    }

    /**
     * The date the current session began, as the tracker reported it, falling
     * back to the server's date for trackers that predate the field.
     */
    static function sessionDateOf( $event ) {

        $sent = self::dateFromTimestamp( $event->get( 'sts' ) );

        return $sent !== null ? $sent : date( 'Ymd', (int) $event->get( 'timestamp' ) );
    }

    static function deriveDaysSinceFirstSession( $days, $event ) {

        $count = self::daysBetweenDates(
            self::dateFromTimestamp( $event->get( 'fsts' ) ),
            self::sessionDateOf( $event )
        );

        return $count === null ? $days : $count;
    }

    static function deriveDaysSincePriorSession( $days, $event ) {

        $count = self::daysBetweenDates(
            self::dateFromTimestamp( $event->get( 'psts' ) ),
            self::sessionDateOf( $event )
        );

        return $count === null ? $days : $count;
    }

    /**
     * Is this session's visitor a returning one?
     *
     * Returns a BOOLEAN both ways. It used to return true for a repeat visitor
     * and fall off the end for a new one, so the "false" case was NULL -- and
     * `is_repeat_visitor` is a required derived property, so every new
     * visitor's session stored NULL rather than 0.
     *
     * That is not merely untidy. NULL and 0 are two distinct values for a
     * two-state fact, so anything GROUPing on the column -- the isRepeatVisitor
     * dimension, and any pie or grid built on it -- gets three buckets and
     * reports "No" twice.
     */
    static function setRepeatVisitorFlag( $flag, $event ) {

        return ! $event->get( 'is_new_visitor' );
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

        // Never return an empty yyyymmdd.
        //
        // This column is NOT NULL, it is the partition key, and every date-range
        // report filters on it -- so a row written without one is invisible to
        // reporting and lands in the catch-all partition. A permissive sql_mode
        // hid that by turning the empty write into 0; measured on two live
        // installs, a handful of rows per million had ended up that way.
        //
        // date() with a null timestamp yields 1970, which is worse than useless
        // because it looks like real data. Falling back to now is honest: the
        // server assigns event time anyway when the client does not supply one.
        $timestamp = $event->get('timestamp');

        if ( ! $timestamp ) {

            $timestamp = time();
        }

        return date("Ymd", $timestamp );

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

        list( $msec, $sec ) = explode( " ", (string) $event->get( 'microtime' ) );
        return $sec;
    }

    static function deriveMsec( $msec, $event ) {

        list( $msec, $sec ) = explode( " ", (string) $event->get( 'microtime' ) );
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
                
                \OWA\Core\CoreAPI::debug( 'Found search engine: '. $domain);
                
                return true;
            }
        }
    }
    
    
    static function isSocialNetwork( $host ) {
	    
	    $social_networks = self::getSocialNetworkList();

        foreach ( $social_networks as $network ) {
            
            if ( stripos( $host, $network['domain'] ) !== false ) {
                
                \OWA\Core\CoreAPI::debug( 'Found social network: %s', $network['domain'] );
                
                return true;
            }
        }
    }
    
    static function getSearchEngineList() {
	    
	    return \OWA\Core\CoreAPI::loadConf( 'searchengines.php', 'tracking.search_engine_registry' );
    }
    
    static function getSocialNetworkList() {
	    
	    return \OWA\Core\CoreAPI::loadConf( 'socialnetworks.php', 'tracking.social_network_registry' );
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
            \OWA\Core\CoreAPI::debug('no site_id passed to make makeUrlCanonical. Returning URL as is.');
            return $url;
        }

        $url = html_entity_decode( $url );
        // remove port, pass, user, and fragment
        $url = \OWA\Core\Lib::unparseUrl( parse_url( $url ), array( 'port', 'user', 'pass', 'fragment' ) );

        \OWA\Core\CoreAPI::debug('makeUrlCanonical using site_id: '.$site_id);
        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load( $site->generateId( $site_id ) );

        $filter_string = $site->getSiteSetting( 'query_string_filters' );

        if ($filter_string) {
            $filters = str_replace(' ', '', $filter_string);
            $filters = explode(',', $filter_string);
        } else {
            $filters = array();
        }

        // merge global filters
        $global_filters = \OWA\Core\CoreAPI::getSetting('base', 'query_string_filters');
        if ($global_filters) {
            $global_filters = str_replace(' ', '', $global_filters);
            $global_filters = explode(',', $global_filters);
            $filters = array_merge($global_filters, $filters);
        }

        // OWA specific params to filter
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'source');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'medium');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'campaign');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'ad');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'ad_type');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'overlay');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').'state');
        array_push($filters, \OWA\Core\CoreAPI::getSetting('base', 'ns').\OWA\Core\CoreAPI::getSetting('base', 'feed_subscription_param'));

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
                    \OWA\Core\CoreAPI::debug("Checking URL for domain alias: $da");
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

        return \OWA\Core\Lib::utf8Encode( trim( $string ) );
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
				&& \OWA\Core\CoreAPI::getSetting(
						'base', 'resolve_hosts', 'profile', $event->get('site_id') )

        ) {
			
			$remote_host = '';
            // get ip address
            $ip_address = $event->get( 'ip_address' );
            
            if ( \OWA\Core\Lib::isNotPrivateIp( $ip_address ) ) {
	            
	            // valid v4 or v6 IP address
	            
	            if ( \OWA\Core\Lib::isValidIpv6( $ip_address ) ) {
		            
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
                $host = \OWA\Core\CoreAPI::getRegisteredDomain( $fullhost );
                \OWA\Core\CoreAPI::debug("Registered domain is: $host");
            }

            return $host;
        }
    }

    static function resolveBrowserType( $browser_type, $event ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $bcap = $service->getBrowscap();

        return $bcap->getUaFamily();
    }

    static function isBrowser( $is_browser , $event ) {

        if ( $event->get( 'browser_type' ) ) {

            return true;
        }
    }

    static function resolveBrowserVersion( $version, $event ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $bcap = $service->getBrowscap();

        return $bcap->getUaVersion();
    }

    static function isRobot ( $is_robot, $event ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $bcap = $service->getBrowscap();

        return $bcap->isRobot();
    }

    static function resolveOs ( $os, $event ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

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

        $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress($event->get('ip_address'));

        return $location->getCountry();
    }

    static function resolveCity ( $city, $event ) {

        // if city is set manually, use it
        if ($city) {
            return $city;
        }

        $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getCity();
    }

    static function resolveLatitude ( $latitude, $event ) {

        // if latitude is set manually, use it
        if ($latitude) {
            return $latitude;
        }

        $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getLatitude();
    }

    static function resolveLongitude ( $longitude, $event ) {

        // if longitude is set manually, use it
        if ($longitude) {
            return $longitude;
        }

        $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getLongitude();
    }

    static function resolveCountryCode ( $country_code, $event ) {

        // if country_code is set manually, use it
        if ($country_code) {
            return $country_code;
        }

        $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

        return $location->getCountryCode();
    }

    static function resolveState ( $state, $event ) {

        // if state is set manually, use it
        if ($state) {
            return $state;
        }

        $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress( $event->get( 'ip_address' ) );

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
            $referer_parse = \OWA\Core\Lib::parse_url( $event->get('HTTP_REFERER') );

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
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {

            // set user name if one does not already exist on event
            if ( ! $user_name && \OWA\Core\CoreAPI::getSetting( 'base', 'log_owa_user_names' ) ) {

                $cu = \OWA\Core\CoreAPI::getCurrentUser();

                $user_name = $cu->user->get( 'user_id' );
            }

            return $user_name;
        }
    }

    static function setEmailAddress ( $email_address, $event ) {

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'log_visitor_pii' ) ) {

            if ( ! $email_address && \OWA\Core\CoreAPI::getSetting( 'base', 'log_owa_user_names' ) ) {

                $cu = \OWA\Core\CoreAPI::getCurrentUser();

                $email_address = $cu->user->get( 'email_address' );
            }

            return $email_address;
        }
    }

    /**
     * Resolve the traffic source.
     *
     * The tracker reports what the landing URL was tagged with; the server
     * decides what the source IS. Those used to be the same property name, so
     * a value in the column recorded no trace of which half produced it and
     * the callback had to open by respecting its own current value. Now the
     * claim arrives as tagged_source and the answer is written here.
     *
     * Precedence: an explicit tag wins, else classify the referer.
     */
    static function resolveSource( $source, $event ) {

        $tagged = $event->get( 'tagged_source' );

        if ( $tagged ) {

            return strtolower( trim( $tagged ) );
        }

        $referer = $event->get( 'session_referer' );

        if ( ! $referer ) {

            return $source;
        }

        $uri = self::parse_url( $referer );

        if ( empty( $uri['host'] ) ) {

            return $source;
        }

        return strtolower( self::stripWwwFromDomain( $uri['host'] ) );
    }

    /**
     * Resolve the medium. See resolveSource() for the claim/answer split.
     *
     * Precedence: an explicit tag wins, else classify the referer as a search
     * engine, a social network or a plain referral, else leave the declared
     * default of direct.
     */
    static function resolveMedium( $medium, $event ) {

        $tagged = $event->get( 'tagged_medium' );

        if ( $tagged ) {

            return strtolower( trim( $tagged ) );
        }

        $referer = $event->get( 'session_referer' );

        if ( ! $referer ) {

            return $medium;
        }

        $uri = self::parse_url( $referer );

        if ( empty( $uri['host'] ) ) {

            return $medium;
        }

        $host = $uri['host'];

        if ( self::isSearchEngine( $host ) ) {

            return 'organic-search';
        }

        if ( self::isSocialNetwork( $host ) ) {

            return 'social-network';
        }

        return 'referral';
    }

    /**
     * Resolve the campaign name.
     *
     * There is nothing to derive it from -- a campaign exists only because a
     * URL was tagged with one -- so this is the claim, passed through. It is a
     * callback rather than a bare property so that campaign is registered at
     * all: it reached CampaignHandlers on the wire for years without appearing
     * in any property map, which meant no declared type and no way for the
     * wire filter to have an opinion about it.
     */
    static function resolveCampaign( $campaign, $event ) {

        $tagged = $event->get( 'tagged_campaign' );

        return $tagged ? trim( $tagged ) : $campaign;
    }

    /** As resolveCampaign(). Read by AdHandlers. */
    static function resolveAd( $ad, $event ) {

        $tagged = $event->get( 'tagged_ad' );

        return $tagged ? trim( $tagged ) : $ad;
    }

    /** As resolveCampaign(). Read by AdHandlers beside ad. */
    static function resolveAdType( $ad_type, $event ) {

        $tagged = $event->get( 'tagged_ad_type' );

        return $tagged ? trim( $tagged ) : $ad_type;
    }

    /**
     * Resolve the search terms someone arrived on.
     *
     * Note this is acquisition, not site-internal search -- the v2 plan (§1.10)
     * flags that those two facts share this one name and should not.
     *
     * Precedence: an explicit tag wins, else read the query param the
     * referring search engine is known to use.
     */
    static function resolveSearchTerms( $terms, $event ) {

        $tagged = $event->get( 'tagged_terms' );

        if ( $tagged ) {

            return trim( strtolower( $tagged ) );
        }

        $referer = $event->get( 'session_referer' );

        if ( ! $referer ) {

            return $terms;
        }

        $uri = self::parse_url( $referer );

        if ( empty( $uri['query_params'] ) || empty( $uri['host'] ) ) {

            return $terms;
        }

        foreach ( self::getSearchEngineList() as $engine ) {

            if ( stripos( $uri['host'], $engine['domain'] ) === false ) {

                continue;
            }

            $param = $engine['query_param'];

            if ( ! isset( $uri['query_params'][ $param ] ) ) {

                /* A known engine that sent no term: it withheld it (https
                   referrers usually do), which is a different fact from never
                   having searched. */
                return '(not provided)';
            }

            // urldecode to turn the '+' separators back into spaces
            return trim( urldecode( strtolower( $uri['query_params'][ $param ] ) ) );
        }

        return $terms;
    }

}

?>
