<?php
namespace OWA\Core;

/**
 * OWA Request Params
 */

// Request values are set as undeclared properties; this attribute is PHP 8.2's
// supported way to allow it.
#[\AllowDynamicProperties]
class RequestContainer {

    /** @var list<string>|null the CLI's argv; null on a web request */
    public $cli_args;

    /**
     * The request's params by their un-namespaced names.
     *
     * Keyed by name; PHP turns a numeric name into an int key.
     *
     * @var array<mixed> a string, or an array PHP decoded; setParam() stores anything
     */
    public $owa_params = [];

    /** @var array<list<string>> name => each value a cookie of that name carried */
    public $cookies = [];

    /** @var array<list<string>> the cookies in OWA's namespace, the prefix removed */
    public $owa_cookies = [];

    /** @var array<mixed> the PHP session's values */
    public $session = [];

    /** @var array<mixed> the request's params, cleaned, as named on the request */
    public $request = [];

    /** @var array<mixed>|null the request's server variables */
    public $server;

    /** @var string */
    public $guid;

    /** @var \OWA\Module\Base\Classes\State */
    public $state;

    /** @var string REQUEST_METHOD, 'cli', or '' */
    public $request_type = '';

    /** @var int the edge receipt in seconds */
    public $timestamp;

    /**
     * The same instant as $timestamp, in microseconds.
     *
     * Stamped from one microtime() reading alongside it so the two cannot
     * disagree -- calling time() and microtime() separately can straddle a
     * second boundary, which would put an event in one second and its
     * microsecond stamp in the next.
     *
     * @var int
     */
    public $timestamp_usec;

    /** @var string|null null on a CLI request */
    public $current_url;

    /**
     * Constructor
     *
     */
    public function __construct() {

        /*
         * EDGE RECEIPT. One reading, taken once, for the life of this request.
         *
         * v2 derives an event id from (site, visitor, session, ts, name) and
         * has no counter to fall back on, so two events of one session collide
         * exactly when they share a ts. At second resolution that is reachable
         * -- a page view and the click that follows it inside the same second.
         * At microsecond resolution it is not. So the RESOLUTION here is not a
         * nicety about ordering; it is what makes the id unique.
         *
         * Taken here rather than at write time on purpose: an event queued to
         * disk and drained minutes later must keep the instant it ARRIVED, or
         * the drain would restamp it and a redelivery would derive a different
         * id and fail to collapse.
         */
        $now = microtime( true );

        $this->timestamp      = (int) $now;
        $this->timestamp_usec = (int) round( $now * 1000000 );

        $this->guid = \OWA\Core\Lib::randomId();

        // php's server variables
        $this->server = $_SERVER;
      
        // setup cookies
        $this->cookies = [];

        // look for access to the raw HTTP cookie string. This is needed becuause OWA can set settings cookies
        // with the same name under different subdomains. Multiple cookies with the same name are not
        // available under $_COOKIE. Therefor OWA's cookie conainter must be an array of arrays.
        if ( isset( $_SERVER['HTTP_COOKIE'] ) && strpos( $_SERVER['HTTP_COOKIE'], ';') ) {

            $raw_cookie_array = explode(';', $_SERVER['HTTP_COOKIE']);

            foreach($raw_cookie_array as $raw_cookie ) {

                $nvp = explode( '=', trim( $raw_cookie ) );
                $this->cookies[ $nvp[0] ][] = urldecode($nvp[1]);
            }

        } else {
            // just use the normal cookie global
            if ( $_COOKIE ) {

                foreach ($_COOKIE as $n => $v) {
                    // hack against other frameworks sanitizing cookie data and blowing away our '>' delimiter
                    // this should be removed once all cookies are using json format.
                    if (strpos($v, '&gt;')) {
                        $v = str_replace("&gt;", ">", $v);
                    }

                    // $this->cookies, not a local: a request whose Cookie header holds a
                    // SINGLE cookie reaches only this branch, since the raw-header path above
                    // requires a ';'.
                    $this->cookies[ $n ][] = $v;
                }
            }
        }

        // populate owa_cookie container with just the cookies that have the owa namespace.
        $this->owa_cookies = \OWA\Core\Lib::stripParams( $this->cookies, \OWA\Core\CoreAPI::getSetting('base', 'ns') );


        // session
        if (!empty($_SESSION)) {
            $this->session = $_SESSION;
        }

        /* STATE CONTAINER */

        // state
        $this->state = new \OWA\Module\Base\Classes\State();
        // merges session
        if (!empty($this->session)) {
            $this->state->addStores(\OWA\Core\Lib::stripParams($this->session, \OWA\Core\CoreAPI::getSetting('base', 'ns')));
        }

        // merges cookies
        foreach ( $this->owa_cookies as $k => $owa_cookie ) {

            $this->state->setInitialState( $k, $owa_cookie );
        }

        // create request params and type
        $params = [];
        if ( array_key_exists('REQUEST_METHOD', $_SERVER) ) {
                
                $this->request_type = $_SERVER['REQUEST_METHOD'];
                
            if ( $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE' ) {
            
                parse_str( trim(file_get_contents("php://input") ), $post_vars );
                $params = array_merge( $_GET, $post_vars);
                
            } else if ( $_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD' ) {

                // HEAD is a GET without the body.
                $params = $_GET;
                
            } else if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
                
                // merge in POST vars. GET and POST can occure on the same request.
                $params = array_merge( $_GET, $_POST);
            }
            
            $this->current_url = \OWA\Core\Lib::get_current_url();
            
            // Names only. The values include whatever a form posted, a
            // password among them, and this is written to a file.
            \OWA\Core\CoreAPI::debug( 'Request params: ' . implode( ', ', array_keys( (array) $params ) ) );
            
        } else {
            
            // CLI Request
            if (array_key_exists( 'argv', $_SERVER ) ) {
                
                $this->cli_args = $_SERVER['argv'];

                // parse arguments into key value pairs
                for ( $i=1; $i < count( $this->cli_args ); $i++ ) {
                    
                    $it = explode( "=", $this->cli_args[$i] );
    
                    if ( isset( $it[1] ) ) {
                        
                        $params[ $it[0] ] = $it[1];
                    
                    } else {
                           
                        $params[ $it[0] ] = '';
                    }
                }
    
                $this->request_type = 'cli';
            }
        }
        
        // Clean Input arrays
        if ( $params ) {

            if ( ! \OWA\Core\CoreAPI::getSetting('base', 'tracking_mode') ) {

                $params = \OWA\Module\Base\Classes\Sanitize::cleanInput( $params, [ 'remove_html' => true, 'escape_html' => false ] );

            }
            if ( is_array( $params ) && ! empty( $params ) ) {

                $this->request = $params;
            }
        }

        // get namespace
        $ns = \OWA\Core\CoreAPI::getSetting('base', 'ns');

        // strip action and do params of nasty include exploits.
        //
        // Both spellings are filtered. These two params choose which controller
        // runs, so the un-namespaced forms have to be sanitized the moment they
        // become readable -- filtering only the prefixed spelling would leave
        // the bare one an unfiltered path into the same dispatch.
        foreach ( [ $ns . 'action', $ns . 'do', 'action', 'do' ] as $exploitable ) {

            if ( array_key_exists( $exploitable, $this->request ) ) {

                $this->request[ $exploitable ] =
                    \OWA\Core\Lib::fileInclusionFilter( $this->request[ $exploitable ] );
            }
        }

        /*
         * Resolve every param to its un-namespaced name.
         *
         * OWA emits its own admin and reporting URLs without the prefix, but every
         * bookmark, saved report link and third-party API caller in existence
         * still spells them 'owa_do=...'.
         * Both are read. Where the two spellings of the SAME name collide, the
         * namespaced value wins: it is the older contract, so a stray bare param
         * can never displace one a caller deliberately namespaced.
         *
         * ORDER IS LOAD-BEARING, so this walks $this->request once rather than
         * merging two arrays. 'action' is rekeyed to 'do' immediately below
         * (see the reserved_words setting), which means a request carrying both
         * -- every admin form POST does: 'do' in the query string, 'action' in
         * the form body -- resolves to whichever sits LATER in the array. Any
         * regrouping silently changes which controller runs. Building both
         * spellings into one pass keeps each param in the position it arrived
         * in.
         *
         * Bare names are always read: OWA owns the whole query string of every
         * entry point, as embedding OWA in another application's request is not
         * supported. Cookies and the session are still read prefixed-only
         * (stripParams()).
         */
        $ns_len = strlen( (string) $ns );

        $from_ns      = [];

        $this->owa_params = [];

        foreach ( $this->request as $n => $v ) {

            $n = (string) $n;

            if ( $ns && strpos( $n, $ns ) === 0 ) {

                $name = substr( $n, $ns_len );

                // a param named exactly the namespace has no name left
                if ( $name === '' ) {
                    continue;
                }

                // overwriting in place keeps the position of whichever
                // spelling arrived first
                $this->owa_params[ $name ] = $v;
                $from_ns[ $name ] = true;

                continue;
            }

            // the namespaced spelling of this name already supplied the value
            if ( isset( $from_ns[ $n ] ) ) {
                continue;
            }

            $this->owa_params[ $n ] = $v;
        }

        // translate certain request variables that are reserved in javascript
        $this->owa_params = \OWA\Core\Lib::rekeyArray($this->owa_params, array_flip(\OWA\Core\CoreAPI::getSetting('base', 'reserved_words')));
    }

    /**
     * @param  string $name
     * @return mixed  as owa_params holds it; false when absent
     */
    public function getParam($name) {

        if (array_key_exists($name, $this->owa_params)) {
            return $this->owa_params[$name];
        } else {
            return false;
        }

    }

    /**
     * @param  string $name
     * @param  mixed  $value any type a caller stores
     * @return true
     */
    public function setParam($name, $value) {

        $this->owa_params[$name] = $value;
        return true;
    }

    /**
     * @param  string $name
     * @return mixed  as the request carried it; false when absent
     */
    public function getRequestParam($name) {

        if (array_key_exists($name, $this->request)) {
            return $this->request[$name];
        } else {
            return false;
        }
    }

    /**
     * @return array<mixed>
     */
    public function getAllOwaParams() {

        return $this->owa_params;
    }

    /**
     * @param  array<mixed> $params name => value; each replaces the one held
     * @return void
     */
    public function mergeParams($params) {

        $this->owa_params = array_merge($this->owa_params, $params);
        return;
    }

    /**
     * @param  string $name
     * @return mixed  as $_SERVER holds it; false when absent
     */
    public function getServerParam($name) {

        // Null on a request built without $_SERVER, as a CLI one can be.
        if (is_array($this->server) && array_key_exists($name, $this->server)) {
            return $this->server[$name];
        } else {
            return false;
        }
    }

    /**
     * URL-decode every param a second time, after PHP's decode. For an older
     * beacon only: Beacon\Compat::decodeOlderBeacon() decides.
     *
     * @return void
     */
    public function decodeRequestParams() {

        $params = [];
        // Apply caller specific params
        foreach ($this->owa_params as $k => $v) {
            // An array's values are kept as PHP decoded them.
            if (is_array($v)) {
                $params[$k] = $v;
            } else {
                $params[$k] = is_null($v)?$v:rawurldecode($v);
            }
        }

        // replace owa params
        $this->owa_params = $params;
        //debug
        // Names only, as for the request's own params.
        \OWA\Core\CoreAPI::debug( 'Decoded OWA params: ' . implode( ', ', array_keys( (array) $this->owa_params ) ) );
        return;

    }

    /**
     * @return int
     */
    public function getTimestamp() {

        return $this->timestamp;
    }

    /**
     * Move the request clock, BOTH readings of it.
     *
     * $timestamp is seconds and $timestamp_usec is the same instant in
     * microseconds; the constructor takes them from a single microtime() reading
     * so they cannot disagree, and this keeps them that way. Assigning
     * ->timestamp alone would not move what v2 reads: `ts` is
     * TrackingEventHelpers::edgeTimestampMicroseconds(), yyyymmdd is derived from
     * ts, and the event id is a hash over ts.
     *
     * Seconds in, because that is what every caller has and it is the coarser of
     * the two: multiplying up cannot invent precision, where truncating down would
     * throw some away.
     *
     * Nothing in OWA calls it; tests use it to fix the request's time.
     *
     * @param  int $seconds
     * @return void
     */
    public function setTimestamp( $seconds ) {

        $this->timestamp      = (int) $seconds;
        $this->timestamp_usec = ( (int) $seconds ) * 1000000;
    }

    /**
     * Edge receipt in microseconds. See the constructor.
     *
     * @return int
     */
    public function getTimestampMicroseconds() {

        return $this->timestamp_usec;
    }

    /**
     * @return string|null
     */
    public function getCurrentUrl() {

        return $this->current_url;
    }

    /**
     * @return string
     */
    public function getRequestType() {

        return strtoupper( $this->request_type );
    }

}
