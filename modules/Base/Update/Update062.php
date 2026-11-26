<?php
namespace OWA\Module\Base\Update;

/**
 * Migrate v1's history into v2.
 *
 * FORCED AND BLOCKING. CLI-only, and until it succeeds the schema stays behind
 * and every action refuses -- an installation does not run on v2 with its
 * history left in tables v2 does not read.
 *
 * HOW MUCH HISTORY IS AN EXPLICIT CHOICE, passed to the update command:
 *
 *   php cli.php cmd=core-update-schema since=2years     what is newer than a cutoff
 *   php cli.php cmd=core-update-schema since=20240101
 *   php cli.php cmd=core-update-schema --all            everything
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
 * THEN A RECONCILIATION, per site and day, of each pass that
 * writes raw rows: every row v1 holds is either refused, with its reason, or
 * present in owa_event_raw with the revenue and visitors it should carry.
 * Until that holds the update is not recorded, and the next run writes what
 * is missing.
 *
 * On an installation with no v1 tables -- one that was never 1.x -- there is
 * nothing to do.
 */
class Update062 extends \OWA\Core\Update {

    public $schema_version = 62;

    /** @var bool */
    public $is_cli_mode_required = true;

    public $down_does_not_restore = 'the 1.x file queue lines it moved to its update062-v1-queue archive file: they stay there.';

    /**
     * The table prefix v1 is read under; a test reads a fixture's.
     *
     * @var string
     */
    public $prefix = 'owa_';

    /**
     * The partitioned tables reachBack() extends; null for raw and every cube. A test names its own.
     *
     * @var list<string>|null
     */
    public $partitioned_tables = null;

    /**
     * The migration, failed when the database refuses a read: a migrator
     * throws then (FactMigrator::results()) rather than reading no rows, and
     * the update is run again.
     *
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        try {

            return $this->migrate();

        } catch ( \RuntimeException $e ) {

            $this->e->notice( $e->getMessage() );

            return false;
        }
    }

    /**
     * @return bool
     */
    private function migrate() {

        /*
         * First, whatever v1 holds. Beacons in 1.x's FILE queue
         * arrived and were never ingested, in a format v2 does not read. Their
         * lines are moved to an
         * archive file in the queue directory, said in a notice, and the
         * upgrade goes on. down() leaves the archive where it is.
         *
         * owa_queue_item is not a reason to stop. It held only retries --
         * events whose v1 handlers had already failed -- and with no shipped
         * drain it is mostly years of rows that fail every time. Its count is
         * said, and Update068 drops it.
         */
        $queued = $this->undrainedV1Queue();

        if ( $queued['file_lines'] ) {

            $archived = $this->archiveV1Queue();

            if ( $archived === null ) {

                $this->e->notice( 'Moving the 1.x file queue to an archive failed; nothing was removed from it.' );

                return false;
            }

            $this->e->notice( sprintf(
                'OWA 1.x queued %d %s to its file queue that %s never ingested, in a format v2 cannot read. '
              . '%d %s moved from %d %s to %s.',
                $queued['file_lines'], \OWA\Core\Lib::plural( $queued['file_lines'], 'beacon', 'beacons' ),
                \OWA\Core\Lib::plural( $queued['file_lines'], 'was', 'were' ),
                $archived['lines'], \OWA\Core\Lib::plural( $archived['lines'], 'line was', 'lines were' ),
                $archived['files'], \OWA\Core\Lib::plural( $archived['files'], 'file', 'files' ), $archived['path'] ) );
        }

        if ( $queued['queue_rows'] ) {

            $this->e->notice( sprintf(
                '%d failed %s awaiting retry in %squeue_item will be dropped with the table: v2 does not '
              . 'retry v1 handler failures.', $queued['queue_rows'], \OWA\Core\Lib::plural( $queued['queue_rows'], 'event', 'events' ), $this->prefix ) );
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

            $this->e->notice( 'Choose how much history to migrate: cmd=core-update-schema since=<yyyymmdd, or a period'
                . ' such as 2years or 18m>, or cmd=core-update-schema --all. Rows older than the cutoff stay in the v1'
                . ' tables until they are dropped.' );

            $this->awaiting = 'a choice of how much history to migrate';

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

                $progress = $migrator->migrateSite( $site_id );

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
     * @param  string|null $since yyyymmdd, or null for all history
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

            $this->e->notice( 'The migration is left pending. Run cmd=core-update-schema again with the same cutoff to'
                . ' write what is missing; a difference that remains after that is a fault to report.' );
        }

        return $ok;
    }

    /** Each pass, in the order it runs. */
    const PASSES = [
        'RequestMigrator'  => 'page views',
        'ClickMigrator'    => 'clicks',
        'ActionMigrator'   => 'actions',
        'PurchaseMigrator' => 'purchases',
        'VisitorMigrator'  => 'visitor store',
        'GoalMigrator'     => 'goal completions',
    ];

    /**
     * Undo every pass, last first. Rows are deleted by the ids their v1 rows
     * derive, so nothing a beacon wrote is touched.
     *
     * Refused once cmd=v1-drop has run: the ids come from v1's rows, and with
     * them gone the migrated history can be neither found nor restored.
     */
    public function down() {

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

    /** @var string|null 1.x's queue directory; null for v1QueueDir()'s. TESTS ONLY. */
    public $v1_queue_dir = null;

    /**
     * Where 1.x's file queue is: OWA_ASYNC_LOG_DIR when the config file sets
     * it, else owa-data/logs/, 1.x's default. Not async_log_dir's default,
     * which is v2's own queue directory (logs/queue_<instance hash>/).
     *
     * @return string with a trailing slash
     */
    private function v1QueueDir() {

        $dir = $this->v1_queue_dir ?? ( defined( 'OWA_ASYNC_LOG_DIR' ) && (string) OWA_ASYNC_LOG_DIR !== ''
            ? (string) OWA_ASYNC_LOG_DIR
            : (string) \OWA\Core\CoreAPI::getSetting( 'base', 'data_dir' ) . 'logs/' );

        return rtrim( $dir, '/' ) . '/';
    }

    /** @var string|null the archive's file name; null to name it by the time. TESTS ONLY. */
    public $archive_name = null;

    /**
     * The files of the queue directory that can hold 1.x's lines.
     *
     * @param  string $dir
     * @return list<string>
     */
    private static function v1QueueFiles( $dir ) {

        // Plain files only: the web server writes this directory, and a link here could name any file of this user's.
        return array_values( array_filter( array_merge( [ $dir . 'events.txt' ], (array) glob( $dir . 'unprocessed/*' ) ),
            static fn ( $f ) => is_file( $f ) && ! is_link( $f ) ) );
    }

    /**
     * Whether a queue line is 1.x's: "<time>|*|<queue>|*|<pid>|*|<urlencoded blob>". v2 writes JSON.
     *
     * @param  string $line
     * @return bool
     */
    private static function isV1Line( $line ) {

        return strpos( (string) $line, '|*|' ) !== false;
    }

    /**
     * Move every 1.x line of the file queue to an archive file in the queue
     * directory, update062-v1-queue-<date and time>.txt, unchanged, file by
     * file. v2's own lines stay where they are; a batch in unprocessed/ left
     * with none is removed.
     *
     * Each file is rewritten under the lock a writer appends under, so a beacon
     * arriving meanwhile waits and is appended after. The archive is written
     * before its lines are removed from a file, so a failure part way loses
     * none: the lines moved so far are in the archive and the rest in the queue.
     *
     * @return array{lines: int, files: int, path: string}|null null when a file
     *         could not be read or the archive written
     */
    public function archiveV1Queue() {

        $dir  = $this->v1QueueDir();
        $path = $dir . ( $this->archive_name ?: 'update062-v1-queue-' . date( 'Ymd-His' ) . '.txt' );

        $moved = [ 'lines' => 0, 'files' => 0, 'path' => $path ];

        foreach ( self::v1QueueFiles( $dir ) as $file ) {

            $fh = @fopen( $file, 'c+' );

            if ( ! $fh || ! flock( $fh, LOCK_EX ) ) {

                return null;
            }

            $v1   = [];
            $keep = [];

            foreach ( explode( "\n", (string) stream_get_contents( $fh ) ) as $line ) {

                if ( $line === '' ) {

                    continue;
                }

                if ( self::isV1Line( $line ) ) {

                    $v1[] = $line;

                } else {

                    $keep[] = $line;
                }
            }

            $ok = true;

            if ( $v1 ) {

                // Appended through SafeFile, which never writes through a link at the name: the
                // web server can write this directory, and the name can be guessed to the second.
                $ok = \OWA\Core\SafeFile::append( $path, implode( "\n", $v1 ) . "\n", 0600 );

                if ( $ok ) {

                    $rest = $keep ? implode( "\n", $keep ) . "\n" : '';
                    $ok   = ftruncate( $fh, 0 ) && rewind( $fh ) && fwrite( $fh, $rest ) === strlen( $rest ) && fflush( $fh );

                    $moved['lines'] += count( $v1 );
                    $moved['files']++;
                }
            }

            flock( $fh, LOCK_UN );
            fclose( $fh );

            if ( ! $ok ) {

                return null;
            }

            if ( $v1 && ! $keep && $file !== $dir . 'events.txt' ) {

                @unlink( $file );
            }
        }

        return $moved;
    }

    /**
     * What OWA 1.x left queued: lines in its file queue that are
     * not v2's JSON -- beacons never ingested, which up() archives -- and
     * owa_queue_item rows still awaiting a retry, which do not.
     *
     * @return array{file_lines: int, queue_rows: int}
     */
    public function undrainedV1Queue() {

        $dir   = $this->v1QueueDir();
        $lines = 0;

        foreach ( self::v1QueueFiles( $dir ) as $file ) {

            foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {

                if ( self::isV1Line( $line ) ) {

                    $lines++;
                }
            }
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->prefix . 'queue_item';
        $rows  = 0;

        if ( $db->tableExists( $table ) ) {

            $row  = $db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s WHERE status = ?', $table ), [ 'unhandled' ] );
            $rows = (int) ( $row['n'] ?? 0 );
        }

        return [ 'file_lines' => $lines, 'queue_rows' => $rows ];
    }

    /**
     * v1's volume per site and year, and what will be left behind.
     *
     * @param  \OWA\Module\Base\Classes\Migration\FactMigrator $migrator
     * @return list<string>
     */
    public function preflight( $migrator ) {

        $lines   = [ 'v1 page views, per site and year:' ];
        $total   = 0;
        $orphans = 0;
        $unknown = [];

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
     *
     * @param  string|null $since yyyymmdd, or null for all history
     * @return bool
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
            [ \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ],
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

    /**
     * Whether any table a pass reads holds a row.
     *
     * @return bool
     */
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

    /**
     * @return bool
     */
    private function hasV1() {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->tableExists(
            \OWA\Module\Base\Classes\Migration\V1Tables::name( 'request', $this->prefix ) );
    }

    /**
     * A pass, or null where its v1 table is not there.
     *
     * @param  string      $class a pass, a key of PASSES
     * @param  string|null $since yyyymmdd, or null for all history
     * @return \OWA\Module\Base\Classes\Migration\FactMigrator|null
     */
    private function migrator( $class, $since = null ) {

        $class = '\\OWA\\Module\\Base\\Classes\\Migration\\' . $class;

        if ( ! \OWA\Core\CoreAPI::dbSingleton()->tableExists(
                \OWA\Module\Base\Classes\Migration\V1Tables::name( $class::SOURCE, $this->prefix ) ) ) {

            return null;
        }

        return new $class( $this->prefix, \OWA\Module\Base\Classes\Migration\FactMigrator::BATCH, $since );
    }
}
