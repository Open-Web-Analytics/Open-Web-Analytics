<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Partition the fact tables, and keep a lead of future partitions ahead of them.
 *
 * A one-time conversion for an installation that predates partitioning. Running
 * it again does nothing: a table that is already partitioned is reported and
 * skipped, with a pointer to the command that does the job being asked for.
 *
 * That keeps one job per command. Maintaining the lead and coarsening old
 * periods is partition-rotate; changing granularity is partition-reorganize.
 *
 * The layout it creates is tiered: whole calendar years over old history, and
 * the table's own granularity over recent history and the lead. A flat layout
 * over a long-running installation asks for one partition per month of history,
 * which no open-file budget accommodates.
 *
 * Granularity is inferred from each table unless given, so a table converted
 * with partition-reorganize keeps extending at the granularity it was given
 * rather than reverting to monthly on the next scheduled run.
 *
 * It is a command rather than an update because the first run rewrites every
 * fact table: on a busy installation that is minutes of I/O, and it should be
 * run deliberately rather than firing inside an upgrade nobody scheduled.
 * Later runs only split an empty catch-all and are cheap.
 *
 *   cmd=partition-init                          partition every fact table
 *   cmd=partition-init granularity=half-month   override it
 *   cmd=partition-init months-ahead=6           keep six months ahead, not twelve
 *   cmd=partition-init table=owa_request        one table
 *   cmd=partition-init --dry-run                report the plan, change nothing
 *
 */
class PartitionInitCli extends PartitionsCli {

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db          = \OWA\Core\CoreAPI::dbSingleton();
        // Left unset, each table keeps whatever it is already using. Setting
        // granularity is partition-reorganize's job; this command maintains
        // what it finds, so a scheduled run does not quietly undo a choice.
        $granularity = $this->getParam( 'granularity' ) ?: null;
        $dry_run     = (bool) $this->getParam( 'dry-run' );

        $months_ahead = $this->getParam( 'months-ahead' );
        $months_ahead = $months_ahead === null || $months_ahead === ''
            ? \OWA\Core\Db::PARTITION_MONTHS_AHEAD
            : max( 1, (int) $months_ahead );

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

        $budget  = $this->partitionLimit( count( $tables ) );
        $already = 0;

        $through = \OWA\Core\Db::partitionLeadBoundary( $months_ahead );

        \OWA\Core\CoreAPI::notice( sprintf(
            'Keeping %d month(s) of partitions ahead: through %s.', $months_ahead, $through
        ) );

        foreach ( $tables as $table ) {

            // An unpartitioned table has nothing to infer from, so it starts
            // monthly unless told otherwise.
            $table_granularity = $granularity
                ?: ( $db->inferPartitionGranularity( $table ) ?: 'monthly' );

            // Already partitioned: top the lead back up rather than skipping.
            // This is the case on every run after the first, and is what makes
            // repeated runs converge on the same layout instead of doing
            // nothing.
            // init converts; it does not maintain. A table that is already
            // partitioned is left entirely alone, because every way of "topping
            // it up" here duplicates another command: extending the lead and
            // coarsening the tail is exactly partition-rotate, and applying a
            // granularity would convert only the periods being added, leaving
            // the rest as they were -- a silent, partial reorganisation.
            //
            // One job each: init sets a table up, rotate keeps it in shape,
            // reorganize changes its granularity.
            if ( $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s is already partitioned (%s, %d periods). Nothing to do here -- use '
                  . 'cmd=partition-rotate to extend the lead and prune, or '
                  . 'cmd=partition-reorganize granularity=... to change its granularity.',
                    $table,
                    $db->inferPartitionGranularity( $table ) ?: 'unrecognised granularity',
                    count( $db->getPartitionSpans( $table ) )
                ) );

                $already++;

                continue;
            }

            // Cover the data that is there, and the lead beyond it. An empty
            // table still gets the current period and the lead, so that writes
            // have somewhere to go other than the catch-all.
            //
            // The minimum ignores malformed dates. Installations carry rows
            // with yyyymmdd of 0, and a plain MIN() returns that, which would
            // put the first boundary at today and collapse every year of
            // history into one partition. Those rows still land in the first
            // partition, since RANGE has no lower bound -- they simply must not
            // decide where the partitioning starts.
            $row   = $db->get_row( sprintf(
                'SELECT MIN(yyyymmdd) AS mn, MAX(yyyymmdd) AS mx FROM %s WHERE yyyymmdd > 19700101', $table
            ) );
            $today = date( 'Ymd' );

            $min = ( $row && $row['mn'] ) ? $row['mn'] : $today;

            // Coarse over old history, fine over the detail window and the
            // lead. A flat layout over a long-running installation asks for one
            // partition per month of history and simply cannot be created.
            $ranges = \OWA\Core\Db::makeTieredPartitionRanges(
                $min, $through, $table_granularity, $this->detailMonths(), $budget['limit']
            );

            if ( ! $ranges ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Could not work out a partition range for %s; skipping.', $table ) );

                continue;
            }

            if ( ! $this->withinPartitionBudget( $table, count( $ranges ), $budget ) ) {

                continue;
            }

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: would create %d partitions covering %s to %s (%s within the last %d months, '
                  . 'whole years before that), plus a catch-all.',
                    $table, count( $ranges ), $min, $through, $table_granularity, $this->detailMonths()
                ) );

                continue;
            }

            \OWA\Core\CoreAPI::notice( sprintf( '%s: partitioning (%d periods). This rewrites the table.', $table, count( $ranges ) ) );

            if ( $db->partitionTable( $table, 'yyyymmdd', $ranges ) ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s: partitioned.', $table ) );

            } else {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: FAILED to partition. The primary key must contain yyyymmdd; see the database error above.', $table
                ) );
            }
        }

        if ( $already && $already === count( $tables ) ) {

            \OWA\Core\CoreAPI::notice(
                'Every fact table is already partitioned, so there was nothing for partition-init '
              . 'to do. It is a one-time conversion; partition-rotate is the command to schedule.'
            );
        }
    }
}
