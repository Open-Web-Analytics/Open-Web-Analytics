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
 *   cli.php cmd=drain-tracker-ingest              until empty, or 45 seconds
 *   cli.php cmd=drain-tracker-ingest seconds=600  a longer budget, by hand
 *
 * The scheduler runs it every minute. With tracker_ingest_drain = external the
 * scheduled run refuses, since something outside OWA consumes the queue; a run
 * by hand still drains.
 */
class DrainTrackerIngestCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Ingests the beacons waiting in the tracker-ingest queue, until it is empty or the time budget runs out. Runs on the scheduler every minute.',
            'arguments'   => array(
                'seconds=<n>' => 'Time budget in seconds. Defaults to 45.',
                'scheduled=1' => 'Set by the scheduler. A scheduled run refuses when tracker_ingest_drain is external.',
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

        $I = '\OWA\Module\Base\Classes\TrackerIngest';

        if ( $this->getParam( 'scheduled' ) && $I::isDrainedExternally() ) {

            return $this->refuse( 'tracker_ingest_drain is external: the scheduler does not drain the intake.' );
        }

        $seconds = (int) ( $this->getParam( 'seconds' ) ?: self::BUDGET );

        try {

            $counts = $I::drain( time() + max( 1, $seconds ) );

        } catch ( \Throwable $t ) {

            return $this->fail( 'Tracker ingest: ' . $t->getMessage() );
        }

        if ( array_sum( $counts ) || ! $this->getParam( 'scheduled' ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Tracker ingest: %d ingested, %d to retry, %d dead-lettered.',
                $counts['ingested'], $counts['released'], $counts['dead'] ) );
        }
    }
}

?>
