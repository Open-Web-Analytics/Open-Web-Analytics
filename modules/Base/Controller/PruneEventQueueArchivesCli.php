<?php
namespace OWA\Module\Base\Controller;

/**
 * Prune Event Queues Archive CLI Controller
 */

class PruneEventQueueArchivesCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Deletes archived files of the file-based event queues.',
            'arguments'   => [
                'queues=<name>[,<name>...]' => 'The queues to prune. Defaults to every registered queue.',
                'interval=<seconds>'        => 'Age beyond which an archive is deleted. Defaults to 86400.',
                'scheduled=1'               => 'Set by the scheduler. Deletes only archives older than raw_retention_months, and none when it is not set; interval= is ignored.',
            ],
        ];
    }

    public function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        parent::__construct($params);
    }

    /**
     *   cli.php cmd=prune-event-queue-archives [queues=a,b] [interval=<seconds>]
     *
     * Archived batches older than interval (a day by default), of every
     * registered queue that keeps an archive, or of those named.
     *
     * Scheduled (prune-tracker-ingest-archive), the age is raw's retention
     * window instead: an archived batch is kept as long as the events in it
     * would be, so with raw_retention_months unset nothing is deleted.
     */
    public function action() {

        if ( $this->getParam( 'scheduled' ) ) {

            $keep_from = \OWA\Module\Base\Classes\Retention::rawCutoffTimestamp();

            if ( $keep_from === null ) {

                return;
            }

            $interval = max( 0, time() - $keep_from );

        } else {

            $raw = $this->getParam( 'interval' );

            // Seconds, as digits: (int) "7days" would be 7 seconds.
            if ( $raw !== null && $raw !== '' && ! ctype_digit( (string) $raw ) ) {

                return $this->usageError( sprintf(
                    'interval= takes a number of seconds, such as 604800 for a week; got "%s". Nothing was deleted.',
                    is_string( $raw ) ? $raw : '--interval' ) );
            }

            $interval = (int) ( $raw ?: 86400 );
        }

        $known = array_values( array_unique( array_merge(
            array_keys( (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'event_queues' ) ),
            [ \OWA\Module\Base\Classes\TrackerIngest::QUEUE ] ) ) );

        $queues = $this->getParam( 'queues' )
            ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $this->getParam( 'queues' ) ) ), static fn ( $name ) => $name !== '' ) )
            : array_keys( (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'event_queues' ) );

        // Every name is checked before anything is pruned.
        foreach ( $queues as $queue_name ) {

            if ( ! in_array( $queue_name, $known, true ) ) {

                return $this->usageError( sprintf( 'Unknown queue: %s. Queues: %s.', $queue_name, implode( ', ', $known ) ) );
            }
        }

        foreach ( $queues as $queue_name ) {

            // The intake is built from its setting, not its registration.
            $q = $queue_name === \OWA\Module\Base\Classes\TrackerIngest::QUEUE
                ? \OWA\Module\Base\Classes\TrackerIngest::queue()
                : \OWA\Core\CoreAPI::getEventQueue( $queue_name );

            // The intake's file fallback (TrackerIngest::fallback()) archives what it forwards.
            $fallback = $queue_name === \OWA\Module\Base\Classes\TrackerIngest::QUEUE
                ? \OWA\Module\Base\Classes\TrackerIngest::fallback() : null;

            foreach ( [ $q, $fallback ] as $each ) {

                if ( $each && method_exists( $each, 'pruneArchive' ) ) {

                    $pruned = (int) $each->pruneArchive( $interval );

                    \OWA\Core\CoreAPI::notice( sprintf( 'Pruned %d archived file%s of event queue %s.',
                        $pruned, $pruned === 1 ? '' : 's', $queue_name ) );
                }
            }
        }
    }
}
