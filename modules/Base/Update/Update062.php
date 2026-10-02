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
 * completions onto the migrated rows (GoalMigrator). A purchase takes its v1
 * line items into params.items, and the reconciliation counts them.
 *
 * THEN A RECONCILIATION (PLAN.html 2.22), per site and day, of each pass that
 * writes raw rows: every row v1 holds is either refused, with its reason, or
 * present in owa_event_raw with the revenue and visitors it should carry.
 * Until that holds the update is not recorded, and the next run writes what
 * is missing.
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

        /*
         * First, whatever v1 holds (PLAN 2.30.6). Beacons in 1.x's FILE queue
         * arrived and were never ingested, in a format v2 does not read: they
         * are processed on 1.x before the upgrade, which refuses until they are.
         *
         * owa_queue_item is not a reason to refuse. It held only retries --
         * events whose v1 handlers had already failed -- and with no shipped
         * drain it is mostly years of rows that fail every time. Its count is
         * said, and Update068 drops it.
         */
        $queued = $this->undrainedV1Queue();

        if ( $queued['file_lines'] ) {

            $this->e->notice( sprintf(
                'OWA 1.x queued %d beacon(s) to its file queue that were never ingested, and v2 cannot read them. '
              . 'Process them on 1.x (php cli.php cmd=processEventQueue) until the queue is empty, then upgrade.',
                $queued['file_lines'] ) );

            return false;
        }

        if ( $queued['queue_rows'] ) {

            $this->e->notice( sprintf(
                '%d failed event(s) awaiting retry in %squeue_item will be dropped with the table: v2 does not '
              . 'retry v1 handler failures.', $queued['queue_rows'], $this->prefix ) );
        }

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

        return $this->reconcile( $since );
    }

    /** Days shown per site that does not reconcile, before the rest are counted. */
    const DISCREPANCIES_SHOWN = 10;

    /**
     * Every raw-writing pass, per site: what v1 holds against what reached
     * v2 (FactMigrator::reconcileSite()). The update is not complete until
     * every expected row is present with its revenue and visitors; a site that
     * differs is named, with its first days, and the update stays pending.
     *
     * @return bool
     */
    private function reconcile( $since ) {

        $ok = true;

        foreach ( self::PASSES as $class => $label ) {

            $migrator = $this->migrator( $class, $class === 'VisitorMigrator' ? null : $since );

            if ( ! $migrator ) {

                continue;
            }

            foreach ( $migrator->sites() as $site_id ) {

                $days = $migrator->reconcileSite( $site_id );

                if ( $days === null ) {

                    continue;
                }

                $wrong = \OWA\Module\Base\Classes\Migration\FactMigrator::discrepancies( $days );

                if ( ! $wrong ) {

                    $this->e->notice( sprintf( 'v1 migration reconciled, %s, site %s: %s.', $label, $site_id,
                        \OWA\Module\Base\Classes\Migration\FactMigrator::summary( $days ) ) );

                    continue;
                }

                $ok = false;

                $this->e->notice( sprintf( 'v1 migration does NOT reconcile, %s, site %s: %s', $label, $site_id,
                    implode( '; ', array_slice( $wrong, 0, self::DISCREPANCIES_SHOWN ) ) )
                    . ( count( $wrong ) > self::DISCREPANCIES_SHOWN
                        ? sprintf( '; and %d more', count( $wrong ) - self::DISCREPANCIES_SHOWN ) : '' ) );
            }
        }

        if ( ! $ok ) {

            $this->e->notice( 'The migration is left pending. Run cmd=update again with the same cutoff to'
                . ' write what is missing; a difference that remains after that is a fault to report.' );
        }

        return $ok;
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
     * What OWA 1.x left queued (PLAN 2.30.6): lines in its file queue that are
     * not v2's JSON -- beacons never ingested, which stop the upgrade -- and
     * owa_queue_item rows still awaiting a retry, which do not.
     *
     * @return array file_lines, queue_rows
     */
    public function undrainedV1Queue() {

        $dir   = rtrim( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'async_log_dir' ), '/' ) . '/';
        $lines = 0;

        foreach ( array_merge( array( $dir . 'events.txt' ), (array) glob( $dir . 'unprocessed/*' ) ) as $file ) {

            if ( ! is_file( $file ) ) {

                continue;
            }

            foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {

                // 1.x wrote "<time>|*|<queue>|*|<pid>|*|<urlencoded blob>"; v2 writes JSON.
                if ( strpos( (string) $line, '|*|' ) !== false ) {

                    $lines++;
                }
            }
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->prefix . 'queue_item';
        $rows  = 0;

        if ( $db->tableExists( $table ) ) {

            $row  = $db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s WHERE status = ?', $table ), array( 'unhandled' ) );
            $rows = (int) ( $row['n'] ?? 0 );
        }

        return array( 'file_lines' => $lines, 'queue_rows' => $rows );
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
