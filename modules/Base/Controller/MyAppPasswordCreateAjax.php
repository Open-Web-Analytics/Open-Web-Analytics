<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Makes an application password for the signed-in user and answers with its
 * token. Called by the Add dialog on base.myAppPasswords.
 *
 * An Ajax endpoint (Core\Controller\Ajax): a signed-in browser session only,
 * so an application password or an API key cannot mint a successor that
 * outlives revoking it. Scripts use `cmd=app-password-create`.
 *
 * The user is the session's, never a parameter.
 */
class MyAppPasswordCreateAjax extends \OWA\Core\Controller\Ajax {

    function __construct( $params ) {

        // Every signed-in role makes its own; see base.myProfile.
        $this->setRequiredCapability( 'view_site_list' );

        parent::__construct( $params );
    }

    function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'stopOnError' => true, 'errorMsg' => $this->getMsg( 3215 )['message'] ) );
    }

    function action() {

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        $made = \OWA\Module\Base\Classes\AppPasswords::create(
            $user_id, trim( (string) $this->getParam( 'name' ) ), (string) $this->getParam( 'scope' ) );

        if ( ! $made ) {

            return $this->answer( 422, $this->getMsg( 3216 )['message'] );
        }

        $made['scope_label']     = \OWA\Module\Base\Classes\AppPasswords::scopes()[ $made['scope'] ];
        $made['created_display'] = date( 'Y-m-d H:i', (int) $made['created_at'] );

        return $this->answer( 201, null, $made );
    }
}
