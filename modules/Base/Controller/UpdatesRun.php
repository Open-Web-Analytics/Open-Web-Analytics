<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\UpdatesState;

/**
 * The Updates page's actions: "Check now" (cmd=core-update) and
 * "Install now" (cmd=core-update-install). The web user cannot write OWA's
 * code or the updates directory, so each is queued as a job the scheduler
 * runs as the install owner, within a minute.
 */
class UpdatesRun extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();

        parent::__construct( $params );
    }

    public function action() {

        $run     = (string) $this->getParam( 'run' );
        $command = UpdatesState::ACTIONS[ $run ] ?? null;
        $queued  = $command !== null && \OWA\Module\Base\Classes\JobQueue::enqueue( $command, [], 'updates-screen:' . $run );

        $this->set( $queued ? 'status_code' : 'error_code', $queued ? 3314 : 3315 );
        $this->setRedirectAction( 'base.optionsUpdates' );
    }
}
