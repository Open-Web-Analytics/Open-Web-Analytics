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
 * Run once, this converts an installation that predates partitioning. Run
 * repeatedly -- which it is meant to be, from cron -- it tops the lead back up
 * and otherwise does nothing, so the layout depends on the date rather than on
 * how many times it has run.
 *
 * The lead is what makes retention work. A write past the last boundary goes to
 * the catch-all, and the catch-all can never be dropped by a cutoff, since with
 * no upper bound it is never wholly older than any date. Keeping a year of
 * partitions ahead means writes always find a real partition, so nothing
 * accumulates anywhere retention cannot reach.
 *
 * It is a command rather than an update because the first run rewrites every
 * fact table: on a busy installation that is minutes of I/O, and it should be
 * run deliberately rather than firing inside an upgrade nobody scheduled.
 * Later runs only split an empty catch-all and are cheap.
 *
 *   cmd=partition-init                          partition/top up, monthly
 *   cmd=partition-init granularity=half-month   a finer granularity
 *   cmd=partition-init months-ahead=6           keep six months ahead, not twelve
 *   cmd=partition-init table=owa_request        one table
 *   cmd=partition-init dry-run=1                report the plan, change nothing
 *
 * Run it monthly:
 *   0 4 1 * *  php /path/to/owa/cli.php cmd=partition-init
 */
class PartitionInitCli extends PartitionsCli {

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db          = \OWA\Core\CoreAPI::dbSingleton();
        $granularity = $this->getParam( 'granularity' ) ?: 'monthly';
        $dry_run     = (bool) $this->getParam( 'dry-run' );

        $months_ahead = $this->getParam( 'months-ahead' );
        $months_ahead = $months_ahead === null || $months_ahead === ''
            ? \OWA\Core\Db::PARTITION_MONTHS_AHEAD
            : max( 1, (int) $months_ahead );

        if ( ! \OWA\Core\Db::isPartitionGranularity( $granularity ) ) {

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

        $budget = $this->partitionLimit( count( $tables ) );

        $through = \OWA\Core\Db::partitionLeadBoundary( $months_ahead );

        \OWA\Core\CoreAPI::notice( sprintf(
            'Keeping %d month(s) of partitions ahead: through %s.', $months_ahead, $through
        ) );

        foreach ( $tables as $table ) {

            // Already partitioned: top the lead back up rather than skipping.
            // This is the case on every run after the first, and is what makes
            // repeated runs converge on the same layout instead of doing
            // nothing.
            if ( $db->isPartitioned( $table ) ) {

                $ext = $db->extendPartitions( $table, $granularity, $through, true );

                if ( $ext['covered'] ) {

                    \OWA\Core\CoreAPI::notice( sprintf(
                        '%s: already covered through %s; nothing to add.', $table, $ext['top']
                    ) );

                    continue;
                }

                if ( ! $ext['planned'] ) {

                    \OWA\Core\CoreAPI::notice( sprintf(
                        '%s: partitioned, but its layout could not be read; skipping.', $table
                    ) );

                    continue;
                }

                if ( ! $this->withinPartitionBudget(
                    $table, count( $db->getPartitionSpans( $table ) ) + $ext['planned'], $budget
                ) ) {

                    continue;
                }

                if ( $dry_run ) {

                    \OWA\Core\CoreAPI::notice( sprintf(
                        '%s: would add %d %s partition(s), extending %s to %s.',
                        $table, $ext['planned'], $granularity, $ext['top'], $through
                    ) );

                    continue;
                }

                $done = $db->extendPartitions( $table, $granularity, $through );

                \OWA\Core\CoreAPI::notice( $done['added']
                    ? sprintf( '%s: added %d partition(s), now covered through %s.',
                        $table, count( $done['added'] ), $through )
                    : sprintf( '%s: FAILED to extend; see the database error above.', $table )
                );

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

            // The lead decides the upper end; -1 day because the boundary is
            // exclusive and makePartitionRanges() covers the period its end
            // falls in.
            $max = date( 'Ymd', strtotime( $through . ' -1 day' ) );

            $ranges = \OWA\Core\Db::makePartitionRanges( $min, $max, $granularity );

            if ( ! $ranges ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Could not work out a partition range for %s; skipping.', $table ) );

                continue;
            }

            if ( ! $this->withinPartitionBudget( $table, count( $ranges ), $budget ) ) {

                continue;
            }

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: would create %d %s partitions covering %s to %s, plus a catch-all.',
                    $table, count( $ranges ), $granularity, $min, $through
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
    }
}
