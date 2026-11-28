<?php
namespace OWA\Module\Base\Controller;

/**
 * Add User Controller
 */

class UsersAdd extends \OWA\Core\AdminController {

    public function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
    }

    public function validate() {

        // As action() stores them.
        $email   = trim( (string) $this->getParam( 'email_address' ) );
        $user_id = trim( (string) $this->getParam( 'user_id' ) );

        $this->addValidation('email_address', $email, 'required', ['stopOnError'  => true]);
        // As base.usersEdit checks it.
        $this->addValidation('email_address', $email, 'emailAddress',
            ['stopOnError' => true, 'errorMsg' => \OWA\Core\CoreAPI::t( 'Enter a valid email address.' )]);
        $this->addValidation('user_id', $user_id, 'required', ['stopOnError'  => true]);
        $this->addValidation('user_id', $user_id, 'userName', ['stopOnError'  => true]);
        $this->addValidation('role', $this->getParam('role'), 'required', ['stopOnError'    => true]);
        
        $roles = \OWA\Core\CoreAPI::getAssignableRoles();
        $this->addValidation('role', $this->getParam('role'), 'inArray', [
            'possible_values' => $roles,
            'errorMsg'        => sprintf( \OWA\Core\CoreAPI::t( 'A user\'s role is one of: %s.' ), implode( ', ', $roles ) ),
            'stopOnError'     => true,
        ] );
        
        // Check for user with the same email address
        // this is needed or else the change password feature will not know which account
        // to chane the password for.
        $userEmailAddressEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'email_address',
            'errorMsg'  => $this->getMsg(3009)
        ];

        $this->addValidation('email_address', $email, 'entityDoesNotExist', $userEmailAddressEntityConf);

        // Check user name.
        $userEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'user_id',
            'errorMsg'  => $this->getMsg(3001)
        ];

        $this->addValidation('user_id', $user_id, 'entityDoesNotExist', $userEntityConf);
    }

    public function action() {

        $user_params = [ 'user_id'       => trim( (string) $this->getParam( 'user_id' ) ),
                         'real_name'     => (string) $this->getParam( 'real_name' ),
                         'role'          => $this->getParam( 'role' ),
                         'email_address' => trim( (string) $this->getParam( 'email_address' ) ) ];

        $u = $this->createUser( $user_params );

        if ( ! $u ) {

            $this->set( 'validation_errors', [ 'user_id' => \OWA\Core\CoreAPI::t( 'The user was not added. The error log says why.' ) ] );
            $this->errorAction();

            // Non-empty, so finishActionCall() does not go on to success().
            return $this->data;
        }

        $u_properties = [
            'user_id'       => $u->get( 'user_id' ),
            'email_address' => $u->get( 'email_address' ),
            'real_name'     => $u->get( 'real_name' ),
            'role'          => $u->get( 'role' ),
        ];

        // The invitation: its handler mails the new user the link that sets a
        // password (UserHandlers), and says on the event whether it was sent.
        $ed    = \OWA\Core\CoreAPI::getEventDispatch();
        $event = $ed->makeEvent( 'base.new_user_account' );
        $event->setProperties( $u_properties + [ 'temp_passkey' => $u->get( 'temp_passkey' ) ] );
        $ed->notify( $event );

        $sent = $event->get( 'mail_sent' ) === true;

        // For the CLI and REST controllers that extend this.
        $this->set( 'user', $u_properties + [ 'invitation_sent' => $sent ] );
        $this->set( 'invitation_sent', $sent );
    }

    /**
     * @param  array<string, mixed> $user_params
     * @return \OWA\Module\Base\Entity\User|false
     */
    protected function createUser( array $user_params ) {

        return ( new \OWA\Module\Base\Classes\UserManager() )->createNewUser( $user_params );
    }

    public function success() {

        unset($this->data['user']);
        $this->setRedirectAction('base.users');
        // 3013: added, and the administrator must pass the invitation on another way.
        $this->setStatusCode( $this->get( 'invitation_sent' ) ? 3000 : 3013 );
    }

    public function errorAction() {
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview('base.usersProfile');
        $this->set('error_code', 3009);
        //assign original form data so the user does not have to re-enter the data
        $this->set('profile', $this->params);
    }
}
