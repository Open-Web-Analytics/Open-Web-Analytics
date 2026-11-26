<?php
namespace OWA\Module\Base\View;

/**
 * The e-mail AppPasswordCreatedMail sends.
 */
class AppPasswordCreatedMail extends \OWA\Core\View\Mail {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render( $data ) {

        $this->t->set_template( 'wrapper_email.php' );
        $this->body->set_template( 'app_password_created_email.php' );

        foreach ( [ 'name', 'scope', 'created', 'from', 'lookup_id', 'key' ] as $name ) {

            $this->body->set( $name, $this->get( $name ) );
        }

        $this->setMailSubject( \OWA\Core\CoreAPI::t( 'An application password was made for your account' ) );
        $this->addMailToAddress( $this->get( 'email_address' ) );
    }
}
