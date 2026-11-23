<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\Install;

/**
 * Install a release by hand (Classes\AutoUpdate\Install::byHand()).
 *
 *   php cli.php cmd=core-update-install                    the newest release in this major
 *   php cli.php cmd=core-update-install version=2.0.3      that one
 *   php cli.php cmd=core-update-install version=2.0.1 --force   one not newer than the installed release
 *
 * Downloaded and checked if it is not yet, then installed whatever the
 * automatic update settings say: the files are copied when the command has
 * finished, and the next run of cli.php finishes the install.
 */
class CoreUpdateInstallCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Installs a release now, whatever the automatic update settings say: the newest in this major, or the one named. Downloads and checks it and the installed release, copies its files when the command has finished, and the next run of cli.php runs its schema updates and republishes the tracking bundles. A copy that fails puts the installed release back. Clears a failed install of that release, which automatic updates skip.',
            'arguments'   => [
                'version=<x.y.z>' => 'The release to install, in this major version.',
                '--force'         => 'Install it even when it is not newer than the installed release (going back, or the same release again). Refused for a release older than the database schema, with the rollbacks to run first.',
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
        $result  = Install::byHand( $version === null || $version === '' ? null : (string) $version, (bool) $this->getParam( 'force' ) );

        $this->write( array_merge( [ $result['message'] ], array_map( static fn ( $l ) => '  ' . $l, $result['lines'] ) ) );

        if ( $result['outcome'] !== Install::OK ) {

            return $this->fail( $result['message'] );
        }
    }
}
