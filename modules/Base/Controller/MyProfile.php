<?php
namespace OWA\Module\Base\Controller;

/**
 * The signed-in user's own account.
 *
 * Their name, their email address and their password. Not other people's --
 * base.usersProfile is that screen and requires edit_users. This one always
 * acts on whoever is signed in, so it takes no user_id: a screen that accepted
 * one would have to prove the requester may edit that account, and there is no
 * reason for this screen to be able to edit any account but its own.
 */
class MyProfile extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        /*
         * view_site_list is what every signed-in role carries -- admin,
         * analyst and viewer -- and nothing else does. Editing your own account
         * is not an administrative act, so gating this on edit_users or
         * edit_settings would leave most users with no way to change their own
         * name.
         */
        $this->setRequiredCapability( 'view_site_list' );

        parent::__construct( $params );
    }

    public function action() {

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $this->set( 'user_id', (string) $user->getUserData( 'user_id' ) );
        $this->set( 'real_name', (string) $user->getUserData( 'real_name' ) );
        $this->set( 'email_address', (string) $user->getUserData( 'email_address' ) );
        $this->set( 'role', (string) $user->getRole() );

        /*
         * Whether the address is theirs to change. The field is shown either
         * way -- knowing which address the account uses matters even when it
         * cannot be edited here -- but read-only without the capability, and
         * the save refuses it as well.
         */
        $this->set( 'may_edit_email', (bool) $user->isCapable( 'edit_own_email' ) );

        /*
         * A refused save comes back through here rather than redirecting, so
         * what was typed is still on the form. The password fields are
         * deliberately not carried back: they are secrets, and re-rendering
         * them would put them in the page source of a refusal.
         */
        foreach ( [ 'real_name', 'email_address' ] as $field ) {

            if ( $this->getParam( 'submitted_' . $field ) !== null ) {

                $this->set( $field, (string) $this->getParam( 'submitted_' . $field ) );
            }
        }

        $error = $this->carriedErrorText( $this->getParam( 'myProfileError' ) );

        if ( $error !== '' ) {

            $this->set( 'my_profile_error', $error );
        }

        if ( $this->getParam( 'myProfileSaved' ) ) {

            $this->set( 'my_profile_saved', true );
        }

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->set( 'siteId', $siteId );

        $this->settingsChrome( [ 'siteId' => $siteId ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $siteId ) );
        $this->setSubview( 'base.myProfile', [
            'template' => 'my_profile.php',
            'vars'     => [ 'user_id', 'real_name', 'email_address', 'role', 'may_edit_email',
                            'my_profile_error', 'my_profile_saved', 'siteId',
                            'min_password_length' => MyProfileSave::MIN_PASSWORD_LENGTH ] ] );
    }

    /**
     * The messages of the codes a refused save carried back
     * (MyProfileSave::carriedErrors()); an unknown code shows nothing.
     *
     * @param  mixed $carried the myProfileError parameter
     * @return string
     */
    private function carriedErrorText( $carried ) {

        $errors = [];

        foreach ( MyProfileSave::carriedErrors( $carried ) as $code ) {

            $msg = $this->getMsg( $code, [ 'message' => [ MyProfileSave::MIN_PASSWORD_LENGTH ] ] );

            $errors[] = (string) ( $msg['message'] ?? '' );
        }

        return implode( ' ', $errors );
    }
}
