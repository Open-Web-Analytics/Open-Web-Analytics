<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\Install;
use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Automatic updates, run once (Classes\AutoUpdate\ReleaseCheck), or one
 * release installed by hand (Install::byHand()).
 *
 *   php cli.php cmd=core-update                            as the scheduler runs it: list, download, install if the settings allow
 *   php cli.php cmd=core-update --dry-run                  only say which release that is
 *   php cli.php cmd=core-update --version=2.0.3            that release, fetched from GitHub by its version, now, whatever the settings say
 *   php cli.php cmd=core-update --version=2.0.1 --force    the same, even when it is not newer than the installed release
 *
 * The scheduler runs it every hour, on any server that runs the scheduler:
 * four of those runs a day list the releases for the bell, and only a full
 * server downloads and installs. An install's files are copied when the run
 * has finished, and the next run of cli.php finishes it. Runs while updates
 * are pending: a release that fixes a failed update is the one to install.
 */
class CoreUpdateCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Automatic updates: stores new OWA releases as notifications, then finds the release this installation updates to, downloads and verifies it, checks that it can be installed here, and installs it if the settings allow. With --version, installs that release now, whatever the settings say. Runs on the scheduler every hour as core-update, listing the releases four times a day. A copy that fails puts the installed release back.',
            'arguments'   => [
                '--version=<x.y.z>' => 'Install this release now, in this major version, whatever the automatic update settings say. Its tarball and signature are fetched from GitHub by its version (auto_update_download_url), not looked up in the listing, so any release can be named. Clears a failed install of it, which automatic updates skip.',
                '--force'           => 'With --version: install it even when it is not newer than the installed release (going back, or the same release again). Never one older than the database schema: the rollbacks to run first are listed instead.',
                '--dry-run'         => 'Only say which release automatic updates would install: nothing is downloaded, written or installed.',
                'scheduled=1'       => 'Set by the scheduler: the releases are listed only in four of the day\'s hours, and nothing is said.',
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

        $version = $this->getParam( 'version' );
        $version = $version === null || $version === '' || $version === true ? null : (string) $version;
        $force   = (bool) $this->getParam( 'force' );

        if ( $force && $version === null ) {

            return $this->usageError( '--force goes with --version=<x.y.z>: it installs that release even when it is not newer than the installed one.' );
        }

        if ( $version !== null ) {

            if ( $this->getParam( 'dry-run' ) ) {

                return $this->usageError( '--dry-run says what automatic updates would install; it takes no --version.' );
            }

            $result = Install::byHand( $version, $force );
            $this->write( array_merge( [ $result['message'] ], array_map( static fn ( $l ) => '  ' . $l, $result['lines'] ) ) );

            return $result['outcome'] === Install::OK ? null : $this->fail( $result['message'] );
        }

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
