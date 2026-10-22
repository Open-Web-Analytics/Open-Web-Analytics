<?php
namespace OWA\Module\Base\View;

/**
 * The new application password, token included. The only response that
 * carries one, so nothing may keep it.
 */
class AppPasswordCreateRest extends \OWA\Core\View\RestApi {

    function render() {

        if ( ! headers_sent() ) {

            header( 'Cache-Control: no-store' );
        }

        $this->setResponseData( $this->get( 'app_password' ) ?: null );
    }
}
