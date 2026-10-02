<?php

namespace OWA\Module\Base\Controller;

/**
 * Republish every Profile's tracking bundle when a new build lands (PLAN
 * 2.30.7).
 *
 *   cli.php cmd=tracker-build-check
 *
 * Every minute from the scheduler, so an OWA update reaches visitors within a
 * minute without anyone running cmd=update for it. A minute with nothing new
 * reads the build manifest and the first line of each bundle, which names the
 * build it was made from -- no database.
 */
class TrackerBuildCheckCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $B = '\OWA\Module\Base\Classes\TrackerBundle';

        if ( $B::buildIsPublished() ) {

            return;
        }

        $counts = array_count_values( $B::publishStale( false, null ) );

        \OWA\Core\CoreAPI::notice( sprintf( 'A new tracker build: %d bundle(s) published, %d current, %d failed.',
            $counts['published'] ?? 0, $counts['current'] ?? 0, $counts['failed'] ?? 0 ) );
    }
}

?>
