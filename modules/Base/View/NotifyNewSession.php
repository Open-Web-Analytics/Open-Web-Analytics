<?php
namespace OWA\Module\Base\View;

/**
 * New Session Notification View
 */

class NotifyNewSession extends \OWA\Core\View\Mail {
        
    /**
     * @return void
     */
    public function render() {
        
        $this->t->set_template( 'wrapper_email.php' );
        $this->body->set_template( 'new_session_email.php' );
        $this->body->set( 'site', $this->get( 'site' ) );
        $this->body->set( 'session', $this->get( 'session' ) );
        $this->setMailSubject( $this->get('subject') );
        $this->addMailToAddress( $this->get('email_address') );
    }
}
