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
        $this->body->set('isAdmin', false);

        if ($this->get('edit')) {
            $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Edit user profile' ));
            $this->body->set('action', 'base.usersEdit');
            $this->body->set('edit', true);
            $userEntity =  \OWA\Core\CoreAPI::entityFactory( 'base.user' );
            $userEntity->load( $user['id'] );
            $this->body->set('isAdmin', $userEntity->isOWAAdmin());
            // Names and last use only. A token is never stored, so there is none to show.
            $this->body->set('app_passwords', \OWA\Module\Base\Classes\AppPasswords::forUser( $user['user_id'] ?? '' ));
            $this->body->set('app_password_scopes', \OWA\Module\Base\Classes\AppPasswords::scopes());
        } else {
            $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Add a new user profile' ));
            $this->body->set('action', 'base.usersAdd');
            $this->body->set('edit', false);
        }
        //page title
        $this->t->set('page_title', \OWA\Core\CoreAPI::t( 'User Profile' ));
        $this->body->set_template('users_addoredit.php');
        $this->body->set('roles', \OWA\Core\CoreAPI::getAssignableRoles());

        $this->body->set('user', $user);

    }
}
