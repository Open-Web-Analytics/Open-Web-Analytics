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
 *   cmd=partition-drop older-than=18m --dry-run
 *   cmd=partition-drop older-than=2years only=raw         event data only
 *   cmd=partition-drop older-than=12months only=cubes     every reporting cube only
 *   cmd=partition-drop older-than=12months property=<id>  one Property's cube
 *
 * THE MANUAL PRUNE, not bound by the retention settings (Classes\Retention),
 * which govern the scheduled partition-rotate. Raw and cubes are independent:
 * a cube keeps months dropped from raw, and a rebuild leaves those months as
 * built rather than emptying them (CubeRebuildCli). Months dropped from a cube
 * are rebuilt from raw when its window is lengthened, as far back as raw holds.
 */
class PartitionDropCli extends PartitionsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Drops partitions holding only data older than a cutoff. A partition that straddles the cutoff is kept, and the command reports the date before which data no longer exists. Not bound by the retention settings.',
            'arguments'   => array(
                'older-than=<cutoff>' => 'Required. A date (yyyymmdd) or a period back from today, such as 12months, 18m or 2years.',
                'only=<raw|cubes>'    => 'Drop from the raw event tables only, or from every reporting cube only.',
                'property=<id>'       => 'Drop only from this Property\'s cube.',
                'table=<name>'        => 'Drop only from this table.',
                '--force'             => 'Proceed when every historical partition of a table would be dropped.',
                '--dry-run'           => 'Report what would be dropped, and change nothing.',
            ),
        );
    }

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

        $touched = 0;

        $tables = $this->selectTables();

        if ( $tables === false ) {

            return;
        }

        foreach ( $tables as $table ) {

            if ( ! $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s is not partitioned; skipping.', $table ) );

                continue;
            }

            $touched++;

            $this->dropOlderThan( $table, $cutoff, $dry_run );
        }

        // Skipping everything is not success -- see partition-rotate.
        if ( ! $touched ) {

            $this->refuse( 'Nothing to drop: no fact table is partitioned. Run cmd=partition-init first.' );
        }
    }

    /**
     * The tables this prune covers: table=, only=raw or only=cubes, or property=.
     *
     * @return string[]|false false when the arguments were refused
     */
    protected function selectTables() {

        $only     = (string) $this->getParam( 'only' );
        $property = (string) $this->getParam( 'property' );
        $tables   = $this->factTables( $this->getParam( 'table' ) ?: null );

        if ( $only !== '' && ! in_array( $only, array( 'raw', 'cubes' ), true ) ) {

            $this->refuse( sprintf( 'only=%s: use only=raw (event data) or only=cubes (reporting cubes).', $only ) );

            return false;
        }

        if ( $property !== '' ) {

            $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property );

            if ( ! $cube || ! in_array( $cube, $tables, true ) ) {

                $this->refuse( sprintf( 'Property %s has no reporting cube.', $property ) );

                return false;
            }

            return array( $cube );
        }

        if ( $only === '' ) {

            return $tables;
        }

        $keep = array();

        foreach ( $tables as $table ) {

            $is_cube = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdFor( $table ) !== '';

            if ( ( $only === 'cubes' ) === $is_cube ) {

                $keep[] = $table;
            }
        }

        return $keep;
    }
}
