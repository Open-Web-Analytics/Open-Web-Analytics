<?php
namespace OWA\Module\Base\Controller;

class Login extends \OWA\Core\Controller {

    /**
     * The cookie, namespaced and HttpOnly, holding the random value the login
     * form's nonce is made of (formNonce()).
     */
    const FORM_COOKIE = 'lf';

    /**
     * Whether the form's nonce verified (action()); until then, only a
     * session signs anyone in.
     *
     * @var bool
     */
    private $form_verified = false;

    public function __construct( $params ) {

        parent::__construct( $params );

        // The one screen that signs someone in. The session is accepted too,
        // after the form, as authenticateUser() has always ordered them.
        $this->setAuthMethods( [ 'login_form', 'cookies' ] );
    }

    /**
     * The form signs in only once its nonce has verified. doAction()
     * authenticates before action() runs whenever session cookies are
     * present, and with the form accepted then, a link from anywhere carrying
     * a user_id and password signed a signed-in browser into that account
     * before the nonce was checked. Until action() has checked it, only the
     * session is accepted.
     *
     * @return list<string>|null
     */
    public function acceptedAuthMethods() {

        return $this->form_verified ? parent::acceptedAuthMethods() : [ 'cookies' ];
    }

    public function validate()
    {
        $this->addValidation('user_id', $this->getParam('user_id'), 'userName', ['stopOnError' => true]);
    }

    public function action() {

        // A form from another page, or one older than the nonce windows: the
        // form again, with a nonce that verifies. Posted only: a password is
        // never accepted from a URL, where logs and history keep it.
        $posted = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'POST' ) ) === 'POST';

        if ( ! $posted || ! self::formNonceIsValid( $this->getParam( 'nonce' ) ) ) {

            $this->setView( 'base.loginForm' );
            $this->set( 'go', \OWA\Module\Base\Classes\Sanitize::cleanUrl( $this->getParam( 'go' ) ) );
            $this->set( 'error_code', 2006 );
            $this->set( 'user_id', $this->getParam( 'user_id' ) );

            return;
        }

        $this->form_verified = true;

        $auth = \OWA\Core\Auth::get_instance();
        $status = $auth->authenticateUser( $this->acceptedAuthMethods() );
        $go = \OWA\Module\Base\Classes\Sanitize::cleanUrl( $this->getParam('go') );
        // if authentication is successfull
        if ($status['auth_status'] == true) {

            if (!empty($go)) {
                // redirect to url if present
                $url = urldecode(htmlspecialchars_decode( $go ) );
                $this->e->debug("redirecting browser to...:". $url);
                \OWA\Core\Lib::redirectBrowser($url);

            } else {
                //else redirect to home page

                // Cleared: doAction() sets params and site_id, and a redirect
                // carries none of these.
                $this->set('auth_status', '');
                $this->set('params', '');
                $this->set('site_id', '');
                $this->setRedirectAction($this->config['start_page']);
            }

        } else {
            // return login form with error msg
            $this->setView('base.loginForm');
            $this->set('go', $go);
            $this->set('error_code', 2002);
            $this->set('user_id', $this->getParam('user_id'));

        }
    }

    public function errorAction() {

        // return login form with error msg
        $this->setView('base.loginForm');
        // $go is a local of action(), so it is re-derived from the request,
        // sanitised the same way action() does, to send a failed login back where
        // it was headed.
        $this->set('go', \OWA\Module\Base\Classes\Sanitize::cleanUrl( $this->getParam('go') ));
        $this->set('user_id', $this->getParam('user_id'));
    }

    /**
     * The login form's nonce: of base.login and a random value kept in this
     * browser's FORM_COOKIE, which is set when it has none. A nonce of the
     * action alone (CoreAPI::createNonce()) is the same for everyone not
     * signed in, so a page elsewhere could fetch one and post it.
     *
     * @param  int|float|null $tick the nonce window; the current one when null
     * @return string
     */
    public static function formNonce( $tick = null ) {

        return self::nonceOf( self::browserValue( true ), $tick ?? \OWA\Core\CoreAPI::getNonceTimeInterval() );
    }

    /**
     * Whether a posted nonce is this browser's login form nonce, of the current
     * window or the one before it, as Controller::verifyNonce() accepts.
     *
     * @param  mixed $nonce
     * @return bool
     */
    public static function formNonceIsValid( $nonce ) {

        $value = self::browserValue( false );

        if ( $value === '' || ! is_string( $nonce ) ) {

            return false;
        }

        $tick = \OWA\Core\CoreAPI::getNonceTimeInterval();

        foreach ( [ $tick, $tick - 1 ] as $t ) {

            if ( hash_equals( self::nonceOf( $value, $t ), $nonce ) ) {

                return true;
            }
        }

        return false;
    }

    /**
     * @param  string    $value FORM_COOKIE's
     * @param  int|float $tick
     * @return string
     */
    private static function nonceOf( $value, $tick ) {

        return substr( \OWA\Core\CoreAPI::saltedHash( $tick . 'base.login' . $value . 'owa_login_nonce', 'nonce' ), -12, 10 );
    }

    /**
     * FORM_COOKIE's value: 32 hex characters, or '' when the browser sent none.
     * With $create, one is made and set when there is none.
     *
     * @param  bool $create
     * @return string
     */
    private static function browserValue( $create ) {

        $name  = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . self::FORM_COOKIE;
        $value = $_COOKIE[ $name ] ?? '';

        if ( is_string( $value ) && preg_match( '/^[0-9a-f]{32}$/', $value ) ) {

            return $value;
        }

        if ( ! $create ) {

            return '';
        }

        $value = bin2hex( random_bytes( 16 ) );

        if ( ! headers_sent() ) {

            \OWA\Core\CoreAPI::createCookie( self::FORM_COOKIE, $value, 0, '/', '', (bool) \OWA\Core\Lib::isHttps(), true );
        }

        $_COOKIE[ $name ] = $value;

        return $value;
    }
}
