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
 *   cmd=partition-rotate                          retain everything; merge old
 *                                                 periods to stay within budget
 *   cmd=partition-rotate keep=24                  keep two years, twelve ahead
 *   cmd=partition-rotate keep=12 months-ahead=6   a shorter lead
 *   cmd=partition-rotate keep=36 --dry-run        report the plan, change nothing
 *
 * Run it monthly:
 *   0 4 1 * *  php /path/to/owa/cli.php cmd=partition-rotate keep=24
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
     * dead.
     *
     * Derived from the work actually PLANNED -- the partitions this run will
     * create and the groups it will merge, each of which is an ALTER TABLE.
     * Counting the partitions a table already HAS would be wrong and was: a
     * routine rotate with nothing to do would have asked for hours because the
     * tables happen to hold a few hundred partitions between them, when the run
     * takes seconds.
     *
     * Drops are deliberately not counted. DROP PARTITION discards a file and
     * returns; it is not what makes a rotate slow.
     *
     * Uncapped on purpose. Too long merely delays recovery, and
     * `schedule-run --force-release` is there for that; too short lets a second
     * copy start alongside a run that is still working, which is the direction
     * worth avoiding. partition-rotate cannot call heartbeat() to extend as it
     * goes -- it sits inside one blocking ALTER TABLE and never returns to PHP
     * mid-statement -- so the estimate has to stand on its own.
     *
     * Read-only, and taken before the lock, so two dispatchers estimating at
     * once is harmless.
     *
     * @return int seconds
     */
    public function getJobLease() {

        $operations = 0;

        try {

            $db      = \OWA\Core\CoreAPI::dbSingleton();
            $through = \OWA\Core\Db::partitionLeadBoundary();
            $budget  = $this->factTableBudget();

            foreach ( $this->factTables( $this->getParam( 'table' ) ?: null ) as $table ) {

                if ( ! $db->isPartitioned( $table ) ) {

                    continue;
                }

                $granularity = $db->inferPartitionGranularity( $table ) ?: 'monthly';

                $extend = $db->extendPartitions( $table, $granularity, $through, true );
                $compact = $db->planPartitionCompaction( $table, $budget['limit'] );

                $operations += (int) ( $extend['planned'] ?? 0 );
                $operations += count( $compact['merges'] ?? array() );
            }

        } catch ( \Throwable $t ) {

            // An estimate that cannot be made must not stop the job running.
            // The base default errs long by design.
            return parent::getJobLease();
        }

        // Five minutes an operation is generous for a REORGANIZE, which is the
        // right direction for a timeout whose only cost when too large is a
        // slower recovery. Half an hour floor, so a rotate with nothing to do
        // still tolerates a slow instance.
        return max( 1800, $operations * 300 );
    }

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $dry_run = (bool) $this->getParam( 'dry-run' );
        $keep    = $this->getParam( 'keep' );

        // keep is optional. Without it nothing is ever deleted: the lead is
        // maintained and old periods are merged into coarser ones to stay within
        // the open-file budget. That is a complete, safe policy for an
        // installation that wants to retain everything -- partition count stops
        // being a reason to discard data.
        $cutoff = null;

        if ( $keep !== null && $keep !== '' ) {

            if ( ! ctype_digit( (string) $keep ) || (int) $keep < 1 ) {

                return $this->refuse(
                    'keep must be a number of months to retain, such as keep=24. '
                  . 'Omit it entirely to retain everything.'
                );
            }

            // Expressed as a period rather than a date on purpose. A fixed date
            // in a scheduled job stops pruning the moment it is passed, and does
            // so silently.
            $cutoff = $this->resolveCutoff( (int) $keep . 'months' );

            if ( ! $cutoff ) {

                return $this->refuse( sprintf( 'Could not work out a cutoff for keep=%s.', $keep ) );

                return;
            }
        }

        $months_ahead = $this->getParam( 'months-ahead' );
        $months_ahead = $months_ahead === null || $months_ahead === ''
            ? \OWA\Core\Db::PARTITION_MONTHS_AHEAD
            : max( 1, (int) $months_ahead );

        $granularity = $this->getParam( 'granularity' ) ?: null;

        if ( $granularity !== null && ! \OWA\Core\Db::isPartitionGranularity( $granularity ) ) {

            return $this->refuse( sprintf(
                'Unknown granularity "%s". Use one of: quarter-month, half-month, monthly.', $granularity
            ) );
        }

        $tables = $this->factTables( $this->getParam( 'table' ) ?: null );

        if ( ! $tables ) {

            return $this->refuse( 'No fact tables found.' );
        }

        $through = \OWA\Core\Db::partitionLeadBoundary( $months_ahead );
        $budget  = $this->factTableBudget();

        \OWA\Core\CoreAPI::notice( $cutoff
            ? sprintf(
                'Rotating: keeping %s month(s) of data (nothing before %s), and %d month(s) of '
              . 'partitions ahead (through %s).',
                $keep, $cutoff, $months_ahead, $through )
            : sprintf(
                'Rotating: RETAINING EVERYTHING (no keep given, so nothing will be dropped), '
              . 'and %d month(s) of partitions ahead (through %s). Old periods are merged, not '
              . 'deleted, to stay within the partition budget.',
                $months_ahead, $through )
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

            $table_granularity = $granularity ?: ( $db->inferPartitionGranularity( $table ) ?: 'monthly' );

            // Ahead first: see the class comment.
            $extended = $this->extendTableLead( $table, $table_granularity, $through, $budget, $dry_run );

            // Coarsen old periods so partition count stays under the budget
            // without deleting anything. This is what allows a table to hold
            // decades of history within a modest open-file allowance.
            $this->compactTable( $table, $budget, $dry_run );

            $dropped = $cutoff ? $this->dropOlderThan( $table, $cutoff, $dry_run ) : 0;

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
        }

        // Skipping every table is not success. Left as 'ok', a scheduled rotate
        // on an installation that never ran partition-init would report a clean
        // history forever while doing nothing at all -- exactly the silent
        // failure this command exists to prevent, moved one level up. 'refused'
        // rather than 'failed' so the occurrence is still consumed and the job
        // is not retried every minute.
        if ( ! $rotated ) {

            return $this->refuse( sprintf(
                'Nothing to rotate: %s not partitioned. Run cmd=partition-init once, in a '
              . 'maintenance window, and this will start doing its job.',
                count( $tables ) === 1 ? 'that table is' : 'no fact table is'
            ) );
        }
    }
}
