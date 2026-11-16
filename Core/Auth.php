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
 * User Authentication Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class Auth extends \OWA\Core\Base {

    /**
     * User object
     *
     * @var mixed
     */
    var $u;

    /**
     * Array of permission roles that users can have
     *
     * @var array
     */
    var $roles;

    var $status_msg;

    /**
     * Login credentials
     *
     * @var array
     */
    var $credentials = array();

    /**
     * Status of Authentication
     *
     * @var boolean
     */
    var $auth_status = false;

    var $_is_user = false;

    var $params;

    var $auth_method;

    var $eq;

    /**
     * How long a session cookie is valid from when it is issued, in seconds.
     */
    const SESSION_LIFETIME = 172800;

    /**
     * A request signed in by the cookie with less than this many seconds left
     * on it is given a fresh one.
     */
    const SESSION_REFRESH_BELOW = 86400;

    /**
     * The `p` cookie: `<expiry>.<hmac>`, expiry a unix timestamp.
     */
    const SESSION_CREDENTIAL_PATTERN = '/^([0-9]{1,10})\.([a-f0-9]{64})$/';

    /**
     * Auth class Singleton
     *
     * @return \OWA\Core\Auth
     */
    public static function get_instance($plugin = '') {

        static $auth;

        if (!$auth) {

            $auth = new \OWA\Core\Auth();

        }

        return $auth;
    }


    /**
     * Class Constructor
     *
     * @return \OWA\Core\Auth
     */
    function __construct() {

        // register auth cookies
        \OWA\Core\CoreAPI::registerStateStore('u', time()+3600*24*365*10, '', '', 'cookie');
        \OWA\Core\CoreAPI::registerStateStore('p', time()+3600*2, '', '', 'cookie');

        parent::__construct();
        $this->eq = \OWA\Core\CoreAPI::getEventDispatch();
    }

    /**
     * Used by controllers to check if the user exists and if they are priviledged.
     *
     * $methods limits which credentials are tried: a branch whose method is
     * not listed is skipped, so its credential is never read and the request
     * is judged on the ones the caller accepts. A login form's user_id and
     * password on a cookies-only endpoint are then plain parameters, not a
     * sign-in that writes the session cookies. Null tries every method, in
     * the order below. See Controller::setAuthMethods().
     *
     * @param string[]|null $methods login_form, overlay_token, app_password, api_key, cookies
     */
    function authenticateUser( $methods = null ) {

        $accepts = function ( $method ) use ( $methods ) {

            return $methods === null || in_array( $method, (array) $methods, true );
        };
		
		$apiKey = \OWA\Core\CoreAPI::getRequestParam('apiKey') ?: \OWA\Core\CoreAPI::getServerParam( 'HTTP_X_API_KEY' );
	
        // check existing auth status first in case someone else took care of this already.
        if (\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated()) {
	        \OWA\Core\CoreAPI::debug('User is already authenticated.');
            $ret = true;
        
        } elseif ($accepts('login_form') && \OWA\Core\CoreAPI::getRequestParam('user_id') && \OWA\Core\CoreAPI::getRequestParam('password')) {
            // auth user by login form input
            $this->setAuthMethod( 'login_form');
            $ret = $this->authByInput(\OWA\Core\CoreAPI::getRequestParam('user_id'), \OWA\Core\CoreAPI::getRequestParam('password'));
             \OWA\Core\CoreAPI::debug('User authenticated via form input.');
    
        } elseif ( is_array( $methods ) && in_array( 'overlay_token', $methods, true )
                   && \OWA\Core\CoreAPI::getRequestParam('overlayToken') ) {
            // Only where a controller names it (setAuthMethods()): the routes an
            // overlay reads, not every route that accepts any credential.
            // auth by a scoped, short-lived overlay token.
            //
            // Checked BEFORE the apiKey branch so that a request carrying both
            // is held to the narrower credential, and scope is enforced here
            // rather than later: a request outside the token's scope does not
            // authenticate at all, so there is no window in which a caller is
            // authenticated but not yet constrained.
            $this->setAuthMethod( 'overlay_token');
            $ret = $this->authByOverlayToken( \OWA\Core\CoreAPI::getRequestParam('overlayToken') );
            \OWA\Core\CoreAPI::debug('User authenticated via overlay token.');
        } elseif ( $accepts('app_password') && ( $appPassword = $this->appPasswordCredential() ) ) {
            // An application password in the Authorization header. Before the
            // apiKey and the cookies, and a failure here does not fall through
            // to either: the request named a credential and it did not hold.
            $this->setAuthMethod( 'app_password' );
            $ret = $this->authByAppPassword( $appPassword['token'], $appPassword['user_id'] );
        } elseif ( $accepts('api_key') && $apiKey ) {
            // auth user by api key
            $this->setAuthMethod( 'api_key');
            $ret = $this->authByApiKey( $apiKey );
            \OWA\Core\CoreAPI::debug('User authenticated via api key.');
    
        } elseif ($accepts('cookies') && \OWA\Core\CoreAPI::getStateParam('u') && \OWA\Core\CoreAPI::getStateParam('p')) {
            // auth user by cookies
            $this->setAuthMethod( 'cookies');
            $ret = $this->authByCookies(\OWA\Core\CoreAPI::getStateParam('u'), \OWA\Core\CoreAPI::getStateParam('p'));
             \OWA\Core\CoreAPI::debug('User authenticated via cookies.');
            
        } else {
            $ret = false;
            \OWA\Core\CoreAPI::debug("Could not find any credentials to authenticate with.");
        }

        // filter results for modules can add their own auth logic.
        $ret = $this->eq->filter('auth_status', $ret);

        return array('auth_status' => $ret);

    }

    /**
     * The action the request is routed to: the route in `rest_params`
     * (module/version/route), which CoreAPI::handleRestRequest() runs in
     * preference to `do`, else `do`. A token is checked against what runs.
     *
     * @return string
     */
    public static function routedAction() {

        $rest  = (string) \OWA\Core\CoreAPI::getRequestParam('rest_params');
        $parts = $rest !== '' ? explode( '/', $rest ) : array();

        return count( $parts ) >= 3
            ? (string) $parts[2]
            : (string) \OWA\Core\CoreAPI::getRequestParam('do');
    }

    /**
     * Authenticates an overlay request (the heatmap, or a module's).
     *
     * These run on the *tracked* site and call back to the OWA origin, so they
     * are cross-origin: a session cookie would be a third-party cookie, and an
     * Authorization header would turn a simple GET into a preflighted one. The
     * credential has to sit in the URL, so what matters is that it is worth as
     * little as possible to anyone who reads it there.
     *
     * It replaces the user's apiKey, which was long-lived and carried the whole
     * account. This carries one user, one endpoint, one resource, and expires
     * in minutes.
     *
     * **Scope is checked here, before the user is loaded**, so a request outside
     * the token's scope never authenticates. Deferring the check to an
     * authorisation layer would leave a window in which the caller is
     * authenticated but unconstrained, and the constraint is the entire point.
     *
     * @param    string    $token
     * @return    boolean
     */
    function authByOverlayToken( $token ) {

        $action = self::routedAction();

        $permitted = \OWA\Core\OverlayToken::permits(
            $token,
            $action,
            function( $name ) {

                return \OWA\Core\CoreAPI::getRequestParam( $name );
            }
        );

        if ( ! $permitted ) {

            \OWA\Core\CoreAPI::debug('Overlay token missing, expired, or out of scope for this request.');

            return false;
        }

        $claims = \OWA\Core\OverlayToken::verify( $token );

        $this->u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $this->u->load( $claims['user_id'], 'user_id' );

        if ( ! $this->u->get( 'user_id' ) ) {

            \OWA\Core\CoreAPI::debug('Overlay token names a user that no longer exists.');

            return false;
        }

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $cu->loadNewUserByObject( $this->u );
        $cu->setAuthStatus( true );
        $this->_is_user = true;

        return true;
    }

    /**
     * The application password a REST request carries, if it carries one.
     *
     * `Authorization: Bearer <token>`, or `Basic` with the user_id and the
     * token. Only a value shaped like a token counts: a site behind HTTP Basic
     * auth sends its own password on every request, including the reporting
     * screens' REST calls, and that must not be read as a failed application
     * password.
     *
     * Header only. A token in the URL ends up in server logs and Referer
     * headers.
     *
     * @return array|null token, user_id (null for Bearer)
     */
    private function appPasswordCredential() {

        if ( ! \OWA\Core\RequestType::is( \OWA\Core\RequestType::REST ) ) {

            return null;
        }

        $header = (string) ( \OWA\Core\CoreAPI::getServerParam( 'HTTP_AUTHORIZATION' )
            ?: \OWA\Core\CoreAPI::getServerParam( 'REDIRECT_HTTP_AUTHORIZATION' ) );

        $token   = '';
        $user_id = null;

        if ( preg_match( '/^Bearer\s+(\S+)\s*$/i', $header, $m ) ) {

            $token = $m[1];

        } elseif ( preg_match( '/^Basic\s+(\S+)\s*$/i', $header, $m ) ) {

            $pair = (string) base64_decode( $m[1], true );
            // The last colon: a token has none, a user_id might.
            $at   = strrpos( $pair, ':' );

            if ( $at !== false ) {

                $user_id = substr( $pair, 0, $at );
                $token   = substr( $pair, $at + 1 );
            }

        } elseif ( $header === '' && \OWA\Core\CoreAPI::getServerParam( 'PHP_AUTH_PW' ) ) {

            // A server that parsed Basic auth itself and passed on only the parts.
            $user_id = (string) \OWA\Core\CoreAPI::getServerParam( 'PHP_AUTH_USER' );
            $token   = (string) \OWA\Core\CoreAPI::getServerParam( 'PHP_AUTH_PW' );
        }

        if ( ! \OWA\Module\Base\Classes\AppPasswords::looksLikeToken( $token ) ) {

            return null;
        }

        return array( 'token' => $token, 'user_id' => $user_id );
    }

    /**
     * Authenticate a REST request by an application password.
     *
     * The password acts as its user with that user's role now, so a role
     * change or a deleted user takes effect on the next request. A read-only
     * password authenticates only a GET.
     *
     * @param  string      $token
     * @param  string|null $user_id the Basic auth user, which must be the password's own
     * @return bool
     */
    function authByAppPassword( $token, $user_id = null ) {

        if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'app_passwords_enabled' ) ) {

            \OWA\Core\CoreAPI::debug( 'Application passwords are turned off.' );

            return false;
        }

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'app_passwords_require_https' ) && ! \OWA\Core\Lib::isHttps() ) {

            \OWA\Core\CoreAPI::debug( 'Application password refused: the request is not HTTPS.' );

            return false;
        }

        $password = \OWA\Module\Base\Classes\AppPasswords::verify( $token );

        if ( ! $password ) {

            \OWA\Core\CoreAPI::debug( 'Application password not recognised.' );

            return false;
        }

        if ( $user_id !== null && (string) $user_id !== (string) $password['user_id'] ) {

            \OWA\Core\CoreAPI::debug( 'Application password belongs to a different user.' );

            return false;
        }

        $method = \OWA\Core\CoreAPI::serviceSingleton()->request->getRequestType();

        if ( $password['scope'] === \OWA\Module\Base\Classes\AppPasswords::SCOPE_READ
             && ! in_array( $method, \OWA\Module\Base\Classes\AppPasswords::READ_METHODS, true ) ) {

            \OWA\Core\CoreAPI::debug( "Read-only application password refused for a $method request." );

            return false;
        }

        $this->u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $this->u->load( $password['user_id'], 'user_id' );

        if ( ! $this->u->get( 'user_id' ) ) {

            \OWA\Core\CoreAPI::debug( 'Application password names a user that no longer exists.' );

            return false;
        }

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $cu->loadNewUserByObject( $this->u );
        $cu->setAuthStatus( true );
        $this->_is_user = true;

        \OWA\Module\Base\Classes\AppPasswords::recordUse( $password['lookup_id'], self::lastPublicHop() );

        return true;
    }

    /**
     * The client address as the nearest proxy saw it.
     *
     * The rightmost public address in X-Forwarded-For, not the leftmost: each
     * proxy appends, so the left end is whatever the client chose to send.
     *
     * @return string
     */
    private static function lastPublicHop() {

        $chain = explode( ',', (string) \OWA\Core\CoreAPI::getServerParam( 'HTTP_X_FORWARDED_FOR' ) );

        foreach ( array_reverse( $chain ) as $hop ) {

            if ( \OWA\Core\Lib::isNotPrivateIp( trim( $hop ) ) ) {

                return trim( $hop );
            }
        }

        return (string) \OWA\Core\CoreAPI::getServerParam( 'REMOTE_ADDR' );
    }

    function authByApiKey( $key ) {

        $key = \OWA\Module\Base\Classes\Sanitize::cleanMd5( $key );

        if ( $key ) {
	        
	        // check signature of request
	        if ( ! \OWA\Core\Lib::inRestDebug() ) {
		    	
		    	//get current request url
		    	$url = \OWA\Core\CoreAPI::getCurrentUrl();
				//owa_coreAPI::debug('request url' . $url);
		    	
		    	//get signatureparam  of request
		        $signature = \OWA\Core\CoreAPI::getRequestParam('signature') ?: \OWA\Core\CoreAPI::getServerParam( 'HTTP_X_SIGNATURE' );
		        
		        \OWA\Core\CoreAPI::debug('Signature: ' . $signature );
		         
		        // check if signature is exists and is valid
		        if ( ! $signature || ! $this->isSignatureValid( $signature, $key, $url ) ) {
			        
			       \OWA\Core\CoreAPI::debug('signature missing from request or not valid.');
			       return false;
		        }
		        
			} else {
				
				\OWA\Core\CoreAPI::debug('skipping signature check in debug/development mode.');
			}
			
            // fetch user object from the db
            $this->u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
            $this->u->load( $key, 'api_key' );

            if ( $this->u->get( 'user_id' ) ) {
                // get current user
                $cu = \OWA\Core\CoreAPI::getCurrentUser();
                
                // set as new current user in service layer
                $cu->loadNewUserByObject( $this->u );
                $cu->setAuthStatus( true );
                $this->_is_user = true;
                
                return true;
                
            } else {
	            
                return false;
            }

        } else {

            return false;
        }

    }

    /**
     * Sign in by the `u` and `p` cookies (see sessionCredential()).
     *
     * A sign-in with less than SESSION_REFRESH_BELOW left is given a fresh `p`,
     * so a session ends between SESSION_REFRESH_BELOW and SESSION_LIFETIME
     * after its last request, not at a fixed time after its sign-in.
     */
    function authByCookies($user_id, $credential) {

        // set credentials
        $this->credentials['user_id'] = \OWA\Module\Base\Classes\Sanitize::cleanUserId( $user_id );
        $this->credentials['password'] = $credential;
        unset( $this->credentials['new_password'] );
		\OWA\Core\CoreAPI::debug('auth by cookies');
        // lookup user if not already done.
        if ($this->_is_user == false) {

            // check to see if the current user has already been authenticated by something upstream
            $cu = \OWA\Core\CoreAPI::getCurrentUser();
            if (!$cu->isAuthenticated()) {
                // check to see if they are a user.
                $val = $this->isUser();

                if ( $val === true && self::sessionCredentialNeedsRefresh( $credential ) ) {

                    $this->writeSessionCredential();
                }

                return $val;
            }
        } else {
            return true;
        }
    }

    function authByInput($user_id, $password) {

        // set credentials
        $this->credentials['user_id'] = \OWA\Module\Base\Classes\Sanitize::cleanUserId( $user_id );
        unset( $this->credentials['password'] );
        // the typed password, checked against the stored hash
        $this->credentials['new_password'] = $password;
        $ret = $this->isUser();

        if ($ret === true) {
            $this->saveCredentials();
        }

        return $ret;
    }

    /**
     * Looks up user by temporary Passkey Column in db
     *
     * @param mixed $key
     * @return unknown
     */
    function authenticateUserTempPasskey( $key ) {

        // Only a current key finds its account: an expired one, or one minted
        // before keys expired, sets nothing (User::TEMP_PASSKEY_PATTERN).
        $key = \OWA\Module\Base\Entity\User::tempPasskeyIsCurrent( $key ) ? $key : false;

        if ( $key ) {

            $this->u = \OWA\Core\CoreAPI::entityFactory('base.user');
            $this->u->getByColumn('temp_passkey', $key);

            $id = $this->u->get('id');

            if (!empty($id)) {

                return true;

            } else {

                return false;
            }

        } else {

            return false;
        }
    }

    /**
     * Writes the session cookies at sign-in: `u`, the user_id, and `p`.
     *
     * HttpOnly: nothing in the browser needs them, and with them a script
     * injected into any OWA page would have the account. Secure on an HTTPS
     * request (Lib::isHttps()).
     */
    function saveCredentials() {

        \OWA\Core\CoreAPI::debug('saving user credentials to cookies');

        $this->writeCookie( 'u', $this->u->get('user_id'), time()+3600*24*365*10 );
        $this->writeSessionCredential();
    }

    /**
     * Writes `p` for the signed-in user, expiring SESSION_LIFETIME from now.
     */
    protected function writeSessionCredential() {

        $expires = time() + self::SESSION_LIFETIME;

        $this->writeCookie( 'p', self::sessionCredential(
            $this->u->get('user_id'), $this->u->get('session_epoch'), $this->u->get('password'), $expires ), $expires );
    }

    /** A session cookie: HttpOnly, and Secure on an HTTPS request. */
    protected function writeCookie( $name, $value, $expires ) {

        $this->emitCookie( $name, $value, $expires, (bool) \OWA\Core\Lib::isHttps(), true );
    }

    protected function emitCookie( $name, $value, $expires, $secure, $httponly ) {

        \OWA\Core\CoreAPI::createCookie( $name, $value, $expires, '/', '', $secure, $httponly );
    }

    /**
     * Removes this browser's `p`. The credential itself stays valid until it
     * expires; User::endSessions() ends it everywhere.
     *
     * @return boolean
     */
    function deleteCredentials() {

        return \OWA\Core\CoreAPI::clearState('p');
    }

    /**
     * Simple Password Encryption Scheme
     *
     * @param string $password
     * @return string
     */
    function encryptPassword($password) {

        return \OWA\Core\Lib::encryptPassword($password);

    }
    function encryptOldPassword($password) {

        return \OWA\Core\Lib::encryptOldPassword($password);

    }

    function getUser() {

        // fetch user object from the db
        \OWA\Core\CoreAPI::debug('auth get user: '. $this->credentials['user_id'] );
        
        $this->u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $this->u->getByColumn('user_id', $this->credentials['user_id']);
    }

    /**
     * The values the old User::generateRandomPassword() gave an account created
     * without a password (a hash prefix). A login with one is refused whatever
     * the stored hash says; the account's owner sets a password through the
     * emailed link.
     */
    const LEGACY_GENERATED_PASSWORD = '/^(\\$2[aby]?\\$\\d\\d|\\$argon)$/';

    static function isLegacyGeneratedPassword( $password ) {

        return is_string( $password ) && preg_match( self::LEGACY_GENERATED_PASSWORD, $password ) === 1;
    }

    /**
     * Whether the credentials (a typed password, or the `p` cookie) sign in
     * the user they name. On success the user is the current user.
     *
     * @return boolean
     */
    function isUser() {

        // get current user
        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        // fetches user object from DB
        $this->getUser();

        if ( $this->credentials['user_id'] !== $this->u->get('user_id') ) {

            $this->_is_user = false;
            return false;
        }

        // new_password is set only by authByInput; otherwise the credential is the `p` cookie.
        if ( isset( $this->credentials['new_password'] ) ) {

            $valid = $this->passwordMatches( $this->credentials['new_password'], (string) $this->u->get('password') );

        } else {

            $valid = self::isValidSessionCredential(
                $this->credentials['password'] ?? null,
                $this->credentials['user_id'],
                $this->u->get('session_epoch'),
                $this->u->get('password')
            );
        }

        if ( ! $valid ) {

            $this->_is_user = false;
            return false;
        }

        $this->_is_user = true;

        // set as new current user in service layer
        $cu->loadNewUserByObject( $this->u );
        $cu->setAuthStatus(true);

        return true;
    }

    /**
     * Whether a typed password is the account's: the stored hash, or, for an
     * account whose password 1.x stored unsalted, the whole of that hash.
     */
    private function passwordMatches( $typed, $stored ) {

        if ( ! is_string( $typed ) || $typed === '' || self::isLegacyGeneratedPassword( $typed ) ) {

            return false;
        }

        if ( password_verify( $typed, $stored ) ) {

            return true;
        }

        return $stored !== '' && hash_equals( $stored, $this->encryptOldPassword( $typed ) );
    }

    /**
     * The `p` cookie's value.
     *
     * The HMAC, with the install's auth secret, covers the user_id, the user's
     * session_epoch, the expiry and four characters of the password hash. It
     * stops signing anyone in when it expires, when the epoch moves (sign-out,
     * a password change: User::endSessions()) or when the password hash
     * changes.
     *
     * @param string $user_id
     * @param int    $session_epoch
     * @param string $password_hash
     * @param int    $expires unix timestamp
     * @return string
     */
    static function sessionCredential( $user_id, $session_epoch, $password_hash, $expires ) {

        $expires = (int) $expires;

        return $expires . '.' . self::sessionHmac( $user_id, $session_epoch, $password_hash, $expires );
    }

    private static function sessionHmac( $user_id, $session_epoch, $password_hash, $expires ) {

        // Only user_id is free text, and it comes first, so the fields cannot run together.
        $data = implode( '|', array(
            (string) $user_id,
            (int) $session_epoch,
            (int) $expires,
            substr( (string) $password_hash, 8, 4 ),
        ) );

        return hash_hmac( 'sha256', $data, \OWA\Core\CoreAPI::getSalt( 'auth' ) );
    }

    /**
     * A `p` value's expiry, or null when the value is not one.
     *
     * @param mixed $credential
     * @return int|null
     */
    static function sessionCredentialExpiry( $credential ) {

        if ( ! is_string( $credential ) || ! preg_match( self::SESSION_CREDENTIAL_PATTERN, $credential, $m ) ) {

            return null;
        }

        return (int) $m[1];
    }

    /**
     * Whether a `p` value signs this user in now: well formed, not expired,
     * and the HMAC of the user's current session_epoch and password hash.
     *
     * @param mixed    $credential
     * @param string   $user_id
     * @param int      $session_epoch
     * @param string   $password_hash
     * @param int|null $now
     * @return bool
     */
    static function isValidSessionCredential( $credential, $user_id, $session_epoch, $password_hash, $now = null ) {

        $expires = self::sessionCredentialExpiry( $credential );

        if ( $expires === null || $expires <= ( $now ?? time() ) ) {

            return false;
        }

        return hash_equals(
            self::sessionCredential( $user_id, $session_epoch, $password_hash, $expires ),
            $credential
        );
    }

    /**
     * Whether a valid `p` value has less than SESSION_REFRESH_BELOW left.
     *
     * @param mixed    $credential
     * @param int|null $now
     * @return bool
     */
    static function sessionCredentialNeedsRefresh( $credential, $now = null ) {

        $expires = self::sessionCredentialExpiry( $credential );

        return $expires !== null && $expires - ( $now ?? time() ) < self::SESSION_REFRESH_BELOW;
    }

    function generateSignature( $requestUrl, $apiKey ) {
	    
	    // get the current time zone
		$tz = date_default_timezone_get();
		
		// switch to UTC for signature check
		date_default_timezone_set('UTC');
		
		// generate date
	    $date = date( 'Ymd', time() );
	   	
	   	// switch time zone back to prior setting
	    date_default_timezone_set($tz);
	    
	    return base64_encode( hash('sha256', 'OWASIGNATURE' . $apiKey . $requestUrl . $date . OWA_AUTH_KEY ) );
    }
    
    function isSignatureValid( $signature, $apiKey, $requestUrl ) {
	    
		// '_' is jQuery's cache-buster, appended after the URL is signed, so it
		// must stay excluded. owa_jsonpCallback was excluded for the same reason
		// and is gone with JSONP -- keeping the carve-out would leave a param
		// that could be set to anything without invalidating the signature.
		$requestUrl = \OWA\Core\Lib::removeQueryParamFromUrl( $requestUrl, '_' );
		
	    
		// The signature cannot cover itself, so it comes off before hashing.
		// Both spellings are removed: OWA still signs with the namespaced name,
		// but un-namespaced params are readable now, so a caller that sends
		// 'signature=' would otherwise be read as authenticating and then fail
		// the comparison for a reason nothing in the log would explain.
		foreach ( array( 'owa_signature', 'signature' ) as $sig_param ) {

			$requestUrl = \OWA\Core\Lib::removeQueryParamFromUrl( $requestUrl, $sig_param );
		}
	    
	    
	    //owa_coreAPI::debug('url w/ no sig: ' . $requestUrl );
	    
	    //$signature = base64_decode( $signature );
	    
	    $computed_signature = $this->generateSignature( $requestUrl, $apiKey );
	    \OWA\Core\CoreAPI::debug( 'computed signature: ' . $computed_signature );
	    \OWA\Core\CoreAPI::debug( 'request url: ' . $requestUrl );
	    
	    if ( $signature === $computed_signature ) {
		    
		    \OWA\Core\CoreAPI::debug('API request signature is valid.' );
		    return true;
		    
	    } else {
		    
		    \OWA\Core\CoreAPI::debug('API request signature failed validation.' );
		    return false;
	    }
    }
    
    // sets the method by which the user was authenticated
    // @method  string
    private function setAuthMethod( $method ) {
        
        $this->auth_method = $method;
    }
    
    public function getAuthMethod() {
        
        return $this->auth_method;
    }
}

?>
