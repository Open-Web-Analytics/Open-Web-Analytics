<?php
namespace OWA\Module\Base\View;

/**
 * OWA User Profile View
 */

class UsersProfile extends \OWA\Core\View {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {
        $user = $this->get('profile');

        if ($this->get('edit')) {
            $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Edit user profile' ));
            $this->body->set('action', 'base.usersEdit');
            $this->body->set('edit', true);
            // Names and last use only. A token is never stored, so there is none to show.
            $this->body->set('app_passwords', \OWA\Module\Base\Classes\AppPasswords::forUser( $user['user_id'] ?? '' ));
            $this->body->set('app_password_scopes', \OWA\Module\Base\Classes\AppPasswords::scopes());
        } else {
            $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Add a new user profile' ));
            $this->body->set('action', 'base.usersAdd');
            $this->body->set('edit', false);

            // A new account starts at the least-privileged role.
            if ( ! is_array( $user ) ) {

                $user = [];
            }

            if ( (string) ( $user['role'] ?? '' ) === '' ) {

                $user['role'] = \OWA\Core\CoreAPI::leastPrivilegedRole();
            }
        }
        //page title
        $this->t->set('page_title', \OWA\Core\CoreAPI::t( 'User Profile' ));
        $this->body->set_template('users_addoredit.php');
        $this->body->set('roles', \OWA\Core\CoreAPI::getAssignableRoles());

        $this->body->set('user', $user);

    }
}
