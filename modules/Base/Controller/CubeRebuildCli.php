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
 * It is safe to register only because the command refuses cheaply when no site
 * collects into v2, which is every installation until one turns it on. Without
 * that guard this would do DDL on every run everywhere to rebuild an empty
 * partition.
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
 * The lock is keyed on the job NAME, so those two serialise separately and a
 * long window rebuild does not hold up the current one.
 */
class CubeRebuildCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        // Rewrites a partition of a fact table, as the partition commands do.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function action() {

        /*
         * NOTHING COLLECTING INTO v2 MEANS NOTHING TO BUILD, and saying so is
         * the whole reason this can be a registered job at all. v2 collection is
         * off until a site turns it on, so on most installations a build would
         * rebuild an empty partition -- and a rebuild is not free even then: it
         * creates a staging table, strips its partitioning and exchanges a
         * partition, which is DDL on every run.
         *
         * refuse(), not fail(): the scheduler counts a refusal as satisfying the
         * occurrence, so the job goes quiet instead of retrying forever.
         */
        if ( ! $this->anySiteCollectsV2() ) {

            return $this->refuse(
                'No site has v2 collection turned on, so owa_event_raw has nothing in it to '
              . 'build from. Nothing to do.' );
        }

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
        }

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
     * Is any site collecting into owa_event_raw?
     *
     * A seam as much as a check: a test can answer it without a site table, and
     * the scheduled job can be exercised on an installation that has none.
     *
     * @return bool
     */
    protected function anySiteCollectsV2() {

        foreach ( \OWA\Core\CoreAPI::getSitesList() as $site ) {

            $id = is_array( $site )
                ? ( isset( $site['site_id'] ) ? $site['site_id'] : null )
                : ( isset( $site->site_id ) ? $site->site_id : null );

            if ( $id && \OWA\Module\Base\Handler\EventRawHandlers::isEnabledForSite( $id ) ) {

                return true;
            }
        }

        return false;
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
