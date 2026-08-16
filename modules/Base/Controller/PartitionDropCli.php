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

            $this->dropOlderThan( $table, $cutoff, $dry_run );
        }
    }
}
