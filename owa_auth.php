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

require_once(OWA_BASE_CLASS_DIR.'sanitize.php');

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
class owa_auth extends owa_base {

    /**
     * User object
     *
     * @var unknown_type
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

    var $_priviledge_level;

    var $_is_priviledged = false;

    var $params;

    var $check_for_credentials = false;

    /**
     * Auth class Singleton
     *
     * @return owa_auth
     */
    public static function get_instance($plugin = '') {

        static $auth;

        if (!$auth) {

            $auth = new owa_auth();

        }

        return $auth;
    }


    /**
     * Class Constructor
     *
     * @return owa_auth
     */
    function __construct() {

        // register auth cookies
        owa_coreAPI::registerStateStore('u', time()+3600*24*365*10, '', '', 'cookie');
        owa_coreAPI::registerStateStore('p', time()+3600*2, '', '', 'cookie');

        parent::__construct();
        $this->eq = owa_coreAPI::getEventDispatch();
    }

    /**
     * Used by controllers to check if the user exists and if they are priviledged.
     *
     * @param string $necessary_role
     */
    function authenticateUser() {
		
		$apiKey = owa_coreAPI::getRequestParam('apiKey') ?: owa_coreAPI::getServerParam( 'HTTP_X_API_KEY' );
	
        // check existing auth status first in case someone else took care of this already.
        if (owa_coreAPI::getCurrentUser()->isAuthenticated()) {
	        owa_coreAPI::debug('User is already authenticated.');
            $ret = true;
        } elseif ( $apiKey ) {
            // auth user by api key
            $ret = $this->authByApiKey( $apiKey );
            owa_coreAPI::debug('User authenticated via api key.');
        } elseif (owa_coreAPI::getRequestParam('pk') && owa_coreAPI::getStateParam('u')) {
            // auth user by temporary passkey. used in forgot password situations
            $ret = $this->authenticateUserByUrlPasskey(owa_coreAPI::getRequestParam('pk'));
             owa_coreAPI::debug('User authenticated via temporary passkey.');
        } elseif (owa_coreAPI::getRequestParam('user_id') && owa_coreAPI::getRequestParam('password')) {
            // auth user by login form input
            $ret = $this->authByInput(owa_coreAPI::getRequestParam('user_id'), owa_coreAPI::getRequestParam('password'));
             owa_coreAPI::debug('User authenticated via form input.');
        } elseif (owa_coreAPI::getStateParam('u') && owa_coreAPI::getStateParam('p')) {
            // auth user by cookies
            $ret = $this->authByCookies(owa_coreAPI::getStateParam('u'), owa_coreAPI::getStateParam('p'));
             owa_coreAPI::debug('User authenticated via cookies.');
            // bump expiration time
            //owa_coreAPI::setState('p', '', owa_coreAPI::getStateParam('p'));
        } else {
            $ret = false;
            owa_coreAPI::debug("Could not find any credentials to authenticate with.");
        }

        // filter results for modules can add their own auth logic.
        $ret = $this->eq->filter('auth_status', $ret);

        return array('auth_status' => $ret);

    }

    function authByApiKey( $key ) {

        $key = owa_sanitize::cleanMd5( $key );

        if ( $key ) {
	        
	        // check signature of request 
	        if ( ! owa_lib::inRestDebug() ) {
		    	
		    	//get current request url
		    	$url = owa_coreAPI::getCurrentUrl();
				//owa_coreAPI::debug('request url' . $url);
		    	
		    	//get signatureparam  of request
		        $signature = owa_coreAPI::getRequestParam('signature') ?: owa_coreAPI::getServerParam( 'HTTP_X_SIGNATURE' ); 
		        
		        owa_coreAPI::debug('Signature: ' . $signature );
		         
		        // check if signature is exists and is valid
		        if ( ! $signature || ! $this->isSignatureValid( $signature, $key, $url ) ) {
			        
			       owa_coreAPI::debug('signature missing from request or not valid.');
			       return false;
		        }
		        
			} else {
				
				owa_coreAPI::debug('skipping signature check in debug/development mode.');
			}
			
            // fetch user object from the db
            $this->u = owa_coreAPI::entityFactory( 'base.user' );
            $this->u->load( $key, 'api_key' );

            if ( $this->u->get( 'user_id' ) ) {
                // get current user
                $cu = owa_coreAPI::getCurrentUser();
                
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

    function authByCookies($user_id, $password) {

        // set credentials
        $this->credentials['user_id'] = owa_sanitize::cleanUserId( $user_id );
        $this->credentials['password'] = $password;

        // lookup user if not already done.
        if ($this->_is_user == false) {

            // check to see if the current user has already been authenticated by something upstream
            $cu = owa_coreAPI::getCurrentUser();
            if (!$cu->isAuthenticated()) {
                // check to see if they are a user.
                $val = $this->isUser();
                //print ("val: ". $val);
                return $val;
            }
        } else {
            return true;
        }
    }

    function authByInput($user_id, $password) {

        // set credentials
        $this->credentials['user_id'] = owa_sanitize::cleanUserId( $user_id );
        // must encrypt password to see if it matches whats in the db
        $this->credentials['password'] = $this->generateAuthCredential( $this->credentials['user_id'], $this->encryptOldPassword( $password ) );
        // pass plain text password to test with password_verify
        $this->credentials['new_password'] = $password;
        //owa_coreAPI::debug(print_r($this->credentials, true));
        $ret = $this->isUser();

        if ($ret === true) {
            $this->saveCredentials();
        }

        return $ret;
    }

    /**
     * Looks up user by temporary Passkey Column in db
     *
     * @param unknown_type $key
     * @return unknown
     */
    function authenticateUserTempPasskey( $key ) {

        $key = owa_sanitize::cleanMd5( $key );

        if ( $key ) {

            $this->u = owa_coreAPI::entityFactory('base.user');
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
     * Authenticates user by a passkey
     *
     * @param unknown_type $key
     * @return unknown
     */
    function authenticateUserByUrlPasskey($user_id, $passkey) {

        $passkey = owa_sanitize::cleanMd5( $passkey );

        if ( $passkey ) {

            // set credentials
            $this->credentials['user_id'] = $user_id;
            $this->credentials['passkey'] = $passkey;

            // fetch user obj
            $this->getUser();

            // generate a new passkey from its components in the db
            $key = $this->generateUrlPasskey($this->u->get('user_id'), $this->u->get('password'));

            // see if it matches the key on the url
            if ($key == $passkey) {

                return true;

            } else {
                return false;
            }

        } else {

            return false;
        }
    }

    /**
     * Sets a temporary Passkey for a user
     *
     * @param string $email_address
     * @return boolean
     */
    function setTempPasskey($email_address) {

        $this->u = owa_coreAPI::entityFactory('base.user');
        $this->u->getByColumn('email_address', $email_address);

        $id = $u->get('id');

        if (!empty($id)):

            $this->eq->log(array('email_address' => $this->u->email_address), 'user.set_temp_passkey');
            return true;
        else:
            return false;
        endif;

    }

    function generateTempPasskey($seed) {

        return md5($seed.time().rand());
    }

    function generateUrlPasskey($user_name, $password) {

        return md5($user_name . $password);

    }

    /**
     * Sets the initial Passkey for a new user
     *
     * @param string $user_id
     * @return boolean
     * @deprecated
     */
    function setInitialPasskey($user_id) {

        return $this->eq->log(array('user_id' => $user_id), 'user.set_initial_passkey');

    }

    /**
     * Saves login credentails to persistant browser cookies
     * TODO: refactor to use state facility
     */
    function saveCredentials() {

        $this->e->debug('saving user credentials to cookies');

        if (PHP_VERSION_ID < 70300) {
            setcookie($this->config['ns'].'u', $this->u->get('user_id'), time()+3600*24*365*10, '/; samesite=Lax', $this->config['cookie_domain']);
            setcookie($this->config['ns'].'p', $this->generateAuthCredential( $this->credentials['user_id'], $this->u->get('password') ), time()+3600*24*2, '/; samesite=Lax', $this->config['cookie_domain']);
        } else {
            setcookie($this->config['ns'].'u', $this->u->get('user_id'), [
                'expires' => time()+3600*24*365*10,
                'path' => '/',
                'samesite' => 'Lax',
                'domain' => $this->config['cookie_domain'],
            ]);
            setcookie($this->config['ns'].'p', $this->generateAuthCredential( $this->credentials['user_id'], $this->u->get('password') ), [
                'expires' => time()+3600*24*365*10,
                'path' => '/',
                'samesite' => 'Lax',
                'domain' => $this->config['cookie_domain'],
            ]);
        }
    }

    /**
     * Removes credentials
     * @return boolean
     */
    function deleteCredentials() {

        return owa_coreAPI::clearState('p');
    }

    /**
     * Simple Password Encryption Scheme
     *
     * @param string $password
     * @return string
     */
    function encryptPassword($password) {

        return owa_lib::encryptPassword($password);

    }
    function encryptOldPassword($password) {

        return owa_lib::encryptOldPassword($password);

    }

    function getUser() {

        // fetch user object from the db
        $this->u = owa_coreAPI::entityFactory('base.user');
        $this->u->getByColumn('user_id', $this->credentials['user_id']);
    }

    /**
     * Checks to see if the user credentials match a real user object in the DB
     *
     * @return boolean
     */
    function isUser() {

        // get current user
        $cu = owa_coreAPI::getCurrentUser();

        // fetches user object from DB
        $this->getUser();

        if ( $this->credentials['user_id'] === $this->u->get('user_id') ) {

            // new_password will only be set when using authByInput
            if ( isset($this->credentials['new_password']) ) {
                // plain text password matches DB password we can authorize
                if ( password_verify( $this->credentials['new_password'], $this->u->get('password') ) ) {
                $this->_is_user = true;

                // set as new current user in service layer
                $cu->loadNewUserByObject( $this->u );
                $cu->setAuthStatus(true);

                return true;
                }
            }

            //if ($this->credentials['password'] === $this->u->get('password')):
            if ( $this->isValidAuthCredential( $this->credentials['user_id'], $this->credentials['password'] ) ) {
                $this->_is_user = true;

                // set as new current user in service layer
                $cu->loadNewUserByObject( $this->u );
                $cu->setAuthStatus(true);

                return true;
            } else {
                $this->_is_user = false;
                return false;
            }
        } else {
            $this->_is_user = false;
            return false;
        }
    }

    function isValidAuthCredential( $user_id, $passed_credential ) {

        $hash = '';
        $hash = $this->reconstructAuthCredential( $user_id );
        //print "hash: $hash ";
        //print "passed credential: $passed_credential ";
        if ( function_exists('hash_equals') ) {

            return hash_equals( $hash, $passed_credential );

        } else {

            if ( $hash === $passed_credential ) {

                return true;
            }
        }
    }

    function reconstructAuthCredential( $user_id ) {

        $u = owa_coreAPI::entityFactory('base.user');

        $u->getByColumn( 'user_id', $user_id );

        $password = $u->get('password');
        //print "password $password";
        return $this->generateAuthCredential( $user_id, $password );
    }

    function generateAuthCredential($user_id, $password, $expiration = '', $scheme = 'auth') {

        $frag = substr($password, 8, 4);

        $key = owa_coreAPI::saltedHash( $user_id . $frag . $expiration, $scheme );

        $algo = 'sha1';

        if ( function_exists( 'hash' ) ) {

            $algo = 'sha256';

        }

        $hash = owa_lib::hash( $algo, $user_id . $expiration, $key );

        $credential = $hash; // could add other elements here.

        return $credential;
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
	    
	    $requestUrl = owa_lib::removeQueryParamFromUrl( $requestUrl, 'owa_jsonpCallback' );
		$requestUrl = owa_lib::removeQueryParamFromUrl( $requestUrl, '_' );
		
	    
	    if ( strpos( $requestUrl, 'owa_signature' ) ) {
		    
		    $requestUrl = owa_lib::removeQueryParamFromUrl( $requestUrl, 'owa_signature' );
	    }
	    
	    
	    //owa_coreAPI::debug('url w/ no sig: ' . $requestUrl );
	    
	    //$signature = base64_decode( $signature );
	    
	    $computed_signature = $this->generateSignature( $requestUrl, $apiKey );
	    owa_coreAPI::debug( 'computed signature: ' . $computed_signature );
	    owa_coreAPI::debug( 'request url: ' . $requestUrl );
	    
	    if ( $signature === $computed_signature ) {
		    
		    owa_coreAPI::debug('API request signature is valid.' );
		    return true;
		    
	    } else {
		    
		    owa_coreAPI::debug('API request signature failed validation.' );
		    return false;
	    }
    }
}

?>