<?php
namespace OWA\Module\Base\Controller;

/**
 * Change Password Controller
 * 
 * handles from input from the Change password screen
 */

class UsersChangePassword extends \OWA\Core\Controller {

    public function validate()
    {
        $this->addValidation('password_match', [$this->getParam('password'), $this->getParam('password2')], 'stringMatch', ['errorMsg' => \OWA\Core\CoreAPI::t( 'The passwords do not match.' )]);
        $this->addValidation('password_required', $this->getParam('password'), 'required');

        $passwordLengthConf = [
            'operator'  => '>=',
            'length'    => \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH,
            'errorMsg'  => sprintf( \OWA\Core\CoreAPI::t( 'The password must be at least %d characters.' ), \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH ),
        ];

        // 'stringLength' (not 'required') is the validator that actually reads
        // the operator/length config above; typed as 'required' the length rule
        // silently degraded to a bare non-empty check and short passwords passed.
        $this->addValidation('password_length', $this->getParam('password'), 'stringLength', $passwordLengthConf);
    }

    public function action() {

        $auth = \OWA\Core\Auth::get_instance();
        $status = $auth->authenticateUserTempPasskey($this->getParam('k'));

        if ($status === true) {
            /*
             * Set here, not by a handler of base.set_password: the event carried
             * the new password, in plain text, to every listener. It now says
             * only that the password changed, for the confirmation mail.
             */
            $user_id = $auth->u->get('user_id');
            $u = ( new \OWA\Module\Base\Classes\UserManager() )->updateUserPassword( [
                'temp_passkey' => $this->getParam('k'),
                'password'     => $this->getParam('password'),
                'user_id'      => $user_id,
            ] );
            $auth->deleteCredentials();
            $this->setRedirectAction('base.loginForm');

            if ( ! $u ) {

                $this->set('error_code', 2013);

                return;
            }

            \OWA\Core\CoreAPI::getEventDispatch()->log(
                [ 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'user_id' => $user_id ], 'base.set_password' );
            $this->setStatusCode( 3006 );
        } else {
            $this->setRedirectAction('base.loginForm');
            // Expired (2012), or not a key any account holds (2011).
            $this->set('error_code', \OWA\Module\Base\Controller\UsersPasswordEntry::keyProblem( $this->getParam('k') ) ?: 2011 );
        }
    }

    public function errorAction() {

        $this->setView( 'base.usersPasswordEntry', UsersPasswordEntry::page( $this ) );

        /*
         * 'key', not 'k'.
         *
         * The view reads 'key' -- UsersPasswordEntry::action() sets it under
         * that name, and View::get() answers FALSE for a name nobody set. Under
         * any other name the hidden field renders as value="", and the passkey
         * is gone from the form the moment any validation fails, so a reset
         * whose confirmation was mistyped once could not be completed.
         */
        $this->set('key', $this->getParam('k'));
    }
}
