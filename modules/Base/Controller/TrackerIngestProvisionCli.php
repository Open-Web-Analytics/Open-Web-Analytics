<?php
namespace OWA\Module\Base\Controller;

/**
 * Create the tracker-ingest queue and its dead-letter queue, or find them
 * there (PLAN 2.30.3): directories for the file queue, queues for a module's
 * backend. Idempotent.
 *
 *   cli.php cmd=tracker-ingest-provision
 */
class TrackerIngestProvisionCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Creates the tracker-ingest queue and its dead-letter queue, or confirms they exist.',
            'arguments'   => [],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function action() {

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
