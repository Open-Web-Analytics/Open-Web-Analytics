<?php
namespace OWA\Module\Base\View;

/**
 * The mail UsersEmailChanged sends, to the address the account had before.
 */
class UsersEmailChanged extends \OWA\Core\View\Mail {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render( $data ) {

        $this->t->set_template( 'wrapper_email.php' );
        $this->body->set_template( 'users_email_changed_email.php' );

        foreach ( [ 'user_id', 'new_email_address', 'changed', 'ip' ] as $name ) {

            $this->body->set( $name, $this->get( $name ) );
        }

        $this->setMailSubject( \OWA\Core\CoreAPI::t( 'Your account email address was changed' ) );
        $this->addMailToAddress( $this->get( 'email_address' ) );
    }
}
