<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Partition the fact tables of an existing installation.
 *
 * New installations are partitioned when their tables are created, so this is
 * for converting one that predates it. It is a command rather than an update
 * because it rewrites every fact table: on a busy installation that is minutes
 * of I/O, and it should be run deliberately rather than firing inside an
 * upgrade nobody scheduled.
 *
 *   cmd=partition-init                      partition every fact table, monthly
 *   cmd=partition-init granularity=daily    a different granularity
 *   cmd=partition-init table=owa_request    one table
 *   cmd=partition-init dry-run=1            report the plan, change nothing
 */
class PartitionInitCli extends PartitionsCli {

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db          = \OWA\Core\CoreAPI::dbSingleton();
        $granularity = $this->getParam( 'granularity' ) ?: 'monthly';
        $dry_run     = (bool) $this->getParam( 'dry-run' );

        if ( ! \OWA\Core\Db::isPartitionGranularity( $granularity ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Unknown granularity "%s". Use one of: daily, quarter-month, half-month, monthly.', $granularity
            ) );

            return;
        }

        $tables = $this->factTables( $this->getParam( 'table' ) ?: null );

        if ( ! $tables ) {

            \OWA\Core\CoreAPI::notice( 'No fact tables found.' );

            return;
        }

        foreach ( $tables as $table ) {

            if ( $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s is already partitioned; skipping.', $table ) );

                continue;
            }

            // Cover the data that is there. An empty table still gets the
            // current period so that writes have somewhere to go beyond the
            // catch-all.
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
            $max = ( $row && $row['mx'] && (int) $row['mx'] > (int) $min ) ? $row['mx'] : $today;

            $ranges = \OWA\Core\Db::makePartitionRanges( $min, $max, $granularity );

            if ( ! $ranges ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Could not work out a partition range for %s; skipping.', $table ) );

                continue;
            }

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: would create %d %s partitions covering %s to %s, plus a catch-all.',
                    $table, count( $ranges ), $granularity, $min, $max
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
