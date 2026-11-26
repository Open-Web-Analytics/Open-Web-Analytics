<?php
namespace OWA\Module\Base\Controller;

/**
 * Makes an application password for the signed-in user and answers with its
 * token. Called by the Add dialog on base.myAppPasswords.
 *
 * An Ajax endpoint (Core\Controller\Ajax): a signed-in browser session only,
 * so an application password cannot mint a successor that
 * outlives revoking it. Scripts use `cmd=app-password-create`.
 *
 * The user is the session's, never a parameter, and must type their
 * password again: a session alone, borrowed or left open, does not make a
 * credential that outlives it.
 */
class MyAppPasswordCreateAjax extends \OWA\Core\Controller\Ajax {

    public function __construct( $params ) {

        // Every signed-in role makes its own; see base.myProfile.
        $this->setRequiredCapability( 'view_site_list' );

        parent::__construct( $params );
    }

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            [ 'stopOnError' => true, 'errorMsg' => $this->getMsg( 3215 )['message'] ] );
    }

    public function action() {

        $user_id = (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' );

        if ( ! \OWA\Core\Auth::get_instance()->isAccountPassword( $user_id, $this->getParam( 'password' ) ) ) {

            return $this->answer( 403, \OWA\Core\CoreAPI::t( 'That is not your password.' ) );
        }

        $made = \OWA\Module\Base\Classes\AppPasswords::create(
            $user_id, trim( (string) $this->getParam( 'name' ) ), (string) $this->getParam( 'scope' ) );

        if ( ! $made ) {

            return $this->answer( 422, $this->getMsg( 3216 )['message'] );
        }

        \OWA\Module\Base\Classes\AppPasswords::announce( $made, $user_id, \OWA\Core\ClientAddress::fromRequest() );

        $made['scope_label']     = \OWA\Module\Base\Classes\AppPasswords::scopes()[ $made['scope'] ];
        $made['created_display'] = date( 'Y-m-d H:i', (int) $made['created_at'] );

        return $this->answer( 201, null, $made );
    }
}
