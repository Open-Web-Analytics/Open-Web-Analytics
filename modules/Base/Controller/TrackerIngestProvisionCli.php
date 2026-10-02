<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Create the tracker-ingest queue and its dead-letter queue, or find them
 * there (PLAN 2.30.3): directories for the file queue, queues for a module's
 * backend. Idempotent.
 *
 *   cli.php cmd=tracker-ingest-provision
 */
class TrackerIngestProvisionCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        try {

            $queue = \OWA\Module\Base\Classes\TrackerIngest::queue();
            $ok    = $queue->provision();

        } catch ( \Throwable $t ) {

            return $this->fail( 'Tracker ingest: ' . $t->getMessage() );
        }

        if ( ! $ok ) {

            return $this->fail( 'Tracker ingest: the queues could not be provisioned; see the log.' );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: %s queue and its dead-letter queue are in place.',
            get_class( $queue ) ) );
    }
}

?>
