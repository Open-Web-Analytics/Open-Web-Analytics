<?php
namespace OWA\Module\Base\Controller;

/**
 * Notify New Session Controller
 */

class NotifyNewSession extends \OWA\Core\Controller {

    /** Run by its handler through performAction(), never by a request. */
    const IN_PROCESS_ONLY = true;
        
    public function action() {
        
        $event = $this->getParam( 'event' );
        $site = $this->getParam( 'site' );
        $this->set( 'site', $site->_getProperties() );
            
        // Per-Profile: the Profile whose new session this is decides who hears
        // about it, falling back up to its Property, Organization and install.
        $this->set( 'email_address', \OWA\Core\CoreAPI::getSetting(
            'base', 'notice_email', 'profile', $site->get( 'site_id' ) ) );
        $this->set( 'session', $event->getProperties() );
        
        $this->set( 'subject', sprintf('OWA: New Visit to %s', $site->get( 'domain' ) ) );
        $this->setView( 'base.notifyNewSession' );
    }
}
