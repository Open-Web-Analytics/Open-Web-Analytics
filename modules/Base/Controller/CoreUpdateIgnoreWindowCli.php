<?php
namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;

/**
 * Install the staged release at the next scheduler run, whatever its window
 * (ReleaseCheck::installNow()); the Updates screen's "Install now" queues it.
 *
 *   php cli.php cmd=core-update-ignore-window
 */
class CoreUpdateIgnoreWindowCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Makes the staged release install at the next scheduler run, within a minute, whatever its update window. Only a release that installs itself; one staged by hand is installed with cmd=core-update-install.',
            'arguments'   => [],
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

        $result = ( new ReleaseCheck() )->installNow();

        $this->write( $result['lines'] );

        if ( $result['outcome'] === ReleaseCheck::BUSY ) {

            return $this->refuse( 'An install is running; nothing was changed.' );
        }
    }
}
