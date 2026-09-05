<?php
namespace OWA\Module\Base\Controller;

/**
 * Remove one goal event.
 *
 * A real delete, not an archive. Unlike a Profile, a goal event owns no
 * collected data: the events it counted are ordinary rows that stay exactly as
 * they are, and what is removed is only the instruction to count them. There is
 * nothing to restore that deleting it destroys.
 */
class GoalEventDelete extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'goalEventId', $this->getParam( 'goalEventId' ), 'required' );
    }

    function action() {

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->delete( $this->getParam( 'goalEventId' ) );

        $this->set( 'siteId', $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.goalEvents' );
        $this->set( 'status_code', 3204 );
    }
}
