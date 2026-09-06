<?php

namespace OWA\Module\Base\View;

/**
 * The signed-in user's own account screen.
 *
 * Every value the template reads is forwarded by name: the body is a separate
 * template with its own scope, so a value the controller set but this method
 * does not mention is simply absent, and a missing template variable is an
 * error rather than an empty string.
 *
 * @since owa 1.8.0
 */
class MyProfile extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Profile' );

        $this->body->set_template( 'my_profile.php' );

        $this->body->set( 'user_id', $this->get( 'user_id' ) );
        $this->body->set( 'real_name', $this->get( 'real_name' ) );
        $this->body->set( 'email_address', $this->get( 'email_address' ) );
        $this->body->set( 'role', $this->get( 'role' ) );
        $this->body->set( 'may_edit_email', $this->get( 'may_edit_email' ) );
        $this->body->set( 'my_profile_error', $this->get( 'my_profile_error' ) );
        $this->body->set( 'my_profile_saved', $this->get( 'my_profile_saved' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
        $this->body->set( 'min_password_length',
            \OWA\Module\Base\Controller\MyProfileSave::MIN_PASSWORD_LENGTH );
    }
}
