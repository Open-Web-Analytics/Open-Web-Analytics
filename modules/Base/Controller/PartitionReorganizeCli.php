<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Change the partition granularity of the fact tables.
 *
 * Granularity is named for how many parts a month is divided into -- monthly,
 * half-month, quarter-month -- never for a length, because months are 28
 * to 31 days and any name carrying a day count would be wrong in some month.
 *
 * Cut points are days of the month, so every boundary falls on a month start
 * and a change rewrites one month at a time rather than the whole table. It can
 * be run against part of the range, which is how an installation ends up coarse
 * for old data and fine for recent:
 *
 *   cmd=partition-reorganize granularity=half-month
 *   cmd=partition-reorganize granularity=quarter-month from=20260801 to=20260901
 *   cmd=partition-reorganize granularity=monthly --dry-run
 */
class PartitionReorganizeCli extends PartitionsCli {

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db          = \OWA\Core\CoreAPI::dbSingleton();
        $granularity = $this->getParam( 'granularity' );
        $dry_run     = (bool) $this->getParam( 'dry-run' );
        $from        = $this->getParam( 'from' ) ?: null;
        $to          = $this->getParam( 'to' ) ?: null;

        foreach ( array( 'from' => $from, 'to' => $to ) as $name => $value ) {

            if ( $value !== null && ! preg_match( '/^\d{8}$/', (string) $value ) ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s must be a date as yyyymmdd; got "%s".', $name, $value ) );

                return;
            }
        }

        if ( ! $granularity ) {

            \OWA\Core\CoreAPI::notice(
                'granularity is required: quarter-month, half-month or monthly.'
            );

            return;
        }

        if ( ! \OWA\Core\Db::isPartitionGranularity( $granularity ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Unknown granularity "%s". Use one of: quarter-month, half-month, monthly. '
              . '(There is no "weekly": a week does not divide a month.)', $granularity
            ) );

            return;
        }

        $tables = $this->factTables( $this->getParam( 'table' ) ?: null );
        $budget = $this->partitionLimit( count( $tables ) );

        foreach ( $tables as $table ) {

            if ( ! $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s is not partitioned; run partition-init first. Skipping.', $table
                ) );

                continue;
            }

            // Plan first so the count can be judged before anything is rewritten.
            $plan = $db->repartitionTable( $table, $granularity, true, $from, $to );

            if ( ! $this->withinPartitionBudget( $table, $plan['planned'], $budget ) ) {

                continue;
            }

            $result = $dry_run ? $plan : $db->repartitionTable( $table, $granularity, false, $from, $to );

            if ( ! $result['changed'] && ! $result['failed'] ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: already %s (%d period(s) checked).', $table, $granularity, $result['skipped']
                ) );

                continue;
            }

            foreach ( $result['changed'] as $change ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s: %s%s', $table, $dry_run ? 'would rewrite ' : 'rewrote ', $change ) );
            }

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: %s %d period(s), %d already correct.',
                $table, $dry_run ? 'would rewrite' : 'rewrote', count( $result['changed'] ), $result['skipped']
            ) );

            if ( $result['failed'] ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: FAILED on %s. The table is still valid -- the periods that did change are done.',
                    $table, implode( '; ', $result['failed'] )
                ) );
            }
        }
    }
}
