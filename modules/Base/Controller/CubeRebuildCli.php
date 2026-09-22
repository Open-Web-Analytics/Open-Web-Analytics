<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Rebuild the reporting cube from owa_event_raw, a partition at a time.
 *
 *   cmd=cube-rebuild                     yesterday and today
 *   cmd=cube-rebuild from=20260901       that date through to today
 *   cmd=cube-rebuild from=20260901 to=20260930
 *   cmd=cube-rebuild days=3              the last 3 days, today included
 *   cmd=cube-rebuild days=3 --dry-run    print the statements, run nothing
 *   cmd=cube-rebuild steps=1             per-step timings and counts
 *
 * Convergent: a partition rebuilt twice comes out the same, so a missed run
 * costs freshness and nothing else.
 *
 * THE DATES SELECT PARTITIONS, NOT DAYS. A build rebuilds a whole partition,
 * because the swap is the unit of work -- so `from=20260915 to=20260915` against
 * monthly partitioning rebuilds every row of September, and `days=3` usually
 * resolves to the one current partition rather than to three days of work. The
 * command prints what each date range resolved to for exactly that reason.
 *
 * It is also why partition granularity and the run interval are one decision
 * rather than two (2.5.1): at a quarter-hourly cadence on a monthly partition,
 * the last run of the month rewrites a month.
 *
 * REGISTERED AS `rebuild-cube`, daily and spread. One job, not the two cadences
 * 2.5.1 eventually wants: the default range is yesterday and today, so a daily
 * run keeps the cube a day fresh, and nothing reports over owa_event yet -- a
 * quarter-hourly run would buy freshness no reader can see while paying a swap
 * every fifteen minutes.
 *
 * TWO CADENCES ARE INTENDED EVENTUALLY, one job each, because they answer
 * different questions. A frequent run over the current partition sets how stale
 * a report can be; an infrequent run over the trailing window is where a late
 * first_visit or a session that closed after the last rebuild is picked up. An
 * installation that wants them states them in owa-config.php:
 *
 *   define( 'OWA_SCHEDULED_JOBS', serialize( array(
 *       'rebuild-cube-current' => array( 'command' => 'cube-rebuild',
 *           'schedule' => '0,15,30,45 * * * *' ),
 *       'rebuild-cube-window'  => array( 'command' => 'cube-rebuild',
 *           'schedule' => '@hourly', 'params' => array( 'days' => 3 ) ),
 *   ) ) );
 *
 * Written long rather than as a step expression because the step form's slash
 * would have to be escaped to survive this docblock, and Cron::parse() refuses
 * the escaped text -- so the line an operator copied would leave the job
 * silently unscheduled. The two parse identically.
 *
 * The SCHEDULER's lock is keyed on the job name, so those two are not serialised
 * against each other by it. They are serialised here instead, by a lock keyed on
 * the cube itself (JobLease 'cube-build:<table>'): the staging and computed
 * tables are named after the target, so two builds of one cube would fight over
 * them, and the two cadences overlap on today's partition by construction. A
 * cube per property therefore locks per property, and builds for different
 * properties still run at the same time.
 */
class CubeRebuildCli extends \OWA\Core\Controller\Cli {

    /**
     * How long the per-cube build lock outlives proof of life.
     *
     * Sized for ONE partition, not a whole run, because the run refreshes after
     * each. A million-row partition measured 162 seconds; thirty minutes is
     * generous against that and short enough that a crashed build does not
     * block the next one for a working day.
     */
    const BUILD_LEASE = 1800;

    function __construct( $params ) {

        // Rewrites a partition of a fact table, as the partition commands do.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->supportsPartitioning() ) {

            return $this->refuse(
                'This database driver does not support partitioning, and a build swaps '
              . 'a partition to publish a rebuild. Nothing to do.' );
        }

        $builder = new \OWA\Module\Base\Classes\Cube\Builder();
        $table = \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getTableName();

        if ( ! $db->isPartitioned( $table ) ) {

            return $this->refuse( sprintf(
                '%s is not partitioned. Run cmd=partition-init first.', $table ) );
        }

        $range = $this->resolveRange();

        if ( ! $range ) {

            return $this->refuse(
                'Could not read that range. Use from=yyyymmdd for that date through to now, '
              . 'days=N for the same relatively, or from=yyyymmdd to=yyyymmdd for a closed window.' );
        }

        $dry_run    = (bool) $this->getParam( 'dry-run' );
        $partitions = $builder->partitions( $range['from'], $range['to'] );

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

        /*
         * Say what the dates RESOLVED TO, not what was asked for. A build
         * rebuilds whole partitions -- the swap is the unit of work -- so
         * date=20260915 against monthly partitioning rebuilds all of September,
         * and reporting the requested day back would hide that entirely.
         */
        \OWA\Core\CoreAPI::notice( sprintf( '%d to %d covers %d partition(s) of %s.%s',
            $range['from'], $range['to'], count( $partitions ), $table,
            $dry_run ? ' Dry run.' : '' ) );

        foreach ( $partitions as $span ) {

            \OWA\Core\CoreAPI::notice( sprintf( '  %s spans %s to %s, and all of it is rebuilt.',
                $span['name'], $span['start'], $span['less_than'] ) );
        }

        /*
         * ONE BUILD PER CUBE AT A TIME.
         *
         * The staging and computed tables are named after the target, so two
         * builds of the same cube share them: one would drop the table the
         * other is filling. The row-count check would usually catch the result
         * and refuse the swap -- so the live table is not at risk -- but the
         * failure would read as builds mysteriously failing.
         *
         * And the two cadences this command is meant to run at OVERLAP by
         * construction: a frequent run over the current partition and an hourly
         * one over the trailing window both cover today. The scheduler's own
         * lease is keyed on the JOB NAME, which is what lets them run
         * concurrently, so it cannot be what stops them colliding.
         *
         * Keyed on the TARGET TABLE, not the command, so it is the thing they
         * actually contend for. A cube per property therefore locks per
         * property, and builds for different properties still run at the same
         * time.
         */
        $lock = new \OWA\Module\Base\Classes\JobLease( 'cube-build:' . $table );

        if ( ! $lock->acquire( self::BUILD_LEASE ) ) {

            return $this->refuse( sprintf(
                'Another build of %s is already running. Nothing to do -- a build is '
              . 'convergent, so the run in progress produces what this one would.', $table ) );
        }

        $failed = 0;

        foreach ( $partitions as $span ) {

            $result = $builder->rebuild( $span, $dry_run );

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf( "%s:\n%s", $span['name'], $result['sql'] ) );

                continue;
            }

            if ( ! $result['ok'] ) {

                $failed++;

                continue;
            }

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s rebuilt: %s rows, %d steps, %d values computed.',
                $span['name'], number_format( $result['rows'] ),
                $result['steps'], $result['computed'] ) );

            $this->reportSteps( $builder );

            /*
             * Proof of life per partition, not one lease for the whole run. A
             * backfill can legitimately run for hours -- days=365 is many
             * partitions -- and sizing one lease for that would leave a crashed
             * run blocking every later build until it expired. Refreshing means
             * the lease only has to outlive ONE partition.
             */
            $lock->refresh( self::BUILD_LEASE );
        }

        $lock->release();

        if ( $failed ) {

            return $this->fail( sprintf(
                '%d of %d partition(s) failed to rebuild. The live table is unchanged for those.',
                $failed, count( $partitions ) ) );
        }
    }

    /**
     * What each step did, when asked for it.
     *
     * Compute steps are the one place a build spends time outside SQL, so
     * their timings are the ones worth having -- a slow callback would
     * otherwise just look like the build getting slower.
     *
     * @param \OWA\Module\Base\Classes\Cube\Builder $builder
     * @return void
     */
    protected function reportSteps( $builder ) {

        if ( ! $this->getParam( 'steps' ) ) {

            return;
        }

        foreach ( $builder->accounting() as $entry ) {

            \OWA\Core\CoreAPI::notice( sprintf( '  %-34s %-7s %8s %9s  %s',
                $entry['step'], $entry['kind'],
                $entry['kind'] === 'compute' ? number_format( $entry['computed'] ) : '-',
                $entry['msec'] . 'ms',
                $entry['ok'] ? 'ok' : ( 'FAILED: ' . $entry['error'] ) ) );
        }
    }

    /**
     * The dates to rebuild, from whichever parameters were given.
     *
     * `from` with no `to` runs through to today rather than covering one day,
     * which is the whole point of it: a rebuild re-applies a correction or
     * picks up late arrivals, and both reach forward from a point in the past.
     *
     * @return array|null ['from','to'] as yyyymmdd
     */
    protected function resolveRange() {

        $today = (int) date( 'Ymd' );

        /*
         * Compared against null, not for truthiness. getParam() answers null
         * for a parameter that was not given, and `days=0` is a string that is
         * FALSY -- so a truthiness test reads "0" as "not supplied" and quietly
         * runs the default window instead of refusing a nonsense one.
         */
        $from = $this->getParam( 'from' );
        $to   = $this->getParam( 'to' );

        if ( $from !== null || $to !== null ) {

            $from = $from !== null ? $this->asDate( $from ) : $today;
            $to   = $to !== null ? $this->asDate( $to ) : $today;

            return ( $from && $to && $from <= $to )
                ? array( 'from' => $from, 'to' => $to ) : null;
        }

        $days = $this->getParam( 'days' );

        if ( $days !== null ) {

            if ( ! ctype_digit( (string) $days ) || (int) $days < 1 ) {

                return null;
            }

            return array(
                'from' => (int) date( 'Ymd', strtotime( '-' . ( (int) $days - 1 ) . ' days' ) ),
                'to'   => $today,
            );
        }

        /*
         * YESTERDAY AND TODAY, not today. A session whose last event is in the
         * previous partition and which closed after that partition's final
         * build has is_exit = 0 -- correctly, it was still open at the time --
         * and nothing would ever revisit it. Its exit page would be missing for
         * good.
         *
         * The read already looks back a day for session CONTEXT; this is the
         * other end, the terminal values settling.
         *
         * It is close to free because a range resolves to PARTITIONS: mid-month
         * under monthly granularity both dates land in one, and the second
         * partition appears exactly when there is a boundary to settle. Under
         * the daily granularity 2.8 wants, it is two every day, which is the
         * case this exists for.
         *
         * One day rather than one idle timeout because a day is the smallest
         * unit a partition is cut on, and session_length is half an hour.
         */
        return array(
            'from' => (int) date( 'Ymd', strtotime( '-1 day' ) ),
            'to'   => $today,
        );
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
