<?php
namespace OWA\Module\Base\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Asks a signed-in user whether to log out: the answer to a logout request
 * without a valid nonce (Controller\Logout).
 */
class LogoutConfirm extends \OWA\Core\View {

    function render($data) {

        $this->setTitle( \OWA\Core\CoreAPI::t( 'Log out' ) );
        $this->t->set_template('wrapper_public.php');
        $this->body->set_template('logout_confirm.php');

        // The referring page, when it is one of this install's (Controller::getReferringPage()).
        $this->body->set( 'back_url', (string) ( $data['back_url'] ?? '' ) );
    }
}

?>
