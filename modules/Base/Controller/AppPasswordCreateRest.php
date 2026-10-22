<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * POST v1/apppasswords -- makes an application password for the signed-in
 * user and answers with its token.
 *
 * Called by the Add dialog on base.myAppPasswords, so the token is shown on
 * the page that asked for it and a reload repeats nothing.
 *
 * A browser session only. A request authenticated by an application password
 * or an API key is refused, so a leaked credential cannot mint itself a
 * successor that survives revoking it. Scripts use `cmd=app-password-create`.
 *
 * The user is the session's, never a parameter.
 */
class AppPasswordCreateRest extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        // After parent::__construct(), as NotificationDismissRest explains.
        $this->setRequiredCapability( 'view_site_list' );
        $this->setNonceRequired();
    }

    function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'stopOnError' => true, 'errorMsg' => $this->getMsg( 3215 ) ) );
    }

    function action() {

        if ( \OWA\Core\Auth::get_instance()->getAuthMethod() !== 'cookies' ) {

            http_response_code( 403 );
            $this->set( 'error_msg', $this->getMsg( 3221 ) );
            $this->setView( 'base.appPasswordCreateRest' );

            return;
        }

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $made = \OWA\Module\Base\Classes\AppPasswords::create(
            $user_id, trim( (string) $this->getParam( 'name' ) ), (string) $this->getParam( 'scope' ) );

        if ( ! $made ) {

            http_response_code( 422 );
            $this->set( 'error_msg', $this->getMsg( 3216 ) );
            $this->setView( 'base.appPasswordCreateRest' );

            return;
        }

        $scopes = \OWA\Module\Base\Classes\AppPasswords::scopes();

        $made['scope_label']     = $scopes[ $made['scope'] ];
        $made['created_display'] = date( 'Y-m-d H:i', (int) $made['created_at'] );

        http_response_code( 201 );
        $this->set( 'app_password', $made );
        $this->setView( 'base.appPasswordCreateRest' );
    }

    function errorAction() {

        http_response_code( 422 );
        $this->setView( 'base.appPasswordCreateRest' );
    }
}
