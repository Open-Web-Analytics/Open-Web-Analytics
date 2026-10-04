<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Keep a fixed window of data: add the periods ahead, drop the ones behind.
 *
 * This is the command to put in cron. Retention and the lead are two halves of
 * one policy -- "keep two years" only means anything if new periods keep being
 * created -- and running them separately invites the failure where the drop is
 * scheduled and the top-up is forgotten. That install keeps discarding history
 * while everything recent piles into the catch-all, which no cutoff can reach.
 * Here neither half can be scheduled without the other.
 *
 * The lead is added BEFORE anything is dropped. Extending is the safe half, so
 * a run that fails partway has still gained coverage rather than having
 * discarded history and then failed to create anywhere for new data to go.
 * Where the lead is refused for want of open files, it is retried after the
 * drop, since dropping is what frees them.
 *
 * HOW MUCH IS KEPT IS A SETTING, NOT AN ARGUMENT (Classes\Retention):
 * raw_retention_months for raw and every other event table, and each
 * Property's cube_retention_months for its cube. Both 0 by default, which
 * keeps everything: the lead is maintained and old periods are merged, never
 * dropped. The visitor store has its own fixed rule instead: a visitor not
 * seen for 14 months, with no event left in raw, is deleted
 * (expireVisitorStore()), so a cube rebuild never loses an acquisition.
 *
 * There is no keep=. A command-line window that differed from the settings
 * would delete what they say to keep, or name data already gone. A what-if is
 * a dry run, and a one-off prune is partition-drop:
 *
 *   cmd=partition-rotate                          apply the settings
 *   cmd=partition-rotate --dry-run                what the settings would do now
 *   cmd=partition-rotate --dry-run raw-months=12 cube-months=6 property=<id>
 *                                                 what those values would do
 *   cmd=partition-rotate months-ahead=6           a shorter lead
 *
 * Scheduled daily as rotate-partitions; nothing has to be added to cron.
 * After the drops it queues a rebuild for any cube whose window reaches
 * further back than it holds (Retention::backfills()).
 *
 * partition-init, partition-drop and partition-reorganize remain for the
 * one-off jobs: converting an installation that predates partitioning, a single
 * ad-hoc prune, changing granularity. Rotation is the routine.
 */
class PartitionRotateCli extends PartitionsCli {

    /**
     * How long this job's lock should be trusted without proof of life.
     *
     * A CRASH-RECOVERY TIMEOUT, not a runtime budget: on any normal path the
     * lock is released in a finally and this is never consulted. It decides only
     * how long after a process dies before another run may assume it is really
     * dead. Too short lets a second copy start alongside a run still working;
     * too long merely delays recovery, for which --force-release exists. Those
     * costs are asymmetric, so this errs long.
     *
     * WHAT MAKES A ROTATE SLOW IS DATA REWRITTEN, NOT PARTITIONS COUNTED.
     * Extending the lead is ONE `REORGANIZE PARTITION pmax INTO (...)` however
     * many periods it creates -- measured here at 1.52s to create 31 of them --
     * and its cost is proportional to what is sitting in the catch-all. Each
     * merge is a separate REORGANIZE over the partitions it combines. Drops are
     * not counted at all: DROP PARTITION discards a file and returns.
     *
     * Charging per partition created was wrong twice over, and produced a lease
     * of 2.6 hours for that 1.52-second extension.
     *
     * Row counts come from information_schema and are estimates, which is
     * appropriate: this is an estimate, and an exact COUNT(*) over a large
     * catch-all before every rotate would cost more than it informs.
     *
     * Read-only, and taken before the lock, so two dispatchers estimating at
     * once is harmless. partition-rotate cannot call heartbeat() to extend as it
     * goes -- it sits inside one blocking ALTER TABLE and never returns to PHP
     * mid-statement -- so this estimate has to stand on its own.
     *
     * @return int seconds
     */
    public function getJobLease() {

        $statements = 0;
        $rows       = 0;

        try {

            $db      = \OWA\Core\CoreAPI::dbSingleton();
            $through = \OWA\Core\Db::partitionLeadBoundary();
            $budget  = $this->factTableBudget();

            foreach ( $this->factTables( $this->getParam( 'table' ) ?: null ) as $table ) {

                if ( ! $db->isPartitioned( $table ) ) {

                    continue;
                }

                $granularity = $db->inferPartitionGranularity( $table ) ?: 'monthly';
                $sizes       = array();
                $catch_all   = 0;

                foreach ( $db->listPartitions( $table ) as $p ) {

                    $sizes[ $p['name'] ] = (int) $p['rows'];

                    if ( strtoupper( $p['less_than'] ) === OWA_DTD_PARTITION_MAXVALUE ) {

                        $catch_all = (int) $p['rows'];
                    }
                }

                // One statement, rewriting whatever has collected in the
                // catch-all -- which is nothing on a maintained installation and
                // everything on a neglected one.
                $extend = $db->extendPartitions( $table, $granularity, $through, true );

                if ( ! empty( $extend['planned'] ) ) {

                    $statements++;
                    $rows += $catch_all;
                }

                // One statement per merge, over the partitions it combines.
                $compact = $db->planPartitionCompaction( $table, $budget['limit'] );

                foreach ( (array) ( $compact['merges'] ?? array() ) as $merge ) {

                    $statements++;

                    foreach ( (array) ( $merge['names'] ?? array() ) as $name ) {

                        $rows += isset( $sizes[ $name ] ) ? $sizes[ $name ] : 0;
                    }
                }
            }

        } catch ( \Throwable $t ) {

            // An estimate that cannot be made must not stop the job running.
            // The base default errs long by design.
            return parent::getJobLease();
        }

        return self::leaseFor( $statements, $rows );
    }

    /**
     * The lease arithmetic, separated so it can be tested at sizes this
     * installation does not have.
     *
     * Two minutes per statement covers fixed overhead and lock acquisition.
     * Five minutes per million rows rewritten is around sixty times the
     * ~5s/million measured when this partitioning was built -- the margin a
     * crash-recovery timeout should carry. Scaled smoothly rather than rounded
     * up per million, so a few hundred rows do not cost the same as a million.
     * Half an hour floor, so a rotate with nothing to do still tolerates a slow
     * instance.
     *
     * @param int $statements  ALTER TABLE statements the run will issue
     * @param int $rows        rows those statements will rewrite
     * @return int seconds
     */
    public static function leaseFor( $statements, $rows ) {

        $seconds = ( (int) $statements * 120 )
                 + (int) round( max( 0, (int) $rows ) / 1000000 * 300 );

        return max( 1800, $seconds );
    }

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $dry_run = (bool) $this->getParam( 'dry-run' );
        $whatif  = $this->whatIf( $dry_run );

        if ( $whatif === false ) {

            return;
        }

        $months_ahead = $this->getParam( 'months-ahead' );
        $months_ahead = $months_ahead === null || $months_ahead === ''
            ? \OWA\Core\Db::PARTITION_MONTHS_AHEAD
            : max( 1, (int) $months_ahead );

        $granularity = $this->getParam( 'granularity' ) ?: null;

        if ( $granularity !== null && ! \OWA\Core\Db::isPartitionGranularity( $granularity ) ) {

            return $this->refuse( sprintf(
                'Unknown granularity "%s". Use one of: daily, quarter-month, half-month, monthly.', $granularity
            ) );
        }

        $tables = $this->factTables( $this->getParam( 'table' ) ?: null );

        if ( ! $tables ) {

            return $this->refuse( 'No fact tables found.' );
        }

        $through = \OWA\Core\Db::partitionLeadBoundary( $months_ahead );
        $budget  = $this->factTableBudget();

        $raw_months = array_key_exists( 'raw', $whatif ) ? $whatif['raw'] : \OWA\Module\Base\Classes\Retention::rawMonths();
        $raw_cutoff = \OWA\Module\Base\Classes\Retention::cutoff( $raw_months );

        \OWA\Core\CoreAPI::notice( $raw_cutoff
            ? sprintf(
                'Rotating: keeping %d month(s) of event data (nothing before %s)%s, each reporting cube '
              . 'to its own window, and %d month(s) of partitions ahead (through %s).',
                $raw_months, $raw_cutoff, array_key_exists( 'raw', $whatif ) ? ' [what-if]' : '',
                $months_ahead, $through )
            : sprintf(
                'Rotating: RETAINING ALL EVENT DATA (raw_retention_months is not set)%s, each reporting cube to its '
              . 'own window, and %d month(s) of partitions ahead (through %s). Old periods are merged, not '
              . 'deleted, to stay within the partition ceiling.',
                array_key_exists( 'raw', $whatif ) ? ' [what-if]' : '', $months_ahead, $through )
        );

        $rotated = 0;

        foreach ( $tables as $table ) {

            // Rotation maintains a table; it does not convert one. The first
            // partitioning rewrites the whole table, which is minutes of I/O on
            // a busy installation and has no place firing out of cron.
            if ( ! $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s is not partitioned; run partition-init once first. Skipping.', $table
                ) );

                continue;
            }

            $rotated++;

            /*
             * The daily part of the cube's lead, before the rest of the lead
             * work -- it is the same lead, so this is not a separate budget.
             * Merging frees a
             * month of partitions before anything consumes one, so a run peaks
             * near its starting count and a run that dies part way leaves the
             * table below where it started rather than over budget.
             */
            $this->mergeExpiredCubeDays( $table, $dry_run );

            $table_granularity = $granularity ?: ( $db->inferPartitionGranularity( $table ) ?: 'monthly' );

            // Ahead first: see the class comment.
            $extended = $this->extendTableLead( $table, $table_granularity, $through, $budget, $dry_run );

            // Coarsen old periods so partition count stays under the budget
            // without deleting anything. This is what allows a table to hold
            // decades of history within a modest open-file allowance.
            $this->compactTable( $table, $budget, $dry_run );

            $cutoff  = $this->cutoffFor( $table, $whatif, $raw_months );
            $dropped = $cutoff ? $this->dropOlderThan( $table, (string) $cutoff, $dry_run ) : 0;

            // Dropping frees the open files the lead was refused for, so a
            // refusal is worth revisiting once the old periods have gone.
            // Otherwise this run would leave behind the very state the command
            // exists to prevent: history dropped, nothing created ahead. Not
            // skipping the drop instead, which would deadlock -- the count
            // could never come down, so the lead could never fit.
            if ( ! $extended && $dropped && ! $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: retrying the lead now that %d partition(s) have gone.', $table, $dropped
                ) );

                $this->extendTableLead( $table, $table_granularity, $through, $budget, $dry_run );
            }

            /*
             * Carve last, because it is the only step that adds partitions
             * without freeing any -- and after the lead exists, so the month it
             * carves is one that extendTableLead() has already created.
             */
            $this->carveCubeMonths( $table, $budget, $dry_run );
        }

        if ( $rotated ) {

            $this->expireVisitorStore( $tables, $dry_run );
            $this->queueBackfills( $whatif, $dry_run );
        }

        // Skipping every table is not success. Left as 'ok', a scheduled rotate
        // on an installation that never ran partition-init would report a clean
        // history forever while doing nothing at all -- exactly the silent
        // failure this command exists to prevent, moved one level up. 'refused'
        // rather than 'failed' so the occurrence is still consumed and the job
        // is not retried every minute.
        if ( ! $rotated ) {

            return $this->refuse( sprintf(
                'Nothing to rotate: %s. Run cmd=partition-init once, in a maintenance '
              . 'window, and this will start doing its job.',
                count( $tables ) === 1
                    ? 'that table is not partitioned'
                    : 'no fact table is partitioned'
            ) );
        }
    }

    /**
     * The what-if values of a dry run, or false when the arguments were refused.
     *
     * raw-months, cube-months and property= describe a window to try, so they
     * are accepted only with --dry-run.
     *
     * @param bool $dry_run
     * @return array|false raw => months, cube => months, property => id
     */
    protected function whatIf( $dry_run ) {

        $out = array();

        if ( $this->getParam( 'keep' ) !== null && $this->getParam( 'keep' ) !== '' ) {

            $this->refuse( 'keep= is gone: retention is the raw_retention_months setting, and '
                . 'cube_retention_months per Property. To try a window, --dry-run raw-months=N; '
                . 'to prune now, cmd=partition-drop.' );

            return false;
        }

        foreach ( array( 'raw-months' => 'raw', 'cube-months' => 'cube' ) as $param => $key ) {

            $value = $this->getParam( $param );

            if ( $value === null || $value === '' ) {

                continue;
            }

            if ( ! ctype_digit( (string) $value ) ) {

                $this->refuse( sprintf( '%s must be a whole number of months; 0 keeps everything.', $param ) );

                return false;
            }

            if ( ! $dry_run ) {

                $this->refuse( sprintf(
                    '%s is a what-if and needs --dry-run. Retention is applied from the settings: '
                  . 'raw_retention_months, and cube_retention_months per Property.', $param ) );

                return false;
            }

            if ( ! array_key_exists( $key, $out ) ) {

                $out[ $key ] = (int) $value;
            }
        }

        $property = $this->getParam( 'property' );

        if ( $property !== null && $property !== '' ) {

            if ( ! $dry_run ) {

                $this->refuse( 'property= is a what-if and needs --dry-run, with cube-months=.' );

                return false;
            }

            $out['property'] = (string) $property;
        }

        if ( $out ) {

            \OWA\Core\CoreAPI::notice( 'What-if: nothing is changed, and the stored settings are not.' );
        }

        return $out;
    }

    /**
     * The cutoff one table is rotated to: its window from the settings, or the
     * what-if's.
     *
     * @param string $table
     * @param array  $whatif from whatIf()
     * @param int    $raw_months the raw window in force for this run
     * @return int|null yyyymmdd
     */
    protected function cutoffFor( $table, array $whatif, $raw_months ) {

        $property_id = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdFor( $table );

        if ( $property_id === '' ) {

            return \OWA\Module\Base\Classes\Retention::cutoff( $raw_months );
        }

        $cube = array_key_exists( 'cube', $whatif )
                && ( ! isset( $whatif['property'] ) || $whatif['property'] === (string) $property_id )
            ? $whatif['cube'] : null;

        return \OWA\Module\Base\Classes\Retention::cutoff(
            \OWA\Module\Base\Classes\Retention::cubeMonths( $property_id, $raw_months, $cube ) );
    }

    /**
     * Queue the rebuild each cube is owed by a window longer than it holds.
     *
     * @param array $whatif
     * @param bool  $dry_run
     * @return void
     */
    protected function queueBackfills( array $whatif, $dry_run ) {

        $cubes = array();

        if ( array_key_exists( 'cube', $whatif ) ) {

            foreach ( array_keys( \OWA\Module\Base\Classes\Cube\Cubes::existing() ) as $pid ) {

                if ( ! isset( $whatif['property'] ) || $whatif['property'] === (string) $pid ) {

                    $cubes[ (string) $pid ] = $whatif['cube'];
                }
            }
        }

        $owed = \OWA\Module\Base\Classes\Retention::backfills( $cubes,
            array_key_exists( 'raw', $whatif ) ? $whatif['raw'] : null );

        foreach ( $owed as $b ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: %s rebuilt from %d to %d (about %d event(s), roughly %d-%d minutes), so it covers its window.',
                $b['table'], $dry_run ? 'would be' : 'queued to be', $b['from'], $b['to'], $b['events'],
                $b['minutes'][0], $b['minutes'][1] ) );
        }

        if ( $owed && ! $dry_run ) {

            \OWA\Module\Base\Classes\Retention::enqueueBackfills( $owed );
        }
    }

    /**
     * Delete the visitor-store rows raw no longer refers to.
     *
     * The store's own rule, not a retention setting: a row goes once its
     * visitor was last seen more than 14 months ago AND raw holds none of their
     * events, because a cube rebuild reads acquisition from the store for every
     * event raw still has. On every run where raw is rotated, so a store left
     * behind by an earlier run catches up; while raw keeps everything nothing
     * is eligible, and the check is one loose index scan and an empty indexed
     * read. See Classes\VisitorExpiry.
     *
     * @param string[] $tables
     * @param bool     $dry_run
     * @return void
     */
    protected function expireVisitorStore( array $tables, $dry_run ) {

        $raw = \OWA\Module\Base\Classes\VisitorExpiry::rawTable();

        if ( ! in_array( $raw, $tables, true )
          || ! \OWA\Core\CoreAPI::dbSingleton()->isPartitioned( $raw ) ) {

            return;
        }

        $month = \OWA\Module\Base\Classes\VisitorExpiry::cutoff(
            \OWA\Module\Base\Classes\VisitorExpiry::oldestRawMonth(),
            \OWA\Module\Base\Classes\VisitorExpiry::now() );

        if ( $month === null ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s is empty, so the visitor store is left alone.', $raw ) );

            return;
        }

        $store = \OWA\Module\Base\Classes\VisitorExpiry::table();
        $since = sprintf( '%04d-%02d', intdiv( $month, 100 ), $month % 100 );
        $what  = sprintf( 'visitor(s) last seen before %s with no event left in %s', $since, $raw );

        if ( $dry_run ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: would delete %d %s.',
                $store, \OWA\Module\Base\Classes\VisitorExpiry::countExpired( $month ), $what ) );

            return;
        }

        $deleted = \OWA\Module\Base\Classes\VisitorExpiry::deleteAll(
            $month, function () { $this->heartbeat(); } );

        if ( $deleted === false ) {

            $this->fail( sprintf(
                'Deleting from %s was refused. Its rows stay until the next run.', $store ) );

            return;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s: deleted %d %s.', $store, $deleted, $what ) );
    }
}
