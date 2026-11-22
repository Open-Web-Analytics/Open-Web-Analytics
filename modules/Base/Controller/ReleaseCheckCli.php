<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Step 1: release check, run once (Classes\AutoUpdate\ReleaseCheck).
 *
 *   php cli.php cmd=release-check             stage and judge the release to update to
 *   php cli.php cmd=release-check --dry-run   only say which release that is
 *
 * Installs nothing: it leaves owa-data/updates/staged.json pointing at a
 * release that can be installed here, for the installer.
 *
 * Runs while updates are pending: a release that fixes a failed update is
 * the one to stage. The scheduler runs it every six hours as release-check,
 * on any server that runs the scheduler: the bell's release feed comes from
 * its listing, and only a full server stages.
 */
class ReleaseCheckCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Stores new OWA releases as notifications, then finds the release this installation updates to automatically, downloads and verifies it, checks that it can be installed here, and stages it for the installer. Installs nothing. Runs on the scheduler every six hours as release-check.',
            'arguments'   => [
                '--dry-run' => 'Only say which release that is: nothing is downloaded or written.',
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** A release that fixes a failed update is staged while it is pending. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $result = ( new ReleaseCheck() )->run( (bool) $this->getParam( 'dry-run' ) );

        $this->write( $result['lines'] );

        if ( in_array( $result['outcome'], [ ReleaseCheck::FAILED, ReleaseCheck::UNREACHED ], true ) ) {

            return $this->fail( sprintf( 'Release check: %s.', $result['outcome'] ) );
        }
    }
}
