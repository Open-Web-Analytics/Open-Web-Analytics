<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * What automatic updates will do here, and what is downloaded (the updates
 * directory, Classes\AutoUpdate\Downloads).
 *
 *   php cli.php cmd=core-update-status
 *
 * The settings in effect, and each downloaded release: when it was checked,
 * whether it can be installed, any update in it that must be installed by
 * hand; a failed install, and one under way.
 */
class CoreUpdateStatusCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Shows what automatic updates will do: the settings in effect, each downloaded release and whether it can be installed, a failed install, and an install under way.',
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
