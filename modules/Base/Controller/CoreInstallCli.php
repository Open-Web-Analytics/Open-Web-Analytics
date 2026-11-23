<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\Install;

/**
 * Install one release by hand (Classes\AutoUpdate\Install::byHand()).
 *
 *   php cli.php cmd=core-install --version=2.0.3            that release, fetched from GitHub by its version, now
 *   php cli.php cmd=core-install --version=2.0.1 --force    the same, even when it is not newer than the installed release
 *
 * Whatever the automatic update settings say. The files are copied when the
 * command has finished, and the next run of cli.php finishes the install.
 * cmd=core-update is the automatic routine the scheduler runs.
 */
class CoreInstallCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Installs one release now, whatever the automatic update settings say: its tarball and signature are fetched from GitHub by its version (auto_update_download_url), not looked up in the listing, so any release in this major can be named. Copies its files when the command has finished, and the next run of cli.php runs its schema updates and republishes the tracking bundles. A copy that fails puts the installed release back. Clears a failed install of it, which automatic updates skip.',
            'arguments'   => [
                '--version=<x.y.z>' => 'The release to install, in this major version. Required.',
                '--force'           => 'Install it even when it is not newer than the installed release (going back, or the same release again). Never one older than the database schema: the rollbacks to run first are listed instead.',
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** The release that fixes a failed schema update installs while it is pending. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $version = $this->getParam( 'version' );

        if ( $version === null || $version === '' || $version === true ) {

            return $this->usageError( 'cmd=core-install needs --version=<x.y.z>: the release to install.' );
        }

        $result = Install::byHand( (string) $version, (bool) $this->getParam( 'force' ) );
        $this->write( array_merge( [ $result['message'] ], array_map( static fn ( $l ) => '  ' . $l, $result['lines'] ) ) );

        if ( $result['outcome'] !== Install::OK ) {

            return $this->fail( $result['message'] );
        }
    }
}
