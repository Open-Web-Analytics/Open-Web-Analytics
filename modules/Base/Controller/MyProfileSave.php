<?php
namespace OWA\Module\Base\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//


/**
 * Stores what base.myProfile produced.
 *
 * Always writes to the signed-in user's own row. The account is taken from the
 * session, never from the request, so there is no user_id to authorise.
 *
 * @since owa 1.8.0
 */
class MyProfileSave extends \OWA\Core\Controller {

    /** Shortest new password accepted, matching base.usersChangePassword. */
    const MIN_PASSWORD_LENGTH = 6;

    function __construct( $params ) {

        $this->setRequiredCapability( 'view_site_list' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    public function validate() {

        if ( (string) $this->getParam( 'email_address' ) !== '' ) {

            $this->addValidation( 'email_address', $this->getParam( 'email_address' ),
                'emailAddress',
                array( 'errorMsg' => 'That does not look like an email address.' ) );
        }

        if ( ! $this->changingPassword() ) {

            return;
        }

        $this->addValidation( 'current_password', $this->getParam( 'current_password' ),
            'required',
            array( 'errorMsg' => 'Enter your current password to change it.' ) );

        $this->addValidation( 'new_password', $this->getParam( 'new_password' ), 'required',
            array( 'errorMsg' => 'Enter the new password.' ) );

        $this->addValidation( 'password_match',
            array( $this->getParam( 'new_password' ), $this->getParam( 'new_password2' ) ),
            'stringMatch',
            array( 'errorMsg' => 'The new passwords do not match.' ) );

        /*
         * stringLength, not required -- that is the validator that reads the
         * operator and length below. Typed as 'required' the rule degrades to a
         * non-empty check and short passwords pass; see
         * base.usersChangePassword, where that happened.
         */
        $this->addValidation( 'password_length', $this->getParam( 'new_password' ),
            'stringLength', array(
                'operator' => '>=',
                'length'   => self::MIN_PASSWORD_LENGTH,
                'errorMsg' => sprintf( 'The new password must be at least %d characters.',
                    self::MIN_PASSWORD_LENGTH ),
            ) );
    }

    /** Whether this submission is trying to set a password at all. */
    private function changingPassword() {

        foreach ( array( 'current_password', 'new_password', 'new_password2' ) as $field ) {

            if ( (string) $this->getParam( $field ) !== '' ) {

                return true;
            }
        }

        return false;
    }

    function action() {

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $user_id = (string) $user->getUserData( 'user_id' );

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $entity->getByColumn( 'user_id', $user_id );

        if ( ! $entity->wasPersisted() ) {

            return $this->refuse( 'That account no longer exists.' );
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

                    return $this->refuse(
                        'Changing the email address on your account is not something '
                      . 'your role can do. Ask an administrator.' );
                }

                $entity->set( 'email_address', $submitted_email );
            }
        }

        $entity->set( 'last_update_date', time() );

        $entity->update();

        if ( $this->changingPassword() ) {

            $error = $this->changePassword( $entity, $user_id );

            if ( $error !== '' ) {

                return $this->refuse( $error );
            }

            /*
             * A PASSWORD CHANGE ENDS THIS SESSION.
             *
             * The auth cookie is md5( user_id . password_hash ) -- see
             * Auth::saveCredentials() -- so the moment the hash changes the
             * cookie stops matching and the next request is anonymous. Left
             * alone, someone who changed their password was silently signed out
             * on their next click and had no idea why.
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

            $this->set( 'status_code', 3006 );
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
     * @return string an error, or '' on success
     */
    private function changePassword( $entity, $user_id ) {

        $current = (string) $this->getParam( 'current_password' );

        /*
         * password_verify against the stored hash, the same check Auth uses to
         * sign somebody in. Not encryptPassword() and compare: password_hash
         * salts, so two hashes of one password never match as strings.
         */
        if ( ! password_verify( $current, (string) $entity->get( 'password' ) ) ) {

            return 'That is not your current password.';
        }

        /*
         * updateUserPassword() hashes it and mints a new temp_passkey, which
         * also invalidates any reset link that was outstanding.
         */
        $manager = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'userManager' );

        $updated = $manager->updateUserPassword( array(
            'user_id'  => $user_id,
            'password' => $this->getParam( 'new_password' ),
        ) );

        if ( ! $updated ) {

            return 'The password could not be changed.';
        }

        return '';
    }

    /**
     * Back to the form, with what was typed and why it was refused.
     *
     * The name and address ride along under submitted_ names so the form
     * redraws them; the password fields deliberately do not, because carrying a
     * secret back would put it in the page source of the refusal.
     */
    private function refuse( $message ) {

        $this->set( 'myProfileError', $message );
        $this->set( 'submitted_real_name', (string) $this->getParam( 'real_name' ) );
        $this->set( 'submitted_email_address', (string) $this->getParam( 'email_address' ) );

        $this->setRedirectAction( 'base.myProfile' );
    }

    function errorAction() {

        $messages = (array) $this->getValidationErrorMsgs();

        return $this->refuse( $messages ? implode( ' ', $messages )
            : 'Those details could not be saved.' );
    }
}
