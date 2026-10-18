<?php

namespace OWA\Module\Base\View;

/**
 * The signed-in user's application passwords.
 *
 * Each value the template reads is forwarded by name, as in MyProfile: the body
 * template has its own scope.
 */
class MyAppPasswords extends \OWA\Core\View {

    function render() {

        $this->t->set( 'page_title', 'Application Passwords' );

        $this->body->set_template( 'my_app_passwords.php' );

        foreach ( array( 'app_passwords', 'scopes', 'enabled', 'require_https', 'api_url',
                         'new_app_password', 'siteId' ) as $name ) {

            $this->body->set( $name, $this->get( $name ) );
        }
    }
}
