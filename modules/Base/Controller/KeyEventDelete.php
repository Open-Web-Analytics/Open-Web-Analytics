<?php
namespace OWA\Module\Base\Controller;

/**
 * Remove one key event.
 *
 * A real delete, not an archive. Unlike a Profile, a key event owns no
 * collected data: the events it counted are ordinary rows that stay exactly as
 * they are, and what is removed is only the instruction to count them. There is
 * nothing to restore that deleting it destroys.
 */
class KeyEventDelete extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'keyEventId', $this->getParam( 'keyEventId' ), 'required' );
    }

    function action() {

        $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );
        $keyEvent->delete( $this->getParam( 'keyEventId' ) );

        $this->set( 'siteId', $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.keyEvents' );
        $this->set( 'status_code', 3204 );
    }
}
