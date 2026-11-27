<?php
namespace OWA\Module\Base\Controller;

/**
 * Edit User Controller
 */

class UsersEdit extends \OWA\Core\AdminController {

    public function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // check that user_id is present
        $this->addValidation('user_id', $this->getParam('user_id'), 'required');

        // Checked when one is posted: an edit without one keeps the role.
        if ( $this->getParam('role') !== null && $this->getParam('role') !== '' ) {

            $roles = \OWA\Core\CoreAPI::getAssignableRoles();
            $this->addValidation('role', $this->getParam('role'), 'inArray', [
                'possible_values' => $roles,
                'errorMsg'        => sprintf( \OWA\Core\CoreAPI::t( 'A user\'s role is one of: %s.' ), implode( ', ', $roles ) ),
                'stopOnError'     => true,
            ] );
        }

        // Check user name exists
        $userEntityConf = [
            'entity'    => 'base.user',
            'column'    => 'user_id',
            'errorMsg'  => $this->getMsg(3001)
        ];

        $this->addValidation('user_id', $this->getParam('user_id'), 'entityExists', $userEntityConf);

        // An address, and not another user's: password resets and notices go to it.
        $email = trim( (string) $this->getParam( 'email_address' ) );

        $this->addValidation( 'email_address', $email, 'required', [ 'stopOnError' => true ] );
        $this->addValidation( 'email_address', $email, 'emailAddress',
            [ 'stopOnError' => true, 'errorMsg' => \OWA\Core\CoreAPI::t( 'Enter a valid email address.' ) ] );
    }

    public function action() {

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('user_id', $this->getParam('user_id'));

        // Another user's address is refused: a reset link for it would reach the wrong account.
        $email = trim( (string) $this->getParam('email_address') );
        $other = \OWA\Core\CoreAPI::entityFactory('base.user');
        $other->getByColumn('email_address', $email);

        if ( $other->get('id') && (string) $other->get('user_id') !== (string) $u->get('user_id') ) {

            $this->set( 'validation_errors', [ 'email_address' => $this->getMsg( 3009 )['message'] ] );

            return $this->errorAction();
        }

        $role = (string) $this->getParam('role');

        // The last account with the admin role keeps it.
        if ( $role !== '' && ( $refusal = $this->userManager()->roleChangeRefusal( (string) $u->get('user_id'), $role ) ) ) {

            $this->set( 'validation_errors', [ 'role' => $this->getMsg( $refusal )['message'] ] );

            return $this->errorAction();
        }

        $u->set('email_address', $email);
        $u->set('real_name', $this->getParam('real_name'));

        // Only when posted: an edit without one kept nothing and blanked it.
        if ( $role !== '' ) {

            $u->set('role', $role);
        }

        $u->update();
        $this->setStatusCode( 3003 );
        $this->setRedirectAction('base.users');
    }

    /** @return \OWA\Module\Base\Classes\UserManager */
    protected function userManager() {

        return new \OWA\Module\Base\Classes\UserManager();
    }

    public function errorAction() {
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview('base.usersProfile');
        /*
         * 3002 -- "the form had errors" -- not 3311, which is the CLI-updates
         * message. Controller::doAction() sets validation_errors before calling
         * errorAction() and msgs.php shows error_msg only when there are none, so
         * a wrong code here shows only once that branch changes.
         */
        $this->set('error_code', 3002);

        // The edit form again, with what was submitted: the view reads
        // 'profile' and 'edit'.
        $this->set('edit', true);
        $this->set('profile', $this->submittedProfile());
    }

    /**
     * The form's fields as they were submitted.
     *
     * @return array<string, string>
     */
    protected function submittedProfile() {

        $profile = [];

        foreach ( [ 'id', 'user_id', 'real_name', 'role', 'email_address' ] as $field ) {

            $value = $this->getParam( $field );

            $profile[ $field ] = is_scalar( $value ) ? (string) $value : '';
        }

        return $profile;
    }

}
