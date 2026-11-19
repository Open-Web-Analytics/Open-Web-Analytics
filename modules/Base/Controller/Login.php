<?php
namespace OWA\Module\Base\Controller;

class Login extends \OWA\Core\Controller {

    public function __construct( $params ) {

        parent::__construct( $params );

        // The one screen that signs someone in. The session is accepted too,
        // after the form, as authenticateUser() has always ordered them.
        $this->setAuthMethods( [ 'login_form', 'cookies' ] );
    }

    public function validate()
    {
        $this->addValidation('user_id', $this->getParam('user_id'), 'userName', ['stopOnError' => true]);
    }

    public function action() {

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
}
