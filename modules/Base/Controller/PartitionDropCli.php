<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Drop fact-table partitions holding only data older than a cutoff.
 *
 * Dropping a partition is a metadata operation, so this removes old data in
 * milliseconds where a DELETE would rewrite millions of rows and return no
 * space to the tablespace.
 *
 * ONLY WHOLE PARTITIONS ARE DROPPED. A partition straddling the cutoff is kept,
 * because dropping it would remove data on or after that date -- more than was
 * asked for. Since a partition is a period rather than a day, the boundary reached
 * is therefore usually earlier than the one requested, and the command reports
 * it: the date before which data no longer exists.
 *
 *   cmd=partition-drop older-than=20260101    a date
 *   cmd=partition-drop older-than=12months    a period back from today
 *   cmd=partition-drop older-than=18m dry-run=1
 */
class PartitionDropCli extends PartitionsCli {

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $dry_run = (bool) $this->getParam( 'dry-run' );
        $raw     = $this->getParam( 'older-than' );

        if ( ! $raw ) {

            \OWA\Core\CoreAPI::notice(
                'older-than is required: a date as yyyymmdd, or a period such as 12months, 18m, 2years, 90days.'
            );

            return;
        }

        $cutoff = $this->resolveCutoff( $raw );

        if ( ! $cutoff ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Could not read "%s" as a date or period. Use yyyymmdd, or 12months / 18m / 2years / 90days.', $raw
            ) );

            return;
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Retention cutoff: %s (from "%s").', $cutoff, $raw ) );

        foreach ( $this->factTables( $this->getParam( 'table' ) ?: null ) as $table ) {

            if ( ! $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s is not partitioned; skipping.', $table ) );

                continue;
            }

            $plan  = $db->getDroppablePartitions( $table, $cutoff );
            $spans = $db->getPartitionSpans( $table );

            if ( ! $plan['drop'] ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s: nothing older than %s.', $table, $cutoff ) );

                continue;
            }

            // Removing every partition leaves only the catch-all, which is
            // almost always a mistyped cutoff rather than an intent to empty
            // the table.
            if ( count( $plan['drop'] ) === count( $spans ) && ! $this->getParam( 'force' ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: %s would remove EVERY partition. If that is intended, re-run with force=1.',
                    $table, $cutoff
                ) );

                continue;
            }

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: would drop %d partition(s) [%s]; data before %s would be gone.',
                    $table, count( $plan['drop'] ), implode( ', ', $plan['drop'] ), $plan['effective']
                ) );

            } else {

                $dropped = 0;

                foreach ( $plan['drop'] as $partition ) {

                    if ( $db->dropPartition( $table, $partition ) ) {

                        $dropped++;

                    } else {

                        \OWA\Core\CoreAPI::notice( sprintf( '%s: failed to drop %s.', $table, $partition ) );
                    }
                }

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: dropped %d partition(s). Data before %s no longer exists.',
                    $table, $dropped, $plan['effective']
                ) );
            }

            if ( $plan['straddling'] ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: kept %s (%s to %s) -- it also holds data on or after %s, so the boundary reached is %s.',
                    $table, $plan['straddling']['name'], $plan['straddling']['start'],
                    $plan['straddling']['less_than'], $cutoff, $plan['effective']
                ) );
            }
        }
    }
}
