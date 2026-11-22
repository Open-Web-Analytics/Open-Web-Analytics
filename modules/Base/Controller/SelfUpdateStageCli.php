<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Download, verify and judge one release by hand (ReleaseCheck::stageByHand()).
 *
 *   php cli.php cmd=self-update-stage version=2.0.3
 *
 * Any version in this major, older ones included, whatever the automatic
 * update settings say: the operator chose it. It is not scheduled; install it
 * with cmd=self-update-install. For an older release it prints the rollback
 * commands to run first.
 */
class SelfUpdateStageCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Downloads one release in place of the staged one, checks it against its SHA-256, and checks it can be installed here, whatever the automatic update settings say. It is not scheduled: install it with cmd=self-update-install. The release check leaves it alone for 24 hours. For an older release, prints the rollback commands to run first.',
            'arguments'   => [
                'version=<x.y.z>' => 'The release to stage, in this major version.',
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

        $version = (string) $this->getParam( 'version' );

        if ( $version === '' ) {

            return $this->usageError( 'version= names the release to stage, such as version=2.0.3.' );
        }

        $result = ( new ReleaseCheck() )->stageByHand( $version );

        $this->write( $result['lines'] );

        if ( $result['outcome'] !== ReleaseCheck::STAGED ) {

            return $this->fail( sprintf( '%s was not staged to install.', $version ) );
        }
    }
}
