<?php
namespace OWA\Core;


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

/**
 * OWA Request Params
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

// TODO: replace with explicit property declarations (deprecated in PHP 8.2).
#[\AllowDynamicProperties]
class RequestContainer {

    var $cli_args;
    var $is_https;
    var $owa_params = array();
    var $cookies = array();
    var $owa_cookies = array();
    var $session = array();
    var $request = array();
    var $server;
    var $guid;
    var $state;
    var $request_type = '';
    var $timestamp;
    var $current_url;

    /**
     * Constructor
     *
     */
    function __construct() {

        $this->timestamp = time();
        $this->guid = \OWA\Core\Lib::generateRandomUid();

        // php's server variables
        $this->server = $_SERVER;
      
        // files
        if (!empty($_FILES)) {
            $this->files = $_FILES;
        }

        // setup cookies
        $this->cookies = array();

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
            if ( $_COOKIE && is_array($_COOKIE) ) {

                foreach ($_COOKIE as $n => $v) {
                    // hack against other frameworks sanitizing cookie data and blowing away our '>' delimiter
                    // this should be removed once all cookies are using json format.
                    if (strpos($v, '&gt;')) {
                        $v = str_replace("&gt;", ">", $v);
                    }

                    // $this->cookies, not a local: this branch used to
                    // populate an undeclared $cookies that went out of scope
                    // immediately, so a request whose Cookie header held a
                    // SINGLE cookie -- the only way to reach this branch, since
                    // the raw-header path above requires a ';' -- arrived with
                    // no cookies at all, and OWA saw a stateless visitor.
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
        $this->state = \OWA\Core\CoreAPI::supportClassFactory('base', 'state');
        // merges session
        if (!empty($this->session)) {
            $this->state->addStores(\OWA\Core\Lib::stripParams($this->session, \OWA\Core\CoreAPI::getSetting('base', 'ns')));
        }

        // merges cookies
        foreach ( $this->owa_cookies as $k => $owa_cookie ) {

            $this->state->setInitialState( $k, $owa_cookie );
        }

        // create request params and type
        $params = array();
		\OWA\Core\CoreAPI::debug('request container says params are:');
		if ( array_key_exists('REQUEST_METHOD', $_SERVER) ) {
				
				$this->request_type = $_SERVER['REQUEST_METHOD'];
				
			if ( $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE' ) {
			
				parse_str( trim(file_get_contents("php://input") ), $post_vars );
				\OWA\Core\CoreAPI::debug($post_vars);
				$params = array_merge( $_GET, $post_vars);
				
			} else if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
				
				$params = $_GET;
				
			} else if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
				
				// merge in POST vars. GET and POST can occure on the same request.		
				$params = array_merge( $_GET, $_POST);
			}
			
			$this->current_url = \OWA\Core\Lib::get_current_url();
			
			\OWA\Core\CoreAPI::debug($params);
			
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

                $params = \OWA\Module\Base\Classes\Sanitize::cleanInput( $params, array( 'remove_html' => true, 'escape_html' => false ) );

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
        foreach ( array( $ns . 'action', $ns . 'do', 'action', 'do' ) as $exploitable ) {

            if ( array_key_exists( $exploitable, $this->request ) ) {

                $this->request[ $exploitable ] =
                    \OWA\Core\Lib::fileInclusionFilter( $this->request[ $exploitable ] );
            }
        }

        /*
         * Resolve every param to its un-namespaced name.
         *
         * OWA emits its own admin and reporting URLs without the prefix now
         * (see the 'app_ns' setting), but every bookmark, saved report link and
         * third-party API caller in existence still spells them 'owa_do=...'.
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
         * in, which is what preserved that behaviour when the bare spelling was
         * added.
         *
         * The gate matters too. stripParams() does not just rename keys, it
         * FILTERS: anything without the prefix is dropped. That is what stops a
         * host application's query string reaching OWA's dispatcher when OWA is
         * embedded in someone else's request -- WordPress's own 'action' param
         * is exactly the collision the namespace was invented for. So bare
         * names are accepted only for entry points that own their whole query
         * string, and a caller that sets no instance_role -- which is every
         * embedded integration -- keeps the prefixed-only behaviour.
         */
        $ns_len = strlen( (string) $ns );

        $from_ns      = array();

        $this->owa_params = array();

        foreach ( $this->request as $n => $v ) {

            $n = (string) $n;

            if ( $ns && strpos( $n, $ns ) === 0 ) {

                $name = substr( $n, $ns_len );

                // a param named exactly the namespace has no name left
                if ( $name === '' || $name === false ) {
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

        // set https flag
        if( \OWA\Core\Lib::isHttps() ) {
            $this->is_https = true;
        }
    }

    function getParam($name) {

        if (array_key_exists($name, $this->owa_params)) {
            return $this->owa_params[$name];
        } else {
            return false;
        }

    }

    function setParam($name, $value) {

        $this->owa_params[$name] = $value;
        return true;
    }

    function getCookie($name) {

        if (array_key_exists($name, $this->cookies)) {
            return $this->cookies[$name];
        } else {
            return false;
        }

    }

    function getRequestParam($name) {

        if (array_key_exists($name, $this->request)) {
            return $this->request[$name];
        } else {
            return false;
        }
    }

    function getAllRequestParams() {

        return $this->request;
    }

    function getAllOwaParams() {

        return $this->owa_params;
    }

    function mergeParams($params) {

        $this->owa_params = array_merge($this->owa_params, $params);
        return;
    }

    function getServerParam($name) {

        if (array_key_exists($name, $this->server)) {
            return $this->server[$name];
        } else {
            return false;
        }
    }

    function decodeRequestParams() {

        $params = array();
        // Apply caller specific params
        foreach ($this->owa_params as $k => $v) {
            if (is_array($v)) {
                array_walk_recursive($v, array($this, 'arrayUrlDecode'));
                $params[$k] = $v;
            } else {
                $params[$k] = is_null($v)?$v:rawurldecode($v);
            }
        }

        // clean params after decode
        //$params = owa_lib::inputFilter($params);
        // replace owa params
        $this->owa_params = $params;
        //debug
        \OWA\Core\CoreAPI::debug('decoded OWA params: '. print_r($this->owa_params, true));
        return;

    }

    function arrayUrlDecode(&$val, $index) {
        
        rawurldecode($val);
    }

    function getOwaCookie($name) {

        if (array_key_exists($name, $this->owa_cookies)) {
            return $this->owa_cookies[$name];
        } else {
            return false;
        }

    }

    public function getTimestamp() {

        return $this->timestamp;
    }

    public function getCurrentUrl() {

        return $this->current_url;
    }

    public function getRequestType() {

        return strtoupper( $this->request_type );
    }

}

?>
