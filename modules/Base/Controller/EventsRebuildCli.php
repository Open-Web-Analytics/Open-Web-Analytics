<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Rebuild owa_event from owa_event_raw, a partition at a time.
 *
 *   cmd=events-rebuild                     today's partition
 *   cmd=events-rebuild date=20260901       the partition holding that date
 *   cmd=events-rebuild days=3              today and the two days before
 *   cmd=events-rebuild from=20260901 to=20260930
 *   cmd=events-rebuild days=3 --dry-run    print the statements, run nothing
 *
 * Convergent: a partition rebuilt twice comes out the same, so a missed run
 * costs freshness and nothing else.
 *
 * TWO CADENCES ARE INTENDED, one job each, because they answer different
 * questions. A frequent run over the current partition sets how stale a report
 * can be; an infrequent run over the trailing window is where a late
 * first_visit or a session that closed after the last rebuild is picked up.
 * Neither ships registered -- v2 collection is off until a site turns it on,
 * and a job rebuilding an empty table every quarter hour would be the
 * installation's busiest. In owa-config.php:
 *
 *   define( 'OWA_SCHEDULED_JOBS', serialize( array(
 *       'rebuild-events-current' => array( 'command' => 'events-rebuild',
 *           'schedule' => '*\/15 * * * *' ),
 *       'rebuild-events-window'  => array( 'command' => 'events-rebuild',
 *           'schedule' => '@hourly', 'params' => array( 'days' => 3 ) ),
 *   ) ) );
 *
 * The lock is keyed on the job NAME, so those two serialise separately and a
 * long window rebuild does not hold up the current one.
 */
class EventsRebuildCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        // Rewrites a partition of a fact table, as the partition commands do.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->supportsPartitioning() ) {

            return $this->refuse(
                'This database driver does not support partitioning, and the pass swaps '
              . 'a partition to publish a rebuild. Nothing to do.' );
        }

        $pass  = new \OWA\Module\Base\Classes\DenormalisationPass();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getTableName();

        if ( ! $db->isPartitioned( $table ) ) {

            return $this->refuse( sprintf(
                '%s is not partitioned. Run cmd=partition-init first.', $table ) );
        }

        $range = $this->resolveRange();

        if ( ! $range ) {

            return $this->refuse(
                'Could not read that range. Use date=yyyymmdd, days=N, or from=yyyymmdd to=yyyymmdd.' );
        }

        $dry_run    = (bool) $this->getParam( 'dry-run' );
        $partitions = $pass->partitions( $range['from'], $range['to'] );

        if ( ! $partitions ) {

            /*
             * A dated partition covering the range is missing, which means the
             * rows are in the catch-all or nowhere. Refused rather than
             * rebuilt: exchanging into pmax would put rows in a partition that
             * does not describe them.
             */
            return $this->refuse( sprintf(
                'No dated partition of %s covers %d to %d. Run cmd=partition-rotate to extend the lead.',
                $table, $range['from'], $range['to'] ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Rebuilding %d partition(s) of %s for %d to %d.%s',
            count( $partitions ), $table, $range['from'], $range['to'],
            $dry_run ? ' Dry run.' : '' ) );

        $failed = 0;

        foreach ( $partitions as $span ) {

            $result = $pass->rebuild( $span, $dry_run );

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf( "%s:\n%s", $span['name'], $result['sql'] ) );

                continue;
            }

            if ( ! $result['ok'] ) {

                $failed++;

                continue;
            }

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s rebuilt: %d rows.', $span['name'], $result['rows'] ) );
        }

        if ( $failed ) {

            return $this->fail( sprintf(
                '%d of %d partition(s) failed to rebuild. The live table is unchanged for those.',
                $failed, count( $partitions ) ) );
        }
    }

    /**
     * The dates to rebuild, from whichever parameters were given.
     *
     * @return array|null ['from','to'] as yyyymmdd
     */
    protected function resolveRange() {

        $today = (int) date( 'Ymd' );

        $date = $this->getParam( 'date' );

        if ( $date ) {

            $date = $this->asDate( $date );

            return $date ? array( 'from' => $date, 'to' => $date ) : null;
        }

        $from = $this->getParam( 'from' );
        $to   = $this->getParam( 'to' );

        if ( $from || $to ) {

            $from = $from ? $this->asDate( $from ) : $today;
            $to   = $to ? $this->asDate( $to ) : $today;

            return ( $from && $to && $from <= $to )
                ? array( 'from' => $from, 'to' => $to ) : null;
        }

        $days = $this->getParam( 'days' );

        if ( $days ) {

            if ( ! ctype_digit( (string) $days ) || (int) $days < 1 ) {

                return null;
            }

            return array(
                'from' => (int) date( 'Ymd', strtotime( '-' . ( (int) $days - 1 ) . ' days' ) ),
                'to'   => $today,
            );
        }

        return array( 'from' => $today, 'to' => $today );
    }

    /**
     * @param string $value
     * @return int|null yyyymmdd, or null if it is not a real date
     */
    protected function asDate( $value ) {

        $value = trim( (string) $value );

        if ( ! preg_match( '/^\d{8}$/', $value ) ) {

            return null;
        }

        $d = \DateTimeImmutable::createFromFormat( 'Ymd|', $value );

        return ( $d && $d->format( 'Ymd' ) === $value ) ? (int) $value : null;
    }
}

?>
