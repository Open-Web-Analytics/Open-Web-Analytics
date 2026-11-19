<?php
namespace OWA\Module\Base\View;

/**
 * Login Form View
 */

class LoginForm extends \OWA\Core\View {

    public function construct($data) {

        $this->setTitle( \OWA\Core\CoreAPI::t( 'Login' ) );
        $this->t->set_template('wrapper_public.php');
        $this->body->set_template('login_form.php');
        $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Please login using the from below' ));
        $this->body->set('user_id', $this->get('user_id'));
        $this->body->set('go', \OWA\Module\Base\Classes\Sanitize::cleanUrl( $this->get('go') ) );
        $this->body->set('login_nonce', \OWA\Module\Base\Controller\Login::formNonce());
        $this->setJs("owa", "base/dist/owa.reporting-combined-min.js");
    }
}
