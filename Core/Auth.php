<?php
namespace OWA\Core;

/**
 * User Authentication Object
 */
class Auth extends \OWA\Core\Base {

    /**
     * User object
     *
     * @var mixed
     */
    public $u;

    /**
     * Array of permission roles that users can have
     *
     * @var array<string>|null nothing sets it
     */
    public $roles;

    /** @var string|null nothing sets it */
    public $status_msg;

    /**
     * Login credentials: user_id, and password (the `p` cookie) or
     * new_password (a typed password), each as given
     *
     * @var array<string, mixed>
     */
    public $credentials = [];

    /**
     * Status of Authentication
     *
     * @var boolean
     */
    public $auth_status = false;

    /** @var bool */
    public $_is_user = false;

    /** @var array<mixed> */
    public $params;

    /** @var string|null see setAuthMethod() */
    public $auth_method;

    /** @var \OWA\Module\Base\Classes\EventDispatch */
    public $eq;

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
     * Methods tried only where a controller names them (setAuthMethods()):
     * the login form's user_id and password on the login action, and an
     * overlay token on the routes an overlay reads.
     */
    const NAMED_ONLY_METHODS = [ 'login_form', 'overlay_token' ];

    /**
     * Auth class Singleton
     *
     * @param  string $plugin unused
     * @return \OWA\Core\Auth
     */
    public static function get_instance($plugin = '') {

        if (!self::$instance) {

            self::$instance = new \OWA\Core\Auth();

        }

        return self::$instance;
    }

    /** @var \OWA\Core\Auth|null get_instance() */
    private static $instance = null;

    /**
     * Drop the instance get_instance() answers. CoreAPI::forgetSingletons()
     * calls this.
     *
     * @return void
     */
    public static function forgetInstance() {

        self::$instance = null;
    }


    /**
     * Class Constructor
     *
     * @return \OWA\Core\Auth
     */
    public function __construct() {

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
     * the order below, except NAMED_ONLY_METHODS. See
     * Controller::setAuthMethods().
     *
     * @param string[]|null $methods login_form, overlay_token, app_password, cookies
     * @return array{auth_status: mixed} as the auth_status filter left it: a bool
     *         unless a module's filter answers otherwise
     */
    public function authenticateUser( $methods = null ) {

        $accepts = function ( $method ) use ( $methods ) {

            return $methods === null
                ? ! in_array( $method, self::NAMED_ONLY_METHODS, true )
                : in_array( $method, (array) $methods, true );
        };
        
        $this->legacy_api_key_refused = false;

        // check existing auth status first in case someone else took care of this already.
        if (\OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated()) {
            \OWA\Core\CoreAPI::debug('User is already authenticated.');
            $ret = true;

        } elseif ( self::carriesLegacyApiKey() ) {
            // The legacy API key and request signature are not accepted. A
            // request that carries one is refused, never judged on another
            // credential it also carries.
            $this->setAuthMethod( 'api_key' );
            $this->legacy_api_key_refused = true;
            $ret = false;
            \OWA\Core\CoreAPI::debug( 'Legacy API key or request signature refused.' );

        } elseif ($accepts('login_form') && \OWA\Core\CoreAPI::getRequestParam('user_id') && \OWA\Core\CoreAPI::getRequestParam('password')) {
            // auth user by login form input
            $this->setAuthMethod( 'login_form');
            $ret = $this->authByInput(\OWA\Core\CoreAPI::getRequestParam('user_id'), \OWA\Core\CoreAPI::getRequestParam('password'));
             \OWA\Core\CoreAPI::debug('User authenticated via form input.');
    
        } elseif ( $accepts('overlay_token') && \OWA\Core\CoreAPI::getRequestParam('overlayToken') ) {
            // Only where a controller names it (setAuthMethods()): the routes an
            // overlay reads, not every route that accepts any credential.
            // auth by a scoped, short-lived overlay token.
            //
            // Checked BEFORE the other credentials so that a request carrying two
            // is held to the narrower one, and scope is enforced here
            // rather than later: a request outside the token's scope does not
            // authenticate at all, so there is no window in which a caller is
            // authenticated but not yet constrained.
            $this->setAuthMethod( 'overlay_token');
            $ret = $this->authByOverlayToken( \OWA\Core\CoreAPI::getRequestParam('overlayToken') );
            \OWA\Core\CoreAPI::debug('User authenticated via overlay token.');
        } elseif ( $accepts('app_password') && ( $appPassword = $this->appPasswordCredential() ) ) {
            // An application password in the Authorization header. Before the
            // cookies, and a failure here does not fall through to them: the
            // request named a credential and it did not hold.
            $this->setAuthMethod( 'app_password' );
            $ret = $this->authByAppPassword( $appPassword['token'], $appPassword['user_id'] );
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

        return ['auth_status' => $ret];

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
        $parts = $rest !== '' ? explode( '/', $rest ) : [];

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
     * little as possible to anyone who reads it there: one user, one endpoint,
     * one resource, and it expires in minutes.
     *
     * **Scope is checked here, before the user is loaded**, so a request outside
     * the token's scope never authenticates. Deferring the check to an
     * authorisation layer would leave a window in which the caller is
     * authenticated but unconstrained, and the constraint is the entire point.
     *
     * @param    string    $token
     * @return    boolean
     */
    public function authByOverlayToken( $token ) {

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
     * @return array{token: string, user_id: string|null}|null user_id null for Bearer
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

        return [ 'token' => $token, 'user_id' => $user_id ];
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
    public function authByAppPassword( $token, $user_id = null ) {

        if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'app_passwords_enabled' ) ) {

            \OWA\Core\CoreAPI::debug( 'Application passwords are turned off.' );

            return false;
        }

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'app_passwords_require_https' ) && ! \OWA\Core\Lib::isHttps() ) {

            \OWA\Core\CoreAPI::debug( 'Application password refused: the request is not HTTPS.' );

            return false;
        }

        // Failures are counted per client address, with the login form's.
        $keys = \OWA\Module\Base\Classes\LoginThrottle::keys( '', self::lastPublicHop() );

        if ( \OWA\Module\Base\Classes\LoginThrottle::isLocked( $keys ) ) {

            \OWA\Core\CoreAPI::debug( 'Application password refused: too many failed attempts from this address.' );

            return false;
        }

        $password = \OWA\Module\Base\Classes\AppPasswords::verify( $token );

        if ( ! $password ) {

            \OWA\Core\CoreAPI::debug( 'Application password not recognised.' );
            \OWA\Module\Base\Classes\LoginThrottle::recordFailure( $keys );

            return false;
        }

        if ( $user_id !== null && (string) $user_id !== (string) $password['user_id'] ) {

            \OWA\Core\CoreAPI::debug( 'Application password belongs to a different user.' );
            \OWA\Module\Base\Classes\LoginThrottle::recordFailure( $keys );

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
     * The client address: the first address, from the right of
     * X-Forwarded-For and REMOTE_ADDR, that no trusted proxy added
     * (Core\ClientAddress), as a tracked event's is chosen.
     *
     * @return string
     */
    private static function lastPublicHop() {

        return \OWA\Core\ClientAddress::fromRequest();
    }

    /**
     * Sign in by the `u` and `p` cookies (see sessionCredential()).
     *
     * A sign-in with less than SESSION_REFRESH_BELOW left is given a fresh `p`,
     * so a session ends between SESSION_REFRESH_BELOW and SESSION_LIFETIME
     * after its last request, not at a fixed time after its sign-in.
     *
     * @param  mixed $user_id    the `u` cookie, as carried
     * @param  mixed $credential the `p` cookie, as carried
     * @return bool|null null when a user was already signed in upstream
     */
    public function authByCookies($user_id, $credential) {

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

        return null;
    }

    /**
     * @param  mixed $user_id  as the request carried it
     * @param  mixed $password as the request carried it: only a string can match
     * @return bool
     */
    public function authByInput($user_id, $password) {

        // set credentials
        $this->credentials['user_id'] = \OWA\Module\Base\Classes\Sanitize::cleanUserId( $user_id );
        unset( $this->credentials['password'] );
        // the typed password, checked against the stored hash
        $this->credentials['new_password'] = $password;

        // Failed sign-ins are counted per user name and per client address,
        // this attempt among them before its password is checked.
        $address = self::lastPublicHop();
        $keys    = \OWA\Module\Base\Classes\LoginThrottle::keys( $this->credentials['user_id'], $address );

        if ( ! \OWA\Module\Base\Classes\LoginThrottle::attempt( $keys ) ) {

            // Answered as a wrong password is, in the time one takes.
            $this->passwordMatches( $password, self::NO_USER_HASH );
            $this->_is_user = false;
            \OWA\Core\CoreAPI::debug( 'Sign-in refused: too many failed attempts for this user name or address.' );

            return false;
        }

        $ret = $this->isUser();

        if ($ret === true) {
            $this->saveCredentials();
            \OWA\Module\Base\Classes\LoginThrottle::succeeded(
                \OWA\Module\Base\Classes\LoginThrottle::nameKey( $this->credentials['user_id'] ),
                \OWA\Module\Base\Classes\LoginThrottle::addressKey( $address ) );
        } else {
            \OWA\Module\Base\Classes\LoginThrottle::failed( $keys );
        }

        return $ret;
    }

    /**
     * Looks up user by temporary Passkey Column in db
     *
     * @param mixed $key
     * @return bool
     */
    public function authenticateUserTempPasskey( $key ) {

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
     *
     * @return void
     */
    public function saveCredentials() {

        \OWA\Core\CoreAPI::debug('saving user credentials to cookies');

        $this->writeCookie( 'u', $this->u->get('user_id'), time()+3600*24*365*10 );
        $this->writeSessionCredential();
    }

    /**
     * Writes `p` for the signed-in user, expiring SESSION_LIFETIME from now.
     *
     * @return void
     */
    protected function writeSessionCredential() {

        $expires = time() + self::SESSION_LIFETIME;

        $this->writeCookie( 'p', self::sessionCredential(
            $this->u->get('user_id'), $this->u->get('session_epoch'), $this->u->get('password'), $expires ), $expires );
    }

    /**
     * A session cookie: HttpOnly, Secure on an HTTPS request, and sent only
     * to this install (CoreAPI::createSignInCookie()).
     *
     * @param  string $name
     * @param  string $value
     * @param  int    $expires unix timestamp
     * @return void
     */
    protected function writeCookie( $name, $value, $expires ) {

        $this->emitCookie( $name, $value, $expires, (bool) \OWA\Core\Lib::isHttps(), true );
    }

    /**
     * @param  string $name
     * @param  string $value
     * @param  int    $expires unix timestamp
     * @param  bool   $secure
     * @param  bool   $httponly
     * @return void
     */
    protected function emitCookie( $name, $value, $expires, $secure, $httponly ) {

        \OWA\Core\CoreAPI::createSignInCookie( $name, $value, $expires, $secure, $httponly );
    }

    /**
     * Removes this browser's `p`. The credential itself stays valid until it
     * expires; User::endSessions() ends it everywhere.
     *
     * @return null
     */
    public function deleteCredentials() {

        // The state store's copy, and the cookie, at both of its scopes.
        \OWA\Core\CoreAPI::clearState('p');
        $this->emitCookie( 'p', '', time() - 86400, (bool) \OWA\Core\Lib::isHttps(), true );

        return null;
    }

    /**
     * @param  string $password
     * @return string
     */
    public function encryptOldPassword($password) {

        return \OWA\Core\Lib::encryptOldPassword($password);

    }

    /**
     * Loads $u by credentials['user_id'].
     *
     * @return void
     */
    public function getUser() {

        // fetch user object from the db
        \OWA\Core\CoreAPI::debug('auth get user: '. $this->credentials['user_id'] );
        
        $this->u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $this->u->getByColumn('user_id', $this->credentials['user_id']);
    }

    /**
     * What isUser() checks a typed password against when no user has the
     * name: a hash of a random string, at PASSWORD_DEFAULT's cost on PHP 8.2
     * and 8.3, that nothing matches.
     */
    const NO_USER_HASH = '$2y$10$pn9y00Tf2hstPR75joZSc.UtQ8AUsBk9pJhtMQjTE8Uv9lhLQpohS';

    /**
     * The values the old User::generateRandomPassword() gave an account created
     * without a password (a hash prefix). A login with one is refused whatever
     * the stored hash says; the account's owner sets a password through the
     * emailed link.
     */
    const LEGACY_GENERATED_PASSWORD = '/^(\\$2[aby]?\\$\\d\\d|\\$argon)$/';

    /**
     * @param  mixed $password any type: only a string can be one
     * @return bool
     */
    public static function isLegacyGeneratedPassword( $password ) {

        return is_string( $password ) && preg_match( self::LEGACY_GENERATED_PASSWORD, $password ) === 1;
    }

    /**
     * Whether the credentials (a typed password, or the `p` cookie) sign in
     * the user they name. On success the user is the current user.
     *
     * @return boolean
     */
    public function isUser() {

        // get current user
        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        // fetches user object from DB
        $this->getUser();

        if ( $this->credentials['user_id'] !== $this->u->get('user_id') ) {

            // A typed password is checked against a hash all the same, so an
            // unknown user name answers in the time a wrong password does.
            if ( isset( $this->credentials['new_password'] ) ) {

                $this->passwordMatches( $this->credentials['new_password'], self::NO_USER_HASH );
            }

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
     * The request parameters and headers of the legacy API key sign-in, which
     * application passwords replace.
     */
    const LEGACY_API_KEY_PARAMS  = [ 'apiKey', 'signature' ];
    const LEGACY_API_KEY_HEADERS = [ 'HTTP_X_API_KEY', 'HTTP_X_SIGNATURE' ];

    /**
     * Whether $typed is the password of the account $user_id names, as
     * sign-in checks it (passwordMatches()). For a step a session alone may
     * not take: changing the password, making an application password.
     *
     * @param  string $user_id
     * @param  mixed  $typed
     * @return bool
     */
    public function isAccountPassword( $user_id, $typed ) {

        $u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $u->load( (string) $user_id, 'user_id' );

        return (string) $u->get( 'user_id' ) !== '' && $this->passwordMatches( $typed, (string) $u->get( 'password' ) );
    }

    /**
     * Whether a typed password is the account's: the stored hash, or, for an
     * account whose password 1.x stored unsalted, the whole of that hash.
     *
     * @param  mixed  $typed  as the request carried it: only a string can match
     * @param  string $stored
     * @return bool
     */
    protected function passwordMatches( $typed, $stored ) {

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
    public static function sessionCredential( $user_id, $session_epoch, $password_hash, $expires ) {

        $expires = (int) $expires;

        return $expires . '.' . self::sessionHmac( $user_id, $session_epoch, $password_hash, $expires );
    }

    /**
     * @param  string $user_id
     * @param  int    $session_epoch
     * @param  string $password_hash
     * @param  int    $expires unix timestamp
     * @return string
     */
    private static function sessionHmac( $user_id, $session_epoch, $password_hash, $expires ) {

        // Only user_id is free text, and it comes first, so the fields cannot run together.
        $data = implode( '|', [
            (string) $user_id,
            (int) $session_epoch,
            (int) $expires,
            substr( (string) $password_hash, 8, 4 ),
        ] );

        return hash_hmac( 'sha256', $data, \OWA\Core\CoreAPI::getSalt( 'auth' ) );
    }

    /**
     * A `p` value's expiry, or null when the value is not one.
     *
     * @param mixed $credential
     * @return int|null
     */
    public static function sessionCredentialExpiry( $credential ) {

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
    public static function isValidSessionCredential( $credential, $user_id, $session_epoch, $password_hash, $now = null ) {

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
    public static function sessionCredentialNeedsRefresh( $credential, $now = null ) {

        $expires = self::sessionCredentialExpiry( $credential );

        return $expires !== null && $expires - ( $now ?? time() ) < self::SESSION_REFRESH_BELOW;
    }


    /**
     * Whether the last authenticateUser() refused a legacy API key or signature.
     *
     * @var bool
     */
    private $legacy_api_key_refused = false;

    /**
     * Whether the request carries a legacy API key or request signature.
     *
     * @return bool
     */
    public static function carriesLegacyApiKey() {

        foreach ( self::LEGACY_API_KEY_PARAMS as $param ) {

            if ( (string) \OWA\Core\CoreAPI::getRequestParam( $param ) !== '' ) {

                return true;
            }
        }

        foreach ( self::LEGACY_API_KEY_HEADERS as $header ) {

            if ( (string) \OWA\Core\CoreAPI::getServerParam( $header ) !== '' ) {

                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function refusedLegacyApiKey() {

        return $this->legacy_api_key_refused;
    }

    /**
     * Sets the method by which the user was authenticated.
     *
     * @param  string $method api_key, login_form, overlay_token, app_password or cookies
     * @return void
     */
    private function setAuthMethod( $method ) {
        
        $this->auth_method = $method;
    }
    
    /**
     * @return string|null null before authenticateUser() chose one
     */
    public function getAuthMethod() {
        
        return $this->auth_method;
    }
}
