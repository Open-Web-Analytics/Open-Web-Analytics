<?php
namespace OWA\Module\Base\Controller;

/**
 * Stores what base.myProfile produced.
 *
 * Always writes to the signed-in user's own row. The account is taken from the
 * session, never from the request, so there is no user_id to authorise.
 */
class MyProfileSave extends \OWA\Core\AdminController {

    /** Shortest new password accepted (User::MIN_PASSWORD_LENGTH). */
    const MIN_PASSWORD_LENGTH = \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH;

    /**
     * The messages.php codes a refusal carries back to base.myProfile, and
     * the only ones it shows.
     */
    const ERRORS = [ 2001, 3009, 3230, 3231, 3232, 3233, 3234, 3235, 3236, 3237, 3238 ];

    /** The code of each failed validation, by its name. */
    const VALIDATION_ERRORS = [
        'email_address'    => 2001,
        'current_password' => 3230,
        'new_password'     => 3231,
        'password_match'   => 3232,
        'password_length'  => 3233,
    ];

    public function __construct( $params ) {

        $this->setRequiredCapability( 'view_site_list' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    public function validate() {

        if ( (string) $this->getParam( 'email_address' ) !== '' ) {

            $this->addValidation( 'email_address', $this->getParam( 'email_address' ),
                'emailAddress',
                [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Enter a valid email address.' ) ] );
        }

        if ( ! $this->changingPassword() ) {

            return;
        }

        $this->addValidation( 'current_password', $this->getParam( 'current_password' ),
            'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Current password is required.' ) ] );

        $this->addValidation( 'new_password', $this->getParam( 'new_password' ), 'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'New password is required.' ) ] );

        $this->addValidation( 'password_match',
            [ $this->getParam( 'new_password' ), $this->getParam( 'new_password2' ) ],
            'stringMatch',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'The new passwords do not match.' ) ] );

        /*
         * stringLength, not required -- that is the validator that reads the
         * operator and length below. Typed as 'required' the rule degrades to a
         * non-empty check and short passwords pass; see
         * base.usersChangePassword, where that happened.
         */
        $this->addValidation( 'password_length', $this->getParam( 'new_password' ),
            'stringLength', [
                'operator' => '>=',
                'length'   => self::MIN_PASSWORD_LENGTH,
                'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'The new password must be at least %d characters.' ),
                    self::MIN_PASSWORD_LENGTH ),
            ] );
    }

    /**
     * Whether this submission is trying to set a password at all.
     *
     * @return bool
     */
    private function changingPassword() {

        foreach ( [ 'current_password', 'new_password', 'new_password2' ] as $field ) {

            if ( (string) $this->getParam( $field ) !== '' ) {

                return true;
            }
        }

        return false;
    }

    public function action() {

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $user_id = (string) $user->getUserData( 'user_id' );

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $entity->getByColumn( 'user_id', $user_id );

        if ( ! $entity->wasPersisted() ) {

            return $this->refuse( 3234 );
        }

        $entity->set( 'real_name', trim( (string) $this->getParam( 'real_name' ) ) );

        /*
         * The address is only writable with edit_own_email.
         *
         * isParam(), not getParam() !== '': without the capability the form
         * renders the address as text with no name attribute, so the field is
         * not posted AT ALL. Reading an absent param as an empty string made it
         * look like a change to '' and refused every save by anyone who could
         * not edit their address -- including saves that only changed a name.
         * Absent means "not offered", which is different from "cleared".
         */
        if ( $this->isParam( 'email_address' ) ) {

            $submitted_email = trim( (string) $this->getParam( 'email_address' ) );
            $current_email   = (string) $entity->get( 'email_address' );

            if ( $submitted_email !== $current_email ) {

                /*
                 * Posted a change without the capability, which the form does
                 * not offer -- so this came from something other than the form.
                 * Refused rather than ignored, so the answer is not "saved" for
                 * a change that did not happen.
                 */
                if ( ! $user->isCapable( 'edit_own_email' ) ) {

                    return $this->refuse( 3235 );
                }

                // Another user's address: a reset link for it would reach the wrong account.
                $other = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
                $other->getByColumn( 'email_address', $submitted_email );

                if ( $other->get( 'id' ) && (string) $other->get( 'user_id' ) !== $user_id ) {

                    return $this->refuse( 3009 );
                }

                $entity->set( 'email_address', $submitted_email );
            }
        }

        $entity->set( 'last_update_date', time() );

        $entity->update();

        if ( $this->changingPassword() ) {

            $error = $this->changePassword( $entity, $user_id );

            if ( $error !== 0 ) {

                return $this->refuse( $error );
            }

            /*
             * A PASSWORD CHANGE ENDS THIS SESSION, and every other.
             *
             * updateUserPassword() moves the user's session_epoch, which every
             * session cookie is signed with (Auth::sessionCredential()), so the
             * next request with any of them is anonymous.
             *
             * So it is done deliberately and said out loud: credentials
             * cleared, and back to the login form asking them to sign in with
             * the new password. That is also what the emailed reset does.
             *
             * The name and address above are already written, so a submission
             * that changed both keeps both.
             */
            $auth = \OWA\Core\Auth::get_instance();
            $auth->deleteCredentials();

            $this->setStatusCode( 3006 );
            $this->setRedirectAction( 'base.loginForm' );

            return;
        }

        /*
         * The session carries the name that the chrome greets people with, so
         * it has to be told rather than left showing the old one until the next
         * sign-in.
         */
        $user->setUserData( 'real_name', $entity->get( 'real_name' ) );
        $user->setUserData( 'email_address', $entity->get( 'email_address' ) );

        $this->set( 'myProfileSaved', true );
        $this->setRedirectAction( 'base.myProfile' );
    }

    /**
     * Verify the current password, then set the new one.
     *
     * The CURRENT password is required and checked here rather than trusted
     * from the session. A session is enough to read this screen; it is not
     * enough to replace the credential that recovers the account, because a
     * borrowed session would otherwise be able to lock its owner out.
     *
     * @param object $entity  the user row, already loaded
     * @param string $user_id
     * @return int a messages.php code, or 0 on success
     */
    private function changePassword( $entity, $user_id ) {

        $current = (string) $this->getParam( 'current_password' );

        // The check sign-in makes, a password 1.x stored included.
        if ( ! \OWA\Core\Auth::get_instance()->isAccountPassword( $user_id, $current ) ) {

            return 3236;
        }

        /*
         * updateUserPassword() hashes it and mints a new temp_passkey, which
         * also invalidates any reset link that was outstanding.
         */
        $manager = new \OWA\Module\Base\Classes\UserManager();

        $updated = $manager->updateUserPassword( [
            'user_id'  => $user_id,
            'password' => $this->getParam( 'new_password' ),
        ] );

        if ( ! $updated ) {

            return 3237;
        }

        return 0;
    }

    /**
     * Back to the form, with what was typed and why it was refused.
     *
     * Why travels as messages.php codes (ERRORS), not text: the redirect puts
     * it on the URL, and base.myProfile shows a known code's message only.
     * The name and address ride along under submitted_ names so the form
     * redraws them; the password fields deliberately do not, because carrying a
     * secret back would put it in the page source of the refusal.
     *
     * @param  int ...$codes
     * @return null
     */
    private function refuse( ...$codes ) {

        $this->set( 'myProfileError', implode( ',', $codes ) );
        $this->set( 'submitted_real_name', (string) $this->getParam( 'real_name' ) );
        $this->set( 'submitted_email_address', (string) $this->getParam( 'email_address' ) );

        $this->setRedirectAction( 'base.myProfile' );

        return null;
    }

    public function errorAction() {

        $codes = array_values( array_intersect_key( self::VALIDATION_ERRORS,
            (array) $this->getValidationErrorMsgs() ) );

        return $this->refuse( ...( $codes ?: [ 3238 ] ) );
    }

    /**
     * The codes a refusal carried, as base.myProfile reads them back: each
     * one of ERRORS, once, in order; anything else is dropped.
     *
     * @param  mixed $carried the myProfileError parameter
     * @return list<int>
     */
    public static function carriedErrors( $carried ) {

        $codes = [];

        foreach ( explode( ',', is_scalar( $carried ) ? (string) $carried : '' ) as $part ) {

            $part = trim( $part );

            if ( ctype_digit( $part ) && in_array( (int) $part, self::ERRORS, true ) ) {

                $codes[] = (int) $part;
            }
        }

        return array_values( array_unique( $codes ) );
    }
}
