<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * What automatic updates will do here, and what is staged (owa-data/updates/).
 *
 *   php cli.php cmd=self-update
 *
 * The settings in effect, the release staged to install and when, and each
 * downloaded release: when it was checked, whether it can be installed, any
 * update in it that must be installed by hand, and its install's state.
 */
class SelfUpdateCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Shows what automatic updates will do: the settings in effect, the release staged to install and when, and every downloaded release with its checks and install state.',
            'arguments'   => [],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** It reads files and settings only. */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $this->write( ( new ReleaseCheck() )->status() );
    }
}
