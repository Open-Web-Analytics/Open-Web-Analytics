<?php
namespace OWA\Module\Base\Update;

/**
 * Migrate v1's history into v2 (PLAN.html 2.21).
 *
 * FORCED AND BLOCKING. CLI-only, and until it succeeds the schema stays behind
 * and every action refuses -- an installation does not run on v2 with its
 * history left in tables v2 does not read.
 *
 * HOW MUCH HISTORY IS AN EXPLICIT CHOICE, passed to the update command:
 *
 *   php cli.php cmd=update since=2years     what is newer than a cutoff
 *   php cli.php cmd=update since=20240101
 *   php cli.php cmd=update --all            everything
 *
 * Without one it prints v1's volume per site and year and fails, which leaves
 * the update pending. Rows older than the cutoff stay in v1's tables until the
 * optional drop removes them; v1's tables are never written.
 *
 * Resumable: an interrupted run continues where it stopped, and a finished site
 * is not read again. A later run must use the same cutoff.
 *
 * IN ORDER: page views, clicks, actions and purchases (FactMigrator's
 * subclasses), then the visitor store from each visitor's first session
 * (VisitorMigrator, over all of a site's history), then v1's recorded goal
 * completions onto the migrated rows (GoalMigrator). Line items are not
 * migrated: v2 has no item-level shape.
 *
 * On an installation with no v1 tables -- one that was never 1.x -- there is
 * nothing to do.
 */
class Update062 extends \OWA\Core\Update {

    var $schema_version = 62;

    var $is_cli_mode_required = true;

    /** The table prefix v1 is read under; a test reads a fixture's. */
    var $prefix = 'owa_';

    /** The partitioned tables reachBack() extends; null for raw and every cube. A test names its own. */
    var $partitioned_tables = null;

    function up( $force = false ) {

        if ( ! $this->hasV1() ) {

            $this->e->notice( 'No v1 tables: nothing to migrate.' );

            return true;
        }

        // Tables with no rows -- an installation that never tracked anything,
        // or a fresh one whose install created them -- have no history to
        // choose a cutoff for.
        if ( ! $this->hasV1Rows() ) {

            $this->e->notice( 'The v1 tables are empty: nothing to migrate.' );

            return true;
        }

        foreach ( $this->preflight( $this->migrator( 'RequestMigrator' ) ) as $line ) {

            $this->e->notice( $line );
        }

        $raw_since = \OWA\Core\CoreAPI::getRequestParam( 'since' );
        $all       = (bool) \OWA\Core\CoreAPI::getRequestParam( 'all' );

        if ( (bool) $raw_since === $all ) {

            $this->e->notice( 'Choose how much history to migrate: cmd=update since=<yyyymmdd, or a period'
                . ' such as 2years or 18m>, or cmd=update --all. Rows older than the cutoff stay in the v1'
                . ' tables until they are dropped.' );

            return false;
        }

        $since = null;

        if ( $raw_since ) {

            $since = \OWA\Module\Base\Controller\PartitionsCli::resolveCutoff( $raw_since );

            if ( ! $since ) {

                $this->e->notice( sprintf(
                    'Could not read since="%s". Use yyyymmdd, or a period such as 2years, 18m, 90days.', $raw_since ) );

                return false;
            }
        }

        if ( ! $this->reachBack( $since ) ) {

            return false;
        }

        foreach ( self::PASSES as $class => $label ) {

            // The visitor store reads all of a site's history: a visitor's
            // acquisition is stamped on every later session they have.
            $migrator = $this->migrator( $class, $class === 'VisitorMigrator' ? null : $since );

            if ( ! $migrator ) {

                continue;
            }

            foreach ( $migrator->sites() as $site_id ) {

                try {

                    $progress = $migrator->migrateSite( $site_id );

                } catch ( \RuntimeException $e ) {

                    $this->e->notice( $e->getMessage() );

                    return false;
                }

                $this->e->notice( sprintf( 'v1 migration, %s, site %s: read %d, wrote %d, refused %d%s',
                    $label, $site_id, $progress['rows_read'], $progress['rows_written'], $progress['rows_refused'],
                    $progress['refusals'] ? ' ' . json_encode( $progress['refusals'] ) : '' ) );

                if ( empty( $progress['completed_at'] ) ) {

                    return false;
                }
            }
        }

        return true;
    }

    /** Each pass, in the order it runs. */
    const PASSES = array(
        'RequestMigrator'  => 'page views',
        'ClickMigrator'    => 'clicks',
        'ActionMigrator'   => 'actions',
        'PurchaseMigrator' => 'purchases',
        'VisitorMigrator'  => 'visitor store',
        'GoalMigrator'     => 'goal completions',
    );

    /**
     * Undo every pass, last first. Rows are deleted by the ids their v1 rows
     * derive, so nothing a beacon wrote is touched.
     *
     * Refused once cmd=v1-drop has run: the ids come from v1's rows, and with
     * them gone the migrated history can be neither found nor restored.
     */
    function down() {

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'v1_tables_dropped' ) ) {

            $this->e->notice( "v1's tables were dropped (cmd=v1-drop), so the migration cannot be"
                . ' reverted: there is no 1.x history to return to.' );

            return false;
        }

        if ( ! $this->hasV1() ) {

            $this->e->notice( 'No v1 tables: nothing to revert.' );

            return true;
        }

        try {

            foreach ( array_reverse( self::PASSES, true ) as $class => $label ) {

                $migrator = $this->migrator( $class );

                if ( ! $migrator ) {

                    continue;
                }

                foreach ( $migrator->sites() as $site_id ) {

                    $this->e->notice( sprintf( 'v1 migration, %s, site %s: reverted %d rows.',
                        $label, $site_id, $migrator->revertSite( $site_id ) ) );
                }
            }

        } catch ( \RuntimeException $e ) {

            $this->e->notice( $e->getMessage() );

            return false;
        }

        return true;
    }

    /**
     * v1's volume per site and year, and what will be left behind.
     *
     * @return string[]
     */
    public function preflight( $migrator ) {

        $lines   = array( 'v1 page views, per site and year:' );
        $total   = 0;
        $orphans = 0;
        $unknown = array();

        foreach ( $migrator->volume() as $row ) {

            if ( ! $row['known'] ) {

                $unknown[ $row['site_id'] ] = true;
                $orphans += $row['rows'];

                continue;
            }

            $lines[] = sprintf( '  %-40s %4d  %10d', $row['site_id'], $row['year'], $row['rows'] );
            $total  += $row['rows'];
        }

        $lines[] = sprintf( '  %-40s %4s  %10d', 'total', '', $total );

        if ( $unknown ) {

            $lines[] = sprintf( '  Not migrated: %d rows for %d site ids no site carries any more.',
                $orphans, count( $unknown ) );
        }

        return $lines;
    }

    /**
     * Dated partitions on owa_event_raw and every cube, reaching back to the
     * oldest day migrated: months within the detail window, years before it.
     *
     * v2's tables were partitioned from the day they were created. Older rows
     * would land in the first partition, which retention and the cube build
     * both read as starting on that later day -- a build of 2021 refuses, as no
     * dated partition covers it. Done before the passes, so the split moves
     * only rows v2 wrote itself.
     */
    private function reachBack( $since ) {

        $earliest = null;

        foreach ( array_keys( self::PASSES ) as $class ) {

            $migrator = $this->migrator( $class, $since );
            $day      = $migrator ? $migrator->earliestDay() : null;

            if ( $day && ( $earliest === null || $day < $earliest ) ) {

                $earliest = $day;
            }
        }

        if ( $earliest === null ) {

            return true;
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $tables = $this->partitioned_tables ?? array_merge(
            array( \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ),
            array_values( \OWA\Module\Base\Classes\Cube\Cubes::existing() ) );

        // As partition-rotate reads them, so it finds the result already in shape.
        $detail_months = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_detail_months' )
            ?: \OWA\Core\Db::PARTITION_DETAIL_MONTHS;
        $limit         = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_max_partitions' )
            ?: \OWA\Core\Db::PARTITION_COUNT_LIMIT;

        foreach ( $tables as $table ) {

            if ( ! $db->isPartitioned( $table ) ) {

                continue;
            }

            $result = $db->extendPartitionsBack( $table, (string) $earliest, $detail_months, $limit );

            if ( ! $result['covered'] && ! $result['added'] ) {

                $this->e->notice( sprintf( '%s: adding partitions back to %d failed.', $table, $earliest ) );

                return false;
            }

            if ( $result['added'] ) {

                $this->e->notice( sprintf( '%s: %d partitions added, reaching back to %s.',
                    $table, count( $result['added'] ), $result['added'][0] ) );
            }
        }

        return true;
    }

    /** Whether any table a pass reads holds a row. */
    private function hasV1Rows() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( array_keys( self::PASSES ) as $class ) {

            $class = '\\OWA\\Module\\Base\\Classes\\Migration\\' . $class;
            $table = \OWA\Module\Base\Classes\Migration\V1Tables::name( $class::SOURCE, $this->prefix );

            if ( $db->tableExists( $table ) && $db->get_row( sprintf( 'SELECT 1 AS x FROM %s LIMIT 1', $table ) ) ) {

                return true;
            }
        }

        return false;
    }

    private function hasV1() {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists(
            \OWA\Module\Base\Classes\Migration\V1Tables::name( 'request', $this->prefix ) );
    }

    /** A pass, or null where its v1 table is not there. */
    private function migrator( $class, $since = null ) {

        $class = '\\OWA\\Module\\Base\\Classes\\Migration\\' . $class;

        if ( ! \OWA\Core\CoreAPI::dbSingleton()->tableExists(
                \OWA\Module\Base\Classes\Migration\V1Tables::name( $class::SOURCE, $this->prefix ) ) ) {

            return null;
        }

        return new $class( $this->prefix, \OWA\Module\Base\Classes\Migration\FactMigrator::BATCH, $since );
    }
}

?>
