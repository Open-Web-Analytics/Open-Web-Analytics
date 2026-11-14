<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Ingest what the tracker-ingest intake holds (PLAN 2.30.4).
 *
 *   cli.php cmd=drain-tracker-ingest                 until empty, or 45 seconds
 *   cli.php cmd=drain-tracker-ingest seconds=600     a longer budget, by hand
 *   cli.php cmd=drain-tracker-ingest forward_only=1  a collector: forward the file fallback only
 *
 * Each run first forwards what the file fallback holds to the intake
 * (TrackerIngest::forwardFallback()). The scheduler runs it every minute, on
 * the servers whose role runs the scheduler. A collector has no database to
 * ingest into, so there it only forwards.
 *
 * A run fails when the intake could not be read or written -- a missing
 * permission, an unreachable queue -- rather than finishing with nothing done.
 */
class DrainTrackerIngestCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::serverRoles(). A collector runs it with forward_only=1, from cron. */
    public static function serverRoles() {

        return array( \OWA\Core\ServerRole::FULL, \OWA\Core\ServerRole::WORKER, \OWA\Core\ServerRole::COLLECTOR );
    }

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Ingests the beacons waiting in the tracker-ingest queue, until it is empty or the time budget runs out. First forwards any beacons the queue refused and the file queue kept. Runs on the scheduler every minute.',
            'arguments'   => array(
                'seconds=<n>'    => 'Time budget in seconds. Defaults to 45.',
                'forward_only=1' => 'Only forward the beacons the file queue kept to the tracker-ingest queue, without ingesting. For a collector, from cron.',
                'scheduled=1'    => 'Set by the scheduler. A scheduled run reports only when it did something.',
            ),
        );
    }

    /** A scheduled run's budget: inside the minute, so runs do not queue up behind each other. */
    const BUDGET = 45;

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        if ( ! $this->getParam( 'forward_only' ) && ! \OWA\Core\ServerRole::current()->hasDatabase() ) {

            return $this->refuse( 'This server has no database to ingest into (role '
                . \OWA\Core\ServerRole::current()->name() . '). Run with forward_only=1.' );
        }

        $I       = '\OWA\Module\Base\Classes\TrackerIngest';
        $seconds = (int) ( $this->getParam( 'seconds' ) ?: self::BUDGET );

        try {

            $deadline  = time() + max( 1, $seconds );
            $forwarded = $I::forwardFallback( $deadline );

        } catch ( \Throwable $t ) {

            return $this->fail( 'Tracker ingest: ' . $t->getMessage() );
        }

        if ( array_sum( $forwarded ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: %d forwarded from the file queue, %d still waiting there, '
                . '%d dead-lettered there.', $forwarded['forwarded'], $forwarded['waiting'], $forwarded['dead'] ) );
        }

        if ( $this->getParam( 'forward_only' ) ) {

            return $forwarded['waiting']
                ? $this->fail( 'Tracker ingest: the intake did not answer; beacons wait in the file queue.' )
                : null;
        }

        try {

            $counts = $I::drain( $deadline );
            $error  = $I::queueError( $I::queue() );

        } catch ( \Throwable $t ) {

            return $this->fail( 'Tracker ingest: ' . $t->getMessage() );
        }

        if ( array_sum( $counts ) || ! $this->getParam( 'scheduled' ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: %d ingested, %d to retry, %d dead-lettered.',
                $counts['ingested'], $counts['released'], $counts['dead'] ) );
        }

        if ( $error ) {

            return $this->fail( 'Tracker ingest: the intake failed: ' . $error );
        }
    }
}

?>
