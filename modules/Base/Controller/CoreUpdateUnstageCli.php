<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Stop an automatic install before it starts (ReleaseCheck::cancel()).
 *
 *   php cli.php cmd=core-update-unstage                  the release staged to install
 *   php cli.php cmd=core-update-unstage version=2.0.3    and delete that release's staging
 *
 * Refused while an install is running.
 */
class CoreUpdateUnstageCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Deletes the staged release, so nothing installs until one is staged again: the next release check stages it again unless automatic updates are off. Refused while an install runs or is unfinished.',
            'arguments'   => [
                'version=<x.y.z>' => 'Only if it is the release staged.',
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** It changes files only. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $version = $this->getParam( 'version' );
        $result  = ( new ReleaseCheck() )->cancel( $version === null || $version === '' ? null : (string) $version );

        $this->write( $result['lines'] );

        if ( $result['outcome'] === ReleaseCheck::BUSY ) {

            return $this->refuse( 'An install is running; nothing was cancelled.' );
        }
    }
}
