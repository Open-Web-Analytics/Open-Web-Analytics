<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Send the tracker-ingest dead letters back to the intake (PLAN 2.30.4).
 *
 *   cli.php cmd=tracker-ingest-replay      every dead letter that decodes, once its cause is fixed
 *
 * The scheduler runs it daily as replay-tracker-ingest, replaying only those
 * not replayed before. A dead letter that never decoded is never replayed.
 * Either way, one whose event is older than raw_retention_months is deleted,
 * and on SQS one older than the queue's fourteen-day retention is deleted by
 * SQS.
 */
class TrackerIngestReplayCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Sends the tracker-ingest dead letters that decode back to the intake, and deletes those older than raw_retention_months. Runs on the scheduler daily as replay-tracker-ingest.',
            'arguments'   => array(
                'scheduled=1' => 'Set by the scheduler. Replays only dead letters not replayed before.',
            ),
        );
    }

    /** A run's budget, in seconds. */
    const BUDGET = 120;

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $I = '\OWA\Module\Base\Classes\TrackerIngest';

        try {

            $counts = $I::replay( (bool) $this->getParam( 'scheduled' ), time() + self::BUDGET );
            $main   = $I::queue();
            $dlq    = $main->deadLetterQueue();
            $error  = $I::queueError( $main ) ?: ( $dlq ? $I::queueError( $dlq ) : null );

        } catch ( \Throwable $t ) {

            return $this->fail( 'Tracker ingest: ' . $t->getMessage() );
        }

        if ( $error ) {

            return $this->fail( 'Tracker ingest dead letters: the queue failed: ' . $error );
        }

        if ( array_sum( $counts ) || ! $this->getParam( 'scheduled' ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Tracker ingest dead letters: %d replayed, %d replayed before and kept, %d unreadable and kept, '
              . '%d older than raw_retention_months and deleted.',
                $counts['replayed'], $counts['kept'], $counts['unreadable'], $counts['expired'] ) );
        }
    }
}

?>
