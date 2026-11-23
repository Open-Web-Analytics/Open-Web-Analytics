<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\UpdatesState;

/**
 * The Updates page's actions: "Check now" (cmd=core-update) and
 * "Install now" (cmd=core-install --version=<the downloaded release>). The web user cannot write OWA's
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
        $params  = [];

        if ( $run === 'install' ) {

            $v      = UpdatesState::installable( ( new \OWA\Module\Base\Classes\AutoUpdate\Downloads() )->versions(), \OWA\Core\Env::version() );
            $params = [ 'version' => $v ];
            $command = $v === null ? null : $command;
        }

        $queued = $command !== null && \OWA\Module\Base\Classes\JobQueue::enqueue( $command, $params, 'updates-screen:' . $run );

        $this->set( $queued ? 'status_code' : 'error_code', $queued ? 3314 : 3315 );
        $this->setRedirectAction( 'base.optionsUpdates' );
    }
}
