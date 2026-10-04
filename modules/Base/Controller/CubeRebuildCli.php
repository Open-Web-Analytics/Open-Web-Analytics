<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Rebuild the reporting cubes from owa_event_raw, a partition at a time.
 *
 *   cmd=cube-rebuild                     yesterday and today
 *   cmd=cube-rebuild from=20260901       that date through to today
 *   cmd=cube-rebuild from=20260901 to=20260930
 *   cmd=cube-rebuild days=3              the last 3 days, today included
 *   cmd=cube-rebuild property=<id>       just that Property's cube
 *   cmd=cube-rebuild days=3 --dry-run    print the statements, run nothing
 *   cmd=cube-rebuild steps=1             per-step timings and counts
 *
 * ONE CUBE PER PROPERTY. Raw is shared; owa_event_<property id> is not. With no
 * property= this covers every cube that exists plus every Property that has raw
 * rows in the range -- the second half being how a cube comes to exist at all:
 * it is created here, on a Property's first data, rather than when the Property
 * is created. Seventy-odd partitions and 2.7 seconds each is not worth spending
 * on the 216 Properties of this installation that have never collected
 * anything, and the alternative reading -- create it on the first event -- puts
 * that CREATE TABLE inside a beacon request.
 *
 * The cost of a cube that has stopped collecting is one empty partition per
 * run, and it is not optional: skipping it would leave rows in a partition that
 * raw no longer has.
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
 *   define( 'OWA_SCHEDULED_JOBS', array(
 *       'rebuild-cube-current' => array( 'command' => 'cube-rebuild',
 *           'schedule' => '0,15,30,45 * * * *' ),
 *       'rebuild-cube-window'  => array( 'command' => 'cube-rebuild',
 *           'schedule' => '@hourly', 'params' => array( 'days' => 3 ) ),
 *   ) );
 *
 * A plain array: Settings reads the constant only when is_array() holds, so a
 * serialize()d string is ignored and neither job is scheduled.
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
 * them, and the two cadences overlap on today's partition by construction.
 * Keying it on the cube makes it per Property, so two Properties still build at
 * the same time -- and a Property whose cube is locked is skipped rather than
 * failing the run, since the build in progress produces what this one would.
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

        $range = $this->resolveRange();

        if ( ! $range ) {

            return $this->refuse(
                'Could not read that range. Use from=yyyymmdd for that date through to now, '
              . 'days=N for the same relatively, or from=yyyymmdd to=yyyymmdd for a closed window.' );
        }

        $properties = $this->resolveProperties( $range );

        if ( $properties === null ) {

            return;
        }

        if ( ! $properties ) {

            return \OWA\Core\CoreAPI::notice(
                'No Property has a cube or has collected anything in that range. Nothing to do.' );
        }

        $dry_run = (bool) $this->getParam( 'dry-run' );
        $failed  = 0;
        $built   = 0;
        $locked  = 0;
        $skipped = 0;
        $stopped = array();

        /*
         * A ROUTINE RUN is one given no dates: what the scheduler runs. Only it
         * catches up; a range an operator names is built exactly as named.
         */
        $routine = $this->getParam( 'from' ) === null && $this->getParam( 'to' ) === null
            && $this->getParam( 'days' ) === null;

        foreach ( $properties as $property_id ) {

            $outcome = $this->rebuildProperty( $property_id, $range, $dry_run, $routine );

            $failed += $outcome['failed'];
            $built  += $outcome['built'];
            $locked += $outcome['locked'];
            $skipped += $outcome['skipped'] ?? 0;

            if ( ! empty( $outcome['stopped'] ) ) {

                $stopped[] = $outcome['stopped'];
            }
        }

        /*
         * Every cube in the run locked is a refusal; one of fifty is a notice
         * and the run carries on. The difference is whether anything was
         * possible -- with one Property named, or one cube in the
         * installation, "another build is already running" IS the outcome.
         */
        if ( $locked === count( $properties ) ) {

            return $this->refuse( sprintf(
                'Another build is already running for %s. Nothing to do -- a build is '
              . 'convergent, so the run in progress produces what this one would.',
                $locked === 1 ? 'that cube' : 'every cube in this run' ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d partition(s) across %d cube(s)%s; %d unchanged since their last build, skipped.',
            $built, count( $properties ) - $locked, $dry_run ? ' (dry run)' : ' rebuilt', $skipped ) );

        if ( $failed ) {

            /*
             * WHERE IT STOPPED FIRST, because the scheduler keeps this message
             * (last_message, 250 characters) and it is what the cube status
             * screen shows as the reason. The next scheduled run starts again
             * from that partition.
             */
            return $this->fail( sprintf( '%s. %d cube(s) stopped; the next run resumes there.',
                implode( '; ', $stopped ), count( $stopped ) ) );
        }
    }

    /**
     * Whose cubes this run covers.
     *
     * EVERY EXISTING CUBE, PLUS EVERY PROPERTY WITH RAW ROWS IN THE RANGE.
     *
     * The second half is §2.27.3: a cube is created on first data, by a build.
     * The first half is why it is a union rather than just the collecting set
     * -- a Property that has stopped collecting still has to be rebuilt, or a
     * partition keeps rows that raw no longer has. A build is convergent, so a
     * cube with nothing to say costs one empty partition and 235ms.
     *
     * @param array $range from resolveRange()
     * @return string[]|null  property ids, or null having already refused
     */
    protected function resolveProperties( array $range ) {

        $cubes = \OWA\Module\Base\Classes\Cube\Cubes::existing();
        $only  = $this->getParam( 'property' );

        if ( $only !== null ) {

            $only = trim( (string) $only );

            if ( ! ctype_digit( $only ) ) {

                $this->refuse( 'property= takes a Property id.' );

                return null;
            }

            /*
             * Named explicitly, so it is built whether or not it has collected
             * anything -- that is what a backfill of one Property looks like,
             * and refusing it would make the command useless for the one case
             * an operator reaches for it.
             */
            return array( $only );
        }

        $collecting = \OWA\Module\Base\Classes\Cube\Cubes::collecting(
            $range['from'], $range['to'] );

        if ( $collecting['orphan_rows'] ) {

            /*
             * Said once, because it is a real condition with no cube to put it
             * in: either a profile was deleted while its rows remain, or a
             * beacon quoted a site id nothing issued. Neither is this
             * command's to fix, and neither should be silent.
             */
            \OWA\Core\CoreAPI::notice( sprintf(
                '%s raw row(s) in that range belong to a site id no Property claims, '
              . 'so they are in no cube.', number_format( $collecting['orphan_rows'] ) ) );
        }

        /*
         * AND EVERY PROPERTY THAT HAS COLLECTED BUT HAS NO CUBE YET, whatever
         * the range. A fresh install that collected before cron was set up and
         * then went quiet has raw rows only outside this window; left to
         * collecting() it would get no cube until new traffic landed inside a
         * build's range. Its first build then reads back to its first day --
         * see buildUnderLock().
         */
        $properties = array_unique( array_merge(
            array_keys( $cubes ), $collecting['properties'],
            \OWA\Module\Base\Classes\Cube\Cubes::awaitingFirstBuild() ) );

        sort( $properties, SORT_STRING );

        return $properties;
    }

    /**
     * Build one Property's cube over the range.
     *
     * @param string $property_id
     * @param array  $range
     * @param bool   $dry_run
     * @return array ['built' => int, 'failed' => int]
     */
    protected function rebuildProperty( $property_id, array $range, $dry_run, $routine = false ) {

        $none  = array( 'built' => 0, 'failed' => 0, 'locked' => 0 );
        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property_id );

        /*
         * ONE BUILD PER CUBE AT A TIME, AND THE LOCK COMES FIRST.
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
         * Taken before the cube is created, not after, because CREATING it is
         * the one step two runs could both do -- a Property's first data is
         * exactly when two cadences are most likely to meet on it.
         *
         * Keyed on the CUBE, not the command, so it is the thing they actually
         * contend for -- which makes it per Property for free, and lets two
         * Properties build at the same time.
         */
        $lock = new \OWA\Module\Base\Classes\JobLease( 'cube-build:' . $table );

        if ( ! $lock->acquire( self::BUILD_LEASE ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Another build of %s is already running; skipped. A build is convergent, '
              . 'so the run in progress produces what this one would.', $table ) );

            return array( 'built' => 0, 'failed' => 0, 'locked' => 1, 'skipped' => 0, 'stopped' => '' );
        }

        try {

            return $this->buildUnderLock( $property_id, $table, $range, $dry_run, $lock, $routine );

        } finally {

            $lock->release();
        }
    }

    /**
     * The build itself, with this cube's lock already held.
     *
     * @param string   $property_id
     * @param string   $table
     * @param array    $range
     * @param bool     $dry_run
     * @param \OWA\Module\Base\Classes\JobLease $lock
     * @param bool     $routine  no dates were named: catch up if the cube is behind
     * @return array ['built','failed','locked','skipped','stopped']
     */
    protected function buildUnderLock( $property_id, $table, array $range, $dry_run, $lock, $routine = false ) {

        $none    = array( 'built' => 0, 'failed' => 0, 'locked' => 0, 'skipped' => 0, 'stopped' => '' );
        $bad     = array( 'built' => 0, 'failed' => 1, 'locked' => 0, 'skipped' => 0,
                          'stopped' => sprintf( '%s could not be prepared', $table ) );
        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $created = false;

        if ( ! $db->tableExists( $table ) ) {

            $earliest = \OWA\Module\Base\Classes\Cube\Cubes::earliestDay( $property_id );

            // No further back than the cube's retention window: the next rotate
            // would only drop what was built before it.
            $window = \OWA\Module\Base\Classes\Retention::cutoff(
                \OWA\Module\Base\Classes\Retention::cubeMonths( $property_id ) );

            if ( $earliest && $window && $earliest < $window ) {

                $earliest = $window;
            }

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s does not exist yet and would be created%s.', $table,
                    $earliest && $earliest < $range['from']
                        ? sprintf( ', then built from %d, its first day in raw', $earliest ) : '' ) );

                return $none;
            }

            /*
             * THE BUILD CREATES THE CUBE, not ingest and not the Property
             * form. A cube is seventy-odd partitions and about 2.7 seconds, so
             * creating one for every Property that might collect something
             * spends all of it on Properties that never will -- and putting the
             * creation on the beacon path would put that CREATE TABLE inside a
             * request, with every concurrent first event of the Property racing
             * to do it. A build already runs on a schedule and already holds
             * this cube's lock.
             */
            if ( ! \OWA\Module\Base\Classes\Cube\Cubes::create( $property_id ) ) {

                \OWA\Core\CoreAPI::error( sprintf(
                    'Could not create %s. Nothing was built for Property %s.',
                    $table, $property_id ) );

                return $bad;
            }

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s created: Property %s has collected its first data.', $table, $property_id ) );

            $created = true;

            /*
             * A NEW CUBE STARTS FROM THE PROPERTY'S FIRST DAY IN RAW, not from
             * the window a routine rebuild covers. Everything collected before
             * the cube existed -- typically before cron was installed, since
             * the scheduler is what creates cubes -- belongs in it, and nothing
             * else would ever put it there: later rebuilds cover yesterday and
             * today.
             *
             * The cube is created with its lead only, so its first partition
             * starts this month; older days need dated partitions first, or
             * partitions() would find nothing covering them.
             */
            if ( $earliest && $earliest < $range['from'] ) {

                $range = $this->reachBackForFirstBuild( $table, $earliest, $range );
            }
        }

        /*
         * NAMED DATES REACH BACK TOO. A cube has dated partitions only as far
         * as something gave it them -- its lead, or a first build's reach-back
         * to the first day in raw -- and partitions() can only build what a
         * partition covers. A run asking for older dates found none and built
         * just the partitions that existed, saying "covers 3 partition(s)" for
         * 15 years of migrated history and nothing more. So the cube is given
         * partitions back to the start of what was asked for, or the first day
         * raw holds, whichever is later; nothing is added where they exist.
         */
        if ( ! $dry_run && ! $routine ) {

            $first = isset( $earliest ) ? $earliest : \OWA\Module\Base\Classes\Cube\Cubes::earliestDay( $property_id );

            if ( $first ) {

                $want   = max( (int) $range['from'], (int) $first );
                $result = $this->ensurePartitionsFrom( $table, $want );

                if ( ! $result['covered'] && ! $result['added'] ) {

                    \OWA\Core\CoreAPI::error( sprintf(
                        '%s: could not add partitions back to %d, so days before its oldest partition are not built.',
                        $table, $want ) );
                }
            }
        }

        if ( ! $db->isPartitioned( $table ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                '%s is not partitioned, and a build publishes by swapping a partition. '
              . 'Run cmd=partition-init table=%s first.', $table, $table ) );

            return $bad;
        }

        /*
         * BRING THE REGISTERED COLUMNS UP TO DATE FIRST, under the lock this
         * run already holds.
         *
         * Registering a custom dimension records a row and nothing else -- the
         * ALTER is a full table rebuild and cannot happen inside the request
         * that asked for it. Doing it here means it can never be forgotten:
         * whatever else is or is not scheduled, a cube that gets built gets its
         * columns. And it MUST be under this lock rather than beside it, since
         * an ALTER landing between the staging copy and the swap makes
         * EXCHANGE PARTITION refuse the pair.
         *
         * A dry run reports and changes nothing, so it is skipped -- which
         * means a dry run of a Property with a pending registration shows the
         * statement without that column, correctly.
         */
        if ( ! $dry_run ) {

            $this->reconcileDimensions( $property_id, $table );
        }

        $builder = new \OWA\Module\Base\Classes\Cube\Builder( $property_id );

        /*
         * CATCH UP AFTER A GAP. A routine run covers yesterday and today, so
         * an outage, or a run that stopped at a failure, would otherwise leave
         * the days in between built by no one. Nothing records how far a build
         * got; Builder::catchUpFrom() reads it off the partitions. A cube this
         * run created has already reached back to its first day.
         */
        if ( $routine && ! $created ) {

            $catch_up = $builder->catchUpFrom( $range['from'] );

            if ( $catch_up !== null ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s is behind: partitions from %d were not settled, so this run starts there.',
                    $table, $catch_up ) );

                $range['from'] = $catch_up;
            }
        }

        $partitions = $builder->partitions( $range['from'], $range['to'] );

        if ( ! $partitions ) {

            /*
             * A dated partition covering the range is missing, which means the
             * rows are in the catch-all or nowhere. Refused rather than
             * rebuilt: exchanging into pmax would put rows in a partition that
             * does not describe them.
             */
            \OWA\Core\CoreAPI::error( sprintf(
                'No dated partition of %s covers %d to %d. Run cmd=partition-rotate to extend the lead.',
                $table, $range['from'], $range['to'] ) );

            return $bad;
        }

        /*
         * Say what the dates RESOLVED TO, not what was asked for. A build
         * rebuilds whole partitions -- the swap is the unit of work -- so
         * date=20260915 against monthly partitioning rebuilds all of September,
         * and reporting the requested day back would hide that entirely.
         */
        \OWA\Core\CoreAPI::notice( sprintf( '%s: %d to %d covers %d partition(s).%s',
            $table, $range['from'], $range['to'], count( $partitions ),
            $dry_run ? ' Dry run.' : '' ) );

        $outcome = $none;

        /*
         * OLDEST FIRST, AND STOP AT THE FIRST FAILURE. partitions() returns
         * them in order; stopping keeps what a run leaves built contiguous,
         * which is what lets catchUpFrom() read the next start off the
         * partitions -- a run that carried on past a failure would stamp newer
         * partitions settled and leave the failed one behind them for good.
         */
        $raw_from = $this->rawCoversFrom();

        foreach ( $partitions as $span ) {

            /*
             * THE CUBE HOLDS DAYS RAW NO LONGER HAS: raw's partitions were
             * dropped (partition-drop only=raw) while the cube kept them. A
             * rebuild replaces the partition with what raw has, which would
             * lose those days, so the partition is left as built.
             *
             * Asked of the cube's own rows, not of partition boundaries. Raw's
             * oldest partition holds everything below it, so a table that never
             * had older partitions and one whose older partitions were dropped
             * look the same; only what the cube holds tells them apart.
             */
            if ( $raw_from && (int) $span['start'] < $raw_from
                 && $this->holdsDaysBefore( $table, $span, $raw_from ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s %s: kept as built. It holds days before %d, which raw no longer has, so a rebuild would lose them.',
                    $table, $span['name'], $raw_from ) );

                $outcome['skipped']++;

                continue;
            }

            /*
             * NOTHING TO BUILD, NOTHING TO SWAP: empty in raw for this
             * Property and empty in the cube. A dormant cube's catch-up walks
             * through dozens of these, and each would otherwise be a staging
             * table and an EXCHANGE PARTITION on a shared server.
             */
            if ( ! $dry_run && $builder->builtAt( $span ) === null && ! $builder->hasRaw( $span ) ) {

                $outcome['skipped']++;

                continue;
            }

            /*
             * NOTHING HAS ARRIVED SINCE IT WAS BUILT, and its sessions had all
             * closed by then (Builder::isCurrent()). What lets rebuild-cube run
             * every few minutes: a quiet Property costs one scan per partition
             * and no swap. A routine run only -- a range an operator names is
             * built as named, which is how a build is forced.
             */
            if ( ! $dry_run && $routine && $builder->isCurrent( $span ) ) {

                $outcome['skipped']++;

                continue;
            }

            $result = $builder->rebuild( $span, $dry_run );

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf( "%s %s:\n%s",
                    $table, $span['name'], $result['sql'] ) );

                $outcome['built']++;

                continue;
            }

            if ( ! $result['ok'] ) {

                $outcome['failed']++;
                $outcome['stopped'] = sprintf( '%s stopped at %s: %s', $table, $span['name'],
                    $result['error'] !== '' ? $result['error'] : 'see the error log' );

                \OWA\Core\CoreAPI::error( $outcome['stopped'] . '. Later partitions were not built.' );

                break;
            }

            $outcome['built']++;

            \OWA\Core\CoreAPI::notice( sprintf(
                '  %s rebuilt: %s rows, %d steps, %d values computed.',
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

        return $outcome;
    }

    /**
     * The oldest day raw holds, or null when raw is not partitioned or has
     * no partitions: the start of its oldest partition, or the oldest row in it
     * when that is earlier (the oldest partition holds everything below it).
     *
     * @return int|null yyyymmdd
     */
    protected function rawCoversFrom() {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        if ( ! $db->isPartitioned( $raw ) ) {

            return null;
        }

        if ( $this->raw_covers_from !== false ) {

            return $this->raw_covers_from;
        }

        $spans = $db->getPartitionSpans( $raw );
        $from  = $spans && ctype_digit( (string) $spans[0]['start'] ) ? (int) $spans[0]['start'] : null;

        // The oldest partition holds everything below its boundary, so a row
        // can sit before the start its name gives. Raw covers that row too.
        if ( $from ) {

            $row = (array) $db->get_row( sprintf( 'SELECT MIN(yyyymmdd) AS d FROM %s PARTITION (%s)',
                $raw, $spans[0]['name'] ) );

            if ( ! empty( $row['d'] ) && (int) $row['d'] < $from ) {

                $from = (int) $row['d'];
            }
        }

        return $this->raw_covers_from = $from;
    }

    /**
     * Whether a cube partition holds any day before $day.
     *
     * @param string $table
     * @param array  $span
     * @param int    $day yyyymmdd
     * @return bool
     */
    protected function holdsDaysBefore( $table, array $span, $day ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT 1 AS present FROM %s PARTITION (%s) WHERE yyyymmdd < %d LIMIT 1',
            $table, $span['name'], (int) $day ) );

        return is_array( $row ) && ! empty( $row );
    }

    /** @var int|null|false rawCoversFrom(), once per run of the command; false = not read yet */
    protected $raw_covers_from = false;

    /**
     * Dated partitions on $table back to $day, adding only what is missing.
     *
     * @param string $table
     * @param int    $day yyyymmdd
     * @return array extendPartitionsBack()'s result: covered, added, ...
     */
    protected function ensurePartitionsFrom( $table, $day ) {

        // As partition-rotate reads them, so the next rotation finds the table in shape.
        $detail_months = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_detail_months' )
            ?: \OWA\Core\Db::PARTITION_DETAIL_MONTHS;
        $limit         = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_max_partitions' )
            ?: \OWA\Core\Db::PARTITION_COUNT_LIMIT;

        return \OWA\Core\CoreAPI::dbSingleton()->extendPartitionsBack(
            $table, (string) (int) $day, $detail_months, $limit );
    }

    /**
     * Give a just-created cube dated partitions back to $earliest, and widen
     * the range to start there.
     *
     * If the partitions cannot be added, the range is left as it was: the
     * routine window still builds, and the error says what was not.
     *
     * @param string $table
     * @param int    $earliest yyyymmdd
     * @param array  $range    ['from','to']
     * @return array the range to build
     */
    protected function reachBackForFirstBuild( $table, $earliest, array $range ) {

        $result = $this->ensurePartitionsFrom( $table, $earliest );

        if ( ! $result['covered'] && ! $result['added'] ) {

            \OWA\Core\CoreAPI::error( sprintf(
                '%s: could not add partitions back to %d, so only %d to %d is built. '
              . 'Run cmd=cube-rebuild from=%d once the partitions exist.',
                $table, $earliest, $range['from'], $range['to'], $earliest ) );

            return $range;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s: first build, so it reads back to %d, the first day in raw.', $table, $earliest ) );

        $range['from'] = (int) $earliest;

        return $range;
    }

    /**
     * Apply whatever has been registered or de-registered since the last run.
     *
     * One ALTER however much has accumulated, and a failure here does NOT stop
     * the build: a column that could not be added is a dimension that is not
     * filled yet, where refusing to build would turn one bad registration into
     * a Property with no reporting at all.
     *
     * @param string $property_id
     * @param string $table
     * @return void
     */
    protected function reconcileDimensions( $property_id, $table ) {

        $result = \OWA\Module\Base\Classes\Cube\Dimensions::reconcile( $property_id );

        if ( $result['added'] || $result['dropped'] ) {

            \OWA\Core\CoreAPI::notice( sprintf( '%s: custom dimensions applied -- %s%s%s.',
                $table,
                $result['added'] ? 'added ' . implode( ', ', $result['added'] ) : '',
                $result['added'] && $result['dropped'] ? '; ' : '',
                $result['dropped'] ? 'dropped ' . implode( ', ', $result['dropped'] ) : '' ) );
        }

        foreach ( $result['skipped'] as $column => $why ) {

            \OWA\Core\CoreAPI::error( sprintf(
                '%s: %s could not be added, so it stays unfilled. %s', $table, $column, $why ) );
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
