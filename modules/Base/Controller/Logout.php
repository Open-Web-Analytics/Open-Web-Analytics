<?php
namespace OWA\Module\Base\Controller;

/**
 * Logout Controller
 */

class Logout extends \OWA\Core\Controller {

    /**
     * Signs the user out of every browser session, on every device, and
     * clears this browser's cookie.
     *
     * Only with a nonce for base.logout, which every Log out link OWA draws
     * carries. Without one -- a link from anywhere else -- the user is asked
     * first, by a form that carries one. Nobody signed in goes to the login
     * form.
     */
    public function action() {

        $auth = \OWA\Core\Auth::get_instance();
        $cu   = \OWA\Core\CoreAPI::getCurrentUser();

        if ( ! $cu->isAuthenticated() ) {

            $auth->deleteCredentials();
            $this->setRedirectAction('base.loginForm');

            return;
        }

        if ( ! $this->verifyNonce( $this->getParam('nonce'), 'base.logout' ) ) {

            $this->setView( 'base.logoutConfirm', [
                'template' => 'logout_confirm.php',
                'wrapper'  => 'wrapper_public.php',
                'title'    => \OWA\Core\CoreAPI::t( 'Log out' ),
                'vars'     => [ 'back_url' ],
            ] );
            // The referring page, when it is one of this install's.
            $this->set('back_url', (string) $this->getReferringPage());

            return;
        }

        if ( $auth->getAuthMethod() === 'cookies' ) {

            $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
            $user->load( $cu->getUserData( 'user_id' ), 'user_id' );

            if ( $user->wasPersisted() ) {

                $user->endSessions();
                $user->update();
            }
        }

        $auth->deleteCredentials();
        $this->setRedirectAction('base.loginForm');
    }
}
