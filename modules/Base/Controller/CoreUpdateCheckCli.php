<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Automatic updates, run once (Classes\AutoUpdate\ReleaseCheck).
 *
 *   php cli.php cmd=core-update-check             list the releases, download the one to update to, and install it if it is due
 *   php cli.php cmd=core-update-check --dry-run   only say which release that is
 *
 * The scheduler runs it every hour as core-update-check, on any server that
 * runs the scheduler: four of those runs a day list the releases for the
 * bell, and only a full server downloads and installs. An install's files
 * are copied when the run has finished, and the next run of cli.php
 * finishes it. Runs while updates are pending: a release that fixes a failed
 * update is the one to install.
 */
class CoreUpdateCheckCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Stores new OWA releases as notifications, then finds the release this installation updates to automatically, downloads and verifies it, checks that it can be installed here, and installs it if the update window allows. Runs on the scheduler every hour as core-update-check, listing the releases four times a day.',
            'arguments'   => [
                '--dry-run'   => 'Only say which release that is: nothing is downloaded, written or installed.',
                'scheduled=1' => 'Set by the scheduler: the releases are listed only in four of the day\'s hours, and nothing is said.',
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** A release that fixes a failed update installs while it is pending. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $scheduled = (bool) $this->getParam( 'scheduled' );
        $result    = ( new ReleaseCheck( null, [ 'scheduled' => $scheduled ] ) )->run( (bool) $this->getParam( 'dry-run' ) );

        if ( ! $scheduled ) {

            $this->write( $result['lines'] );
        }

        if ( in_array( $result['outcome'], [ ReleaseCheck::FAILED, ReleaseCheck::UNREACHED ], true ) ) {

            return $this->fail( sprintf( 'Release check: %s. %s', $result['outcome'], implode( ' ', $result['problems'] ) ) );
        }
    }
}
