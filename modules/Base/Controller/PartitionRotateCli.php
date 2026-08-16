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

                \OWA\Core\CoreAPI::notice(
                    'keep must be a number of months to retain, such as keep=24. '
                  . 'Omit it entirely to retain everything.'
                );

                return;
            }

            // Expressed as a period rather than a date on purpose. A fixed date
            // in a scheduled job stops pruning the moment it is passed, and does
            // so silently.
            $cutoff = $this->resolveCutoff( (int) $keep . 'months' );

            if ( ! $cutoff ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Could not work out a cutoff for keep=%s.', $keep ) );

                return;
            }
        }

        $months_ahead = $this->getParam( 'months-ahead' );
        $months_ahead = $months_ahead === null || $months_ahead === ''
            ? \OWA\Core\Db::PARTITION_MONTHS_AHEAD
            : max( 1, (int) $months_ahead );

        $granularity = $this->getParam( 'granularity' ) ?: null;

        if ( $granularity !== null && ! \OWA\Core\Db::isPartitionGranularity( $granularity ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Unknown granularity "%s". Use one of: quarter-month, half-month, monthly.', $granularity
            ) );

            return;
        }

        $tables = $this->factTables( $this->getParam( 'table' ) ?: null );

        if ( ! $tables ) {

            \OWA\Core\CoreAPI::notice( 'No fact tables found.' );

            return;
        }

        $through = \OWA\Core\Db::partitionLeadBoundary( $months_ahead );
        $budget  = $this->partitionLimit( count( $tables ) );

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
    }
}
