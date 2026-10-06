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
 */
class TrackerIngestReplayCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Sends the tracker-ingest dead letters that decode back to the intake. Runs on the scheduler daily as replay-tracker-ingest.',
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

        try {

            $counts = \OWA\Module\Base\Classes\TrackerIngest::replay(
                (bool) $this->getParam( 'scheduled' ), time() + self::BUDGET );

        } catch ( \Throwable $t ) {

            return $this->fail( 'Tracker ingest: ' . $t->getMessage() );
        }

        if ( array_sum( $counts ) || ! $this->getParam( 'scheduled' ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Tracker ingest dead letters: %d replayed, %d replayed before and kept, %d unreadable and kept.',
                $counts['replayed'], $counts['kept'], $counts['unreadable'] ) );
        }
    }
}

?>
