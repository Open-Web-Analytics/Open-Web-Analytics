<?php
namespace OWA\Module\Base\Classes\Migration;

/**
 * One v1 fact table into owa_event_raw: what every source shares.
 *
 * Each v1 row becomes the event a live beacon would have been, and is stored
 * through the same row builder as ingest (EventRawHandlers::rowFor), so ids,
 * column mapping and "(not set)" handling are ingest's own. What ingest does
 * beyond building rows is deliberately not run:
 *
 *   - no announcements: a migrated session is not news;
 *   - no goal marking: v1 recorded its own completions (GoalMigrator), and
 *     marking history against today's goal definitions would be retroactive
 *     conversion;
 *   - no acquisition write: VisitorMigrator fills the visitor store.
 *
 * A subclass names its v1 table (SOURCE) and what one of its rows becomes
 * (events()). Everything else -- identity, time, the page, referrer, user
 * agent, location, attribution and custom variables, which every v1 fact row
 * carries the same way -- is here.
 *
 * READS v1 BY COLUMN NAME AND NEVER BY TYPE. Installations hold the same
 * columns with different types; see resolve().
 *
 * Batched and resumable: owa_migration_progress records the last v1 id passed,
 * per source and site, in the same transaction as the rows written, and a batch
 * replayed after an interruption derives the same ids and writes nothing twice.
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-type Refs array<string, array<int|string, mixed>>
 * @phpstan-type Progress array<string, mixed>
 * @phpstan-type Tally array{read?: array<int, int>, refused?: array<int, array<string, int>>, events?: array<int, array<string, array{n: int, revenue: int, items: int, max_ts: int}>>, visitors?: array<int, array<int|string, true>>}
 * @phpstan-type TypeCounts array{expected?: int, revenue_expected?: int, items_expected?: int, present?: int, revenue_present?: int, items_present?: int}
 * @phpstan-type ReconcileDay array{read: int, refused: array<string, int>, types: array<string, TypeCounts>, visitors_expected: int, visitors_present: int}
 *
 * A v1 row is a Row. Refs is resolve()'s: table => [ id as text => row ],
 * plus prior_sessions (session id => count) and what a subclass adds; an id
 * that is all digits is an int key. Progress is the owa_migration_progress
 * row, as the entity loads it or progress() starts it: last_id, since,
 * rows_read, rows_written, rows_refused, refusals (reason => count),
 * completed_at, persisted. Tally is what one batch adds to
 * owa_migration_tally and owa_migration_day_visitor, by yyyymmdd.
 */
abstract class FactMigrator {

    /**
     * The mediums v1 assigned itself rather than read from a link: its
     * default, and its three readings of the referrer (1.x's resolveMedium()).
     */
    const GENERATED_MEDIUMS = [ 'direct', 'organic-search', 'social-network', 'referral' ];

    /** The v1 table, unprefixed; each subclass names its own. */
    const SOURCE = '';

    /** v1's page views, which name sessions v1 lost the row for (priorSessions()). */
    const REQUESTS = 'request';

    /**
     * What progress is recorded under: the table, unless two passes read the
     * same one.
     *
     * @return string
     */
    protected static function progressKey() {

        return static::SOURCE;
    }

    const BATCH = 500;

    /** Refusal reasons, as they are counted in owa_migration_progress. */
    const NO_VISITOR   = 'no_visitor';
    const NO_SESSION   = 'no_session';
    const NO_TIMESTAMP = 'no_timestamp';
    const NO_DAY       = 'no_day';
    const NOT_STORED   = 'not_stored';

    /** @var string */
    private $prefix;

    /** @var int */
    private $batch;

    /** @var int|null the oldest day migrated, as yyyymmdd; null migrates all */
    protected $since;

    /** @var array<string, bool> "table.column" => bool, whether that v1 column is an integer type */
    private $integer_keys = [];

    /**
     * @param string $prefix the prefix the v1 tables are read under
     * @param int    $batch  v1 rows per transaction
     * @param int|null $since the oldest day to migrate, as yyyymmdd; null for all
     */
    public function __construct( $prefix = 'owa_', $batch = self::BATCH, $since = null ) {

        $this->prefix = $prefix;
        $this->batch  = max( 1, (int) $batch );
        $this->since  = $since === null ? null : (int) $since;
    }

    /**
     * How much v1 holds, per site and year: what the preflight shows before an
     * administrator chooses a cutoff.
     *
     * @return list<array{site_id: string, year: int, rows: int, known: bool}>  known: whether the site still exists
     */
    public function volume() {

        $known = $this->knownSites();

        return array_map( function ( $r ) use ( $known ) {

            $r = (array) $r;

            return [ 'site_id' => (string) $r['site_id'], 'year' => (int) $r['year'], 'rows' => (int) $r['n'],
                'known' => isset( $known[ (string) $r['site_id'] ] ) ];

        }, $this->results( sprintf(
            'SELECT site_id, FLOOR(yyyymmdd / 10000) AS year, COUNT(*) AS n FROM %s'
            . ' GROUP BY site_id, FLOOR(yyyymmdd / 10000) ORDER BY site_id, year',
            V1Tables::name( static::SOURCE, $this->prefix ) ) ) );
    }

    /**
     * The oldest day this pass will write, as yyyymmdd; null when it has none.
     *
     * v2's tables need dated partitions reaching back to it before the rows
     * arrive (Update062).
     *
     * @return int|null
     */
    public function earliestDay() {

        $row = $this->row( sprintf( 'SELECT MIN(yyyymmdd) AS d FROM %s WHERE yyyymmdd >= %d',
            V1Tables::name( static::SOURCE, $this->prefix ), max( 19700101, (int) $this->since ) ) );

        return empty( $row['d'] ) ? null : (int) $row['d'];
    }

    /**
     * The sites v1 holds page views for that still exist.
     *
     * A site_id no site row carries -- one deleted outright, or a test's -- is
     * not migrated: its history would sit in v2 where no profile reports on
     * it. The preflight counts what is left behind (volume()).
     *
     * @return string[]
     */
    public function sites() {

        $rows = $this->results( sprintf(
            'SELECT DISTINCT site_id FROM %s', V1Tables::name( static::SOURCE, $this->prefix ) ) );

        $known = $this->knownSites();

        return array_values( array_filter( array_map( function ( $r ) {

            return (string) ( (array) $r )['site_id'];

        }, $rows ), function ( $site_id ) use ( $known ) {

            return $site_id !== '' && isset( $known[ $site_id ] );
        } ) );
    }

    /**
     * get_results(), but a statement the database refused throws rather than
     * reading as no rows: a failed read of a batch read as the end of a
     * site's history marked the site complete, and a failed tally read made
     * the reconcile find nothing wrong. Update062 turns the throw into a
     * failed update, which is run again.
     *
     * @param  string       $sql
     * @param  array<mixed> $params
     * @return array<mixed>
     * @throws \RuntimeException
     */
    protected function results( $sql, array $params = [] ) {

        $db     = $this->db();
        $before = $db->queryErrorCount();
        $rows   = $db->get_results( $sql, $params );

        if ( $db->queryErrorCount() !== $before ) {

            throw new \RuntimeException( 'v1 migration: the database refused a query, so nothing was read: ' . substr( $sql, 0, 160 ) );
        }

        return (array) $rows;
    }

    /**
     * get_row(), with results()'s rule.
     *
     * @param  string       $sql
     * @param  array<mixed> $params
     * @return array<mixed>
     * @throws \RuntimeException
     */
    protected function row( $sql, array $params = [] ) {

        $db     = $this->db();
        $before = $db->queryErrorCount();
        $row    = $db->get_row( $sql, $params );

        if ( $db->queryErrorCount() !== $before ) {

            throw new \RuntimeException( 'v1 migration: the database refused a query, so nothing was read: ' . substr( $sql, 0, 160 ) );
        }

        return (array) $row;
    }

    /** @return array<int|string, true> site_id => true, for every site row there is */
    protected function knownSites() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName();

        $known = [];

        foreach ( $this->results( sprintf( 'SELECT site_id FROM %s', $table ) ) as $r ) {

            $known[ (string) ( (array) $r )['site_id'] ] = true;
        }

        return $known;
    }

    /**
     * Migrate one site's page views, from where the last run stopped.
     *
     * @param  string   $site_id
     * @param  int|null $max_batches stop after this many batches; null runs to the end
     * @return Progress the progress row
     */
    public function migrateSite( $site_id, $max_batches = null ) {

        $progress = $this->progress( $site_id );

        $batches = 0;

        while ( empty( $progress['completed_at'] ) ) {

            if ( $max_batches !== null && $batches >= $max_batches ) {

                break;
            }

            $rows = $this->read( $site_id, $progress['last_id'] );

            if ( ! $rows ) {

                $progress['completed_at'] = time();
                $this->save( $progress );

                break;
            }

            $this->migrateBatch( $site_id, $rows, $progress );

            $batches++;
        }

        return $progress;
    }

    /**
     * One batch: build, write and record progress in one transaction.
     *
     * @param string    $site_id
     * @param list<Row> $rows     v1 rows, in id order
     * @param Progress  $progress updated in place
     * @return void
     */
    protected function migrateBatch( $site_id, array $rows, array &$progress ) {

        $last = end( $rows );
        $progress['last_id'] = (string) $last['id'];

        $db = $this->db();
        $db->beginTransaction();

        $written = $this->apply( $rows, $progress );

        if ( $written === false ) {

            $db->rollbackTransaction();

            throw new \RuntimeException( sprintf(
                'v1 migration: writing a batch of %s for site %s failed; nothing from it was kept.',
                static::SOURCE, $site_id ) );
        }

        $progress['rows_written'] += $written;

        if ( ! $this->save( $progress ) ) {

            $db->rollbackTransaction();

            throw new \RuntimeException( 'v1 migration: recording progress failed; the batch was rolled back.' );
        }

        $db->endTransaction();
    }

    /**
     * What one batch does, inside its transaction: here, write the raw rows it
     * becomes. A pass that is not a copy of rows overrides this.
     *
     * @param  list<Row> $rows
     * @param  Progress  $progress updated in place
     * @return int|false how many rows it wrote
     */
    protected function apply( array $rows, array &$progress ) {

        $tally   = [];
        $written = $this->write( $this->build( $rows, $progress, $tally ) );

        if ( $written === false || ! $this->recordTally( (string) $rows[0]['site_id'], $tally ) ) {

            return false;
        }

        return $written;
    }

    /**
     * Add a batch's tally to owa_migration_tally and owa_migration_day_visitor,
     * inside the batch's transaction.
     *
     * @param string $site_id
     * @param Tally  $tally from build()
     * @return bool
     */
    protected function recordTally( $site_id, array $tally ) {

        $source  = static::progressKey();
        $table   = \OWA\Core\CoreAPI::entityFactory( 'base.migration_tally' )->getTableName();
        $entries = [];

        foreach ( $tally['read'] ?? [] as $d => $n ) {

            $entries[] = [ $d, 'read', '', $n, 0, 0, null ];
        }

        foreach ( $tally['refused'] ?? [] as $d => $reasons ) {

            foreach ( $reasons as $reason => $n ) {

                $entries[] = [ $d, 'refused', $reason, $n, 0, 0, null ];
            }
        }

        foreach ( $tally['events'] ?? [] as $d => $types ) {

            foreach ( $types as $type => $t ) {

                $entries[] = [ $d, 'event', $type, $t['n'], $t['revenue'], $t['items'], $t['max_ts'] ];
            }
        }

        foreach ( $entries as list( $d, $kind, $name, $n, $revenue, $items, $max_ts ) ) {

            // Added to rather than replaced: a day spans batches.
            $ok = $this->db()->query( sprintf(
                'INSERT INTO %s (id, source, site_id, yyyymmdd, kind, name, n, revenue, items, max_ts)'
              . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = n + ?, revenue = revenue + ?,'
              . ' items = items + ?, max_ts = GREATEST(COALESCE(max_ts, 0), COALESCE(?, 0))', $table ),
                [ \OWA\Module\Base\Entity\MigrationTally::idFor( $source, $site_id, $d, $kind, $name ),
                    $source, $site_id, (int) $d, $kind, $name, (int) $n, (int) $revenue, (int) $items, $max_ts,
                    (int) $n, (int) $revenue, (int) $items, $max_ts ] );

            if ( $ok === false ) {

                return false;
            }
        }

        $visitors = [];

        foreach ( $tally['visitors'] ?? [] as $d => $ids ) {

            foreach ( array_keys( $ids ) as $visitor_id ) {

                $visitors[] = [ \OWA\Module\Base\Entity\MigrationDayVisitor::idFor( $source, $site_id, $d, $visitor_id ),
                    $source, $site_id, (int) $d, (string) $visitor_id ];
            }
        }

        foreach ( array_chunk( $visitors, 500 ) as $chunk ) {

            $ok = $this->db()->query( sprintf(
                'INSERT INTO %s (id, source, site_id, yyyymmdd, visitor_id) VALUES %s ON DUPLICATE KEY UPDATE id = id',
                \OWA\Core\CoreAPI::entityFactory( 'base.migration_day_visitor' )->getTableName(),
                implode( ',', array_fill( 0, count( $chunk ), '(?, ?, ?, ?, ?)' ) ) ),
                array_merge( ...$chunk ) );

            if ( $ok === false ) {

                return false;
            }
        }

        return true;
    }

    /**
     * Drop a site's tally for this pass: it starts over, or is reverted.
     *
     * @param  string $site_id
     * @return void
     */
    protected function forgetTally( $site_id ) {

        foreach ( [ 'base.migration_tally', 'base.migration_day_visitor' ] as $entity ) {

            $this->db()->query( sprintf( 'DELETE FROM %s WHERE source = ? AND site_id = ?',
                \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName() ),
                [ static::progressKey(), (string) $site_id ] );
        }
    }

    /**
     * The raw rows a batch of v1 rows becomes, counting refusals into $progress.
     *
     * @param  list<Row>  $rows
     * @param  Progress   $progress updated in place
     * @param  Tally|null $tally    added to in place; null counts nothing
     * @return list<array<string, mixed>> raw rows, as EventRawHandlers::migratedRowFor() builds them
     */
    protected function build( array $rows, array &$progress, ?array &$tally = null ) {

        $refs = $this->resolve( $rows );

        $out = [];

        foreach ( $rows as $r ) {

            $progress['rows_read']++;

            $refused = $this->refusal( $r );
            $built   = [];

            if ( ! $refused ) {

                foreach ( $this->events( $r, $refs ) as $event ) {

                    foreach ( $event->getProperties() as $name => $value ) {

                        if ( is_string( $value ) ) {

                            $event->set( $name, self::v1Text( $value ) );
                        }
                    }

                    $row = \OWA\Module\Base\Handler\EventRawHandlers::migratedRowFor( $event );

                    /*
                     * A row the builder will not store refuses the whole v1 row,
                     * by its own reason, so the count says so. Skipped silently,
                     * it was left out of the expected events as well as raw, and
                     * the day reconciled with it missing.
                     */
                    if ( ! $row ) {

                        $refused = self::NOT_STORED;
                        $built   = [];

                        break;
                    }

                    $built[] = $row;
                }
            }

            if ( $tally !== null ) {

                $d = (int) ( $r['yyyymmdd'] ?? 0 );
                $tally['read'][ $d ] = ( $tally['read'][ $d ] ?? 0 ) + 1;

                if ( $refused ) {

                    $tally['refused'][ $d ][ $refused ] = ( $tally['refused'][ $d ][ $refused ] ?? 0 ) + 1;
                }
            }

            if ( $refused ) {

                $progress['rows_refused']++;
                $progress['refusals'][ $refused ] = ( $progress['refusals'][ $refused ] ?? 0 ) + 1;

                continue;
            }

            foreach ( $built as $row ) {

                $out[] = $row;

                if ( $tally !== null ) {

                    $d    = (int) $row['yyyymmdd'];
                    $type = (string) $row['event_type'];
                    $t    = $tally['events'][ $d ][ $type ] ?? [ 'n' => 0, 'revenue' => 0, 'items' => 0, 'max_ts' => 0 ];

                    $t['n']++;
                    $t['revenue'] += (int) ( $row['revenue'] ?? 0 );
                    $t['items']   += self::itemCount( $row['params'] ?? null );
                    $t['max_ts']   = max( $t['max_ts'], (int) $row['ts'] );

                    $tally['events'][ $d ][ $type ] = $t;
                    $tally['visitors'][ $d ][ (string) $row['visitor_id'] ] = true;
                }
            }
        }

        return $out;
    }

    /**
     * The DELETEs removing these raw rows: one per day, naming it.
     *
     * Raw's key is (yyyymmdd, id), so an id with no day is looked for in
     * every partition.
     *
     * @param  string    $table
     * @param  list<Row> $rows  as build() made them
     * @return list<string>
     */
    protected static function deletesByDay( $table, array $rows ) {

        $by_day = [];

        foreach ( $rows as $row ) {

            $by_day[ (int) $row['yyyymmdd'] ][] = (int) $row['id'];
        }

        $out = [];

        foreach ( $by_day as $day => $ids ) {

            $out[] = sprintf( 'DELETE FROM %s WHERE yyyymmdd = %d AND id IN (%s)', $table, $day, implode( ',', $ids ) );
        }

        return $out;
    }

    /**
     * Undo one site's migration: delete exactly the raw rows it wrote.
     *
     * The ids are derived from the v1 rows, which the migration never
     * touches, so reading them again with the cutoff the site was migrated
     * with derives the same ids. Nothing else in owa_event_raw is touched.
     * Once v1 is dropped there is nothing to roll back to.
     *
     * @param  string $site_id
     * @return int rows deleted
     */
    public function revertSite( $site_id ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );
        $entity->load( \OWA\Module\Base\Entity\MigrationProgress::idFor( static::progressKey(), $site_id ) );

        if ( ! $entity->wasPersisted() ) {

            return 0;
        }

        $since = (int) $entity->get( 'since' );
        $this->since = $since > 0 ? $since : null;

        $table   = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $deleted = 0;
        $after   = null;
        $scratch = [ 'rows_read' => 0, 'rows_refused' => 0, 'refusals' => [] ];

        while ( $rows = $this->read( $site_id, $after ) ) {

            foreach ( self::deletesByDay( $table, $this->build( $rows, $scratch ) ) as $sql ) {

                if ( $this->db()->query( $sql ) === false ) {

                    throw new \RuntimeException( sprintf( 'v1 migration: reverting site %s failed.', $site_id ) );
                }

                $deleted += (int) $this->db()->getAffectedRows();
            }

            $last  = end( $rows );
            $after = (string) $last['id'];
        }

        $entity->delete();
        $this->forgetTally( $site_id );

        return $deleted;
    }

    /**
     * What v1 holds for one site against what reached owa_event_raw, per day.
     *
     * The expected side is what each batch recorded as it wrote
     * (recordTally()): per day, v1 rows read and refused, and per event type
     * the rows written with their revenue and line items, and the visitors.
     * The present side is counted from raw: this pass's event types, up to the
     * latest ts it wrote -- v1 stopped before v2 began recording, so a row
     * after that is live traffic, not migrated history.
     *
     * Counted, not rebuilt: reading v1 again and rebuilding every row to know
     * what to expect would cost as much as the migration.
     *
     * A site reconciles when every expected row is present with the same
     * revenue and visitors. Refused rows are the accounted-for difference.
     *
     * @param  string $site_id
     * @return array<int, ReconcileDay>|null yyyymmdd => day; null where the pass writes no raw rows
     */
    public function reconcileSite( $site_id ) {

        $source  = static::progressKey();
        $site_id = (string) $site_id;
        $raw     = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $days    = [];
        $types   = [];
        $bound   = 0;

        $day = function ( $d ) use ( &$days ) {

            if ( ! isset( $days[ $d ] ) ) {

                $days[ $d ] = [ 'read' => 0, 'refused' => [], 'types' => [],
                    'visitors_expected' => 0, 'visitors_present' => 0 ];
            }
        };

        foreach ( $this->results( sprintf(
                'SELECT yyyymmdd, kind, name, n, revenue, items, max_ts FROM %s WHERE source = ? AND site_id = ?',
                \OWA\Core\CoreAPI::entityFactory( 'base.migration_tally' )->getTableName() ),
                [ $source, $site_id ] ) as $row ) {

            $row = (array) $row;
            $d   = (int) $row['yyyymmdd'];
            $day( $d );

            if ( $row['kind'] === 'read' ) {

                $days[ $d ]['read'] = (int) $row['n'];

            } elseif ( $row['kind'] === 'refused' ) {

                $days[ $d ]['refused'][ (string) $row['name'] ] = (int) $row['n'];

            } else {

                $type = (string) $row['name'];
                $days[ $d ]['types'][ $type ]['expected']         = (int) $row['n'];
                $days[ $d ]['types'][ $type ]['revenue_expected'] = (int) $row['revenue'];
                $days[ $d ]['types'][ $type ]['items_expected']   = (int) $row['items'];
                $types[ $type ] = true;
                $bound = max( $bound, (int) $row['max_ts'] );
            }
        }

        foreach ( $this->results( sprintf(
                'SELECT yyyymmdd, COUNT(*) AS n FROM %s WHERE source = ? AND site_id = ? GROUP BY yyyymmdd',
                \OWA\Core\CoreAPI::entityFactory( 'base.migration_day_visitor' )->getTableName() ),
                [ $source, $site_id ] ) as $row ) {

            $row = (array) $row;
            $day( (int) $row['yyyymmdd'] );
            $days[ (int) $row['yyyymmdd'] ]['visitors_expected'] = (int) $row['n'];
        }

        if ( $types ) {

            $in    = implode( ',', array_fill( 0, count( $types ), '?' ) );
            $span  = array_keys( $days );
            $where = sprintf( 'site_id = ? AND event_type IN (%s) AND ts <= %d AND yyyymmdd BETWEEN %d AND %d',
                $in, $bound, min( $span ), max( $span ) );
            $args  = array_merge( [ $site_id ], array_keys( $types ) );

            foreach ( $this->results( sprintf(
                    "SELECT yyyymmdd, event_type, COUNT(*) AS n, SUM(COALESCE(revenue, 0)) AS revenue,"
                  . " SUM(COALESCE(JSON_LENGTH(params, '$.items'), 0)) AS items FROM %s WHERE %s"
                  . ' GROUP BY yyyymmdd, event_type', $raw, $where ), $args ) as $row ) {

                $row  = (array) $row;
                $d    = (int) $row['yyyymmdd'];
                $type = (string) $row['event_type'];
                $day( $d );

                $days[ $d ]['types'][ $type ]['present']         = (int) $row['n'];
                $days[ $d ]['types'][ $type ]['revenue_present'] = (int) $row['revenue'];
                $days[ $d ]['types'][ $type ]['items_present']   = (int) $row['items'];
            }

            foreach ( $this->results( sprintf(
                    'SELECT yyyymmdd, COUNT(DISTINCT visitor_id) AS n FROM %s WHERE %s GROUP BY yyyymmdd', $raw, $where ),
                    $args ) as $row ) {

                $row = (array) $row;
                $day( (int) $row['yyyymmdd'] );
                $days[ (int) $row['yyyymmdd'] ]['visitors_present'] = (int) $row['n'];
            }
        }

        ksort( $days );

        return $days;
    }

    /**
     * The days that do not reconcile, as lines to print.
     *
     * @param  array<int, ReconcileDay> $days from reconcileSite()
     * @return string[]
     */
    public static function discrepancies( array $days ) {

        $lines = [];

        foreach ( $days as $d => $day ) {

            foreach ( $day['types'] as $type => $t ) {

                $expected = (int) ( $t['expected'] ?? 0 );
                $present  = (int) ( $t['present'] ?? 0 );

                if ( $expected !== $present ) {

                    $lines[] = sprintf( '%d %s: %d expected, %d in v2', $d, $type, $expected, $present );
                }

                if ( (int) ( $t['revenue_expected'] ?? 0 ) !== (int) ( $t['revenue_present'] ?? 0 ) ) {

                    $lines[] = sprintf( '%d %s revenue: %d expected, %d in v2 (minor units)', $d, $type,
                        (int) ( $t['revenue_expected'] ?? 0 ), (int) ( $t['revenue_present'] ?? 0 ) );
                }

                if ( (int) ( $t['items_expected'] ?? 0 ) !== (int) ( $t['items_present'] ?? 0 ) ) {

                    $lines[] = sprintf( '%d %s line items: %d expected, %d in v2', $d, $type,
                        (int) ( $t['items_expected'] ?? 0 ), (int) ( $t['items_present'] ?? 0 ) );
                }
            }

            if ( $day['visitors_expected'] !== $day['visitors_present'] ) {

                $lines[] = sprintf( '%d visitors: %d expected, %d in v2', $d,
                    $day['visitors_expected'], $day['visitors_present'] );
            }
        }

        return $lines;
    }

    /**
     * One line for a site that reconciles: days, rows read, refused, written.
     *
     * @param  array<int, ReconcileDay> $days from reconcileSite()
     * @return string
     */
    public static function summary( array $days ) {

        $read = $present = 0;
        $refused = [];

        foreach ( $days as $day ) {

            $read += $day['read'];

            foreach ( $day['refused'] as $reason => $n ) {

                $refused[ $reason ] = ( $refused[ $reason ] ?? 0 ) + $n;
            }

            foreach ( $day['types'] as $t ) {

                $present += (int) ( $t['present'] ?? 0 );
            }
        }

        $reasons = [];

        foreach ( $refused as $reason => $n ) {

            $reasons[] = $reason . ' ' . $n;
        }

        // More rows in v2 than v1 is expected: a session's first page view also
        // writes its session_start and first_visit markers.
        return sprintf( '%d %s, %d v1 %s, %d refused%s, %d %s in v2 with their markers',
            count( $days ), \OWA\Core\Lib::plural( count( $days ), 'day', 'days' ), $read, \OWA\Core\Lib::plural( $read, 'row', 'rows' ),
            array_sum( $refused ), $reasons ? ' (' . implode( ', ', $reasons ) . ')' : '',
            $present, \OWA\Core\Lib::plural( $present, 'row', 'rows' ) );
    }

    /**
     * How many line items a built row's params carry.
     *
     * @param  string|array<mixed>|null $params  JSON, or already decoded
     * @return int
     */
    protected static function itemCount( $params ) {

        $doc = is_array( $params ) ? $params : json_decode( (string) $params, true );

        return is_array( $doc ) && is_array( $doc['items'] ?? null ) ? count( $doc['items'] ) : 0;
    }

    /**
     * Drop a site's progress row and tally, so the pass starts over.
     *
     * @param  string $site_id
     * @return void
     */
    protected function forget( $site_id ) {

        $this->forgetTally( $site_id );

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );
        $entity->load( \OWA\Module\Base\Entity\MigrationProgress::idFor( static::progressKey(), $site_id ) );

        if ( $entity->wasPersisted() ) {

            $entity->delete();
        }
    }

    /**
     * Why a v1 row cannot become an event, or null.
     *
     * The same things the row builder refuses without, named so the run can
     * say how many of each it met.
     *
     * @param  Row $r
     * @return string|null
     */
    protected function refusal( array $r ) {

        if ( ! self::isId( $r['visitor_id'] ?? null ) ) {

            return self::NO_VISITOR;
        }

        if ( ! self::isId( $r['session_id'] ?? null ) ) {

            return self::NO_SESSION;
        }

        if ( (int) ( $r['timestamp'] ?? 0 ) <= 0 ) {

            return self::NO_TIMESTAMP;
        }

        if ( ! preg_match( '/^[12][0-9]{7}$/', (string) ( $r['yyyymmdd'] ?? '' ) ) ) {

            return self::NO_DAY;
        }

        return null;
    }

    /**
     * What one v1 row becomes.
     *
     * @param  Row  $r
     * @param  Refs $refs
     * @return \OWA\Module\Base\Classes\Event[] events, the row's own first
     */
    abstract protected function events( array $r, array $refs );

    /**
     * An event of $type carrying what every v1 fact row carries: identity,
     * time, the page, referrer, user agent, location, attribution and custom
     * variables, with the URL readings made by ingest's own callbacks.
     *
     * @param  Row    $r
     * @param  Refs   $refs
     * @param  string $type
     * @param  array<string, mixed> $extra  properties of this event type
     * @return \OWA\Module\Base\Classes\Event
     */
    protected function baseEvent( array $r, array $refs, $type, array $extra = [] ) {

        $doc      = $refs['document'][ (string) ( $r['document_id'] ?? '' ) ] ?? [];
        $referer  = $refs['referer'][ (string) ( $r['referer_id'] ?? '' ) ] ?? [];
        $ua       = $refs['ua'][ (string) ( $r['ua_id'] ?? '' ) ] ?? [];
        $os       = $refs['os'][ (string) ( $r['os_id'] ?? '' ) ] ?? [];
        $location = self::repairedLocation(
            $refs['location_dim'][ (string) ( $r['location_id'] ?? '' ) ] ?? [] );
        $session  = $refs['session'][ (string) $r['session_id'] ] ?? [];
        $visitor  = $refs['visitor'][ (string) $r['visitor_id'] ] ?? [];
        $prior    = isset( $session['prior_session_id'] )
            ? ( $refs['prior_session'][ (string) $session['prior_session_id'] ] ?? [] )
            : [];

        $event = new \OWA\Module\Base\Classes\Event;
        $event->setEventType( $type );

        $properties = [
            'site_id'    => (string) $r['site_id'],
            'visitor_id' => (string) $r['visitor_id'],
            'session_id' => (string) $r['session_id'],
            'ts'         => self::microseconds( $r ),
            'yyyymmdd'   => (int) $r['yyyymmdd'],
            'user_id'    => $r['user_name'] ?? null,

            'page_location'   => $doc['url'] ?? null,
            'page_title'      => $doc['page_title'] ?? null,
            // 1.x's page type is 2.0's content group: the dimension the Page
            // Types report groups by, and what the tracker's setPageType() sets.
            'content_group'   => $doc['page_type'] ?? null,
            'HTTP_REFERER'    => $referer['url'] ?? null,
            'HTTP_USER_AGENT' => $ua['ua'] ?? null,
            'browser_type'    => $ua['browser_type'] ?? null,
            'os'              => $os['name'] ?? ( $r['os'] ?? null ),
            'ip_address'      => self::ipAddress( $r['ip_address'] ?? null ),
            'language'        => self::language( $r['language'] ?? null ),

            'country'      => $location['country'] ?? null,
            'country_code' => $location['country_code'] ?? null,
            'city'         => $location['city'] ?? null,
            'state'        => $location['state'] ?? null,

            // Counted from v1's session table, not copied (priorSessions()).
            'num_prior_sessions' => $refs['prior_sessions'][ (string) $r['session_id'] ] ?? null,
            'fsts' => $visitor['first_session_timestamp'] ?? null,
            'sts'  => $session['timestamp'] ?? null,
            'psts' => $prior['timestamp'] ?? null,
        ] + $extra;

        $properties += $this->attribution( $r, $refs );

        // All five slots, whatever maxCustomVars says: the cap is a beacon's (EventRawHandlers::params()).
        for ( $i = 1; $i <= \OWA\Module\Base\Handler\EventRawHandlers::V1_CUSTOM_VARS; $i++ ) {

            $properties[ 'cv' . $i . '_name' ]  = $r[ 'cv' . $i . '_name' ] ?? null;
            $properties[ 'cv' . $i . '_value' ] = $r[ 'cv' . $i . '_value' ] ?? null;
        }

        // Decoded before the URL readings below are taken from them (v1Text()).
        foreach ( $properties as $name => $value ) {

            if ( is_string( $value ) ) {

                $properties[ $name ] = self::v1Text( $value );
            }
        }

        $event->setProperties( $properties );

        // The URL readings, by ingest's own callbacks.
        $helpers = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $event->set( 'page_path', $helpers::derivePagePath( null, $event ) );
        $event->set( 'page_query', $helpers::derivePageQuery( null, $event ) );
        $event->set( 'host', $helpers::deriveHost( null, $event ) );
        $event->set( 'referer_host', $helpers::deriveRefererHost( null, $event ) );
        $event->set( 'referer_query', $helpers::deriveRefererQuery( null, $event ) );

        return $event;
    }

    /**
     * v1's attribution, as the tags it came from -- or nothing.
     *
     * v1 stored a VERDICT: the browser applied a model and the row carries its
     * result. v2 stores evidence, and the cube classifies it. For a row that
     * recorded a campaign or an ad the verdict was the tags, so they go in as
     * tags. Every other row carries none, and the cube classifies it from the
     * migrated referrer as it would a live beacon.
     *
     * EXCEPT WHAT v1 FILLED IN ITSELF. A campaign link without utm_medium or
     * utm_source still got a medium and a source: v1 defaulted the medium to
     * `direct` or classified the referrer (1.x's resolveMedium()), and took the
     * source from the referring host (1.x's resolveSource()). Those are verdicts
     * too, and as tags they would be read as what the link said -- `direct`
     * matches no channel rule, so every such session was Unassigned. They are
     * dropped, and the cube derives both from the referrer. A link that did
     * say utm_medium=referral cannot be told from v1's reading and loses it;
     * with a referrer the cube reads referral again.
     *
     * @param  Row  $r
     * @param  Refs $refs
     * @return array<string, mixed> tracking properties
     */
    protected function attribution( array $r, array $refs ) {

        $campaign = $refs['campaign_dim'][ (string) ( $r['campaign_id'] ?? '' ) ]['name'] ?? null;
        $ad       = $refs['ad_dim'][ (string) ( $r['ad_id'] ?? '' ) ]['name'] ?? null;

        if ( ! self::present( $campaign ) && ! self::present( $ad ) ) {

            return [];
        }

        $medium = strtolower( trim( (string) ( $r['medium'] ?? '' ) ) );
        $source = strtolower( trim( (string) (
            $refs['source_dim'][ (string) ( $r['source_id'] ?? '' ) ]['source_domain'] ?? '' ) ) );

        $referer = $refs['referer'][ (string) ( $r['referer_id'] ?? '' ) ]['url'] ?? null;
        $host    = strtolower( (string) ( \OWA\Module\Base\Classes\V2Event::parseUrl( $referer )['host'] ?? '' ) );

        if ( $source === '(direct)' || ( $host !== '' && in_array( $source, [ $host, preg_replace( '/^www\./', '', $host ) ], true ) ) ) {

            $source = '';
        }

        return [
            'tagged_source'   => $source !== '' ? $source : null,
            'tagged_medium'   => $medium !== '' && ! in_array( $medium, self::GENERATED_MEDIUMS, true ) ? $medium : null,
            'tagged_campaign' => $campaign,
            'tagged_ad'       => $ad,
            'tagged_terms'    => $refs['search_term_dim'][ (string) ( $r['referring_search_term_id'] ?? '' ) ]['terms'] ?? null,
        ];
    }

    /**
     * A v1 location's names, with any double-encoded one undone, and its
     * country code a code or nothing.
     *
     * 1.x rows can hold MaxMind's names encoded a second time -- "MÃ¼nchen" for
     * "München". 1.14's repair-geo-encoding need not have been run, and v2 does
     * not have it, so the migration repairs them on the way through.
     *
     * v1 upper-cased the country code, its "(not set)" sentinel included, so
     * "(NOT SET)" got past the sentinel check and into a CHAR(2) column, which
     * strict mode refuses -- and with it the whole batch. A code is two letters;
     * anything else is not one, and is stored as no code.
     *
     * @param  array<mixed> $location  a v1 location_dim row, or none
     * @return array<mixed>
     */
    public static function repairedLocation( array $location ) {

        foreach ( [ 'country', 'state', 'city' ] as $column ) {

            $fixed = \OWA\Module\Base\Classes\GeoEncodingRepair::repair( $location[ $column ] ?? null );

            if ( $fixed !== null ) {

                $location[ $column ] = $fixed;
            }
        }

        if ( array_key_exists( 'country_code', $location ) ) {

            $code = trim( (string) $location['country_code'] );

            $location['country_code'] = preg_match( '/^[A-Za-z]{2}$/D', $code ) ? strtoupper( $code ) : null;
        }

        return $location;
    }

    /**
     * A 1.x text value as the text it was.
     *
     * 1.x stored tracked text HTML-escaped -- "Caf&eacute;", "Tom &amp; Jerry"
     * -- and 2.0 stores the text as sent and escapes it where it is shown
     * (Sanitize::cleanTrackingText()). Decoded here, a migrated title is the
     * same value as the one 2.0 records for that page now, and groups with it.
     *
     * @param string $value
     * @return string
     */
    public static function v1Text( $value ) {

        $value = (string) $value;

        return strpos( $value, '&' ) === false
            ? $value : html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /**
     * A v1 address, or a v1 proxy chain resolved the way ingest resolves one.
     *
     * 1.x sometimes stored the whole X-Forwarded-For chain -- "client, proxy,
     * ..." -- which is not an address and is longer than the column, so strict
     * mode would refuse the batch. A single address is kept as v1 stored it; 1.x already chose it.
     *
     * @param mixed $value  as v1 stored it
     * @return string|null
     */
    public static function ipAddress( $value ) {

        $value = trim( (string) $value );

        if ( $value === '' ) {

            return null;
        }

        if ( strpos( $value, ',' ) === false ) {

            return strlen( $value ) <= 45 ? $value : null;
        }

        $chosen = \OWA\Module\Base\Classes\TrackingEventHelpers::chooseIp( $value );

        return $chosen !== '' ? $chosen : null;
    }

    /**
     * A v1 language, unless it cannot be one.
     *
     * Kept as stored when it fits the column: ingest stores the first five
     * characters of Accept-Language, so "de,en" is as legitimate here as it
     * is live, and "(not set)" is ingest's own sentinel to drop. A 1.x row can
     * hold something else entirely -- a microtime -- longer than the column.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function language( $value ) {

        $value = trim( (string) $value );

        return $value !== '' && strlen( $value ) <= 16 ? $value : null;
    }

    /**
     * A stored value, as opposed to empty or v1's "(not set)".
     *
     * @param  mixed $value  as v1 stored it
     * @return bool
     */
    protected static function present( $value ) {

        return $value !== null && trim( (string) $value ) !== ''
            && $value !== \OWA\Module\Base\Classes\TrackingEventHelpers::ABSENT_VALUE_LABEL;
    }

    /**
     * The event's time in microseconds.
     *
     * v1 stores whole seconds (its msec column holds 0 or 1), and the v2 id
     * hashes the time, so two page views of one session in one second would
     * collapse into one row. The sub-second part is derived from the v1 row id:
     * deterministic, so a replayed batch derives the same ids.
     *
     * @param  Row $r
     * @return int
     */
    public static function microseconds( array $r ) {

        return (int) $r['timestamp'] * 1000000
            + hexdec( substr( md5( (string) $r['id'] ), 0, 8 ) ) % 1000000;
    }

    /**
     * Everything the batch's rows point at, keyed by the id as text.
     *
     * NEVER A PLAIN JOIN. A fact's foreign key is BIGINT on some installations
     * and VARCHAR(255) on others, against a BIGINT dimension id, and MySQL
     * compares a string with an integer as a double: a 64-bit hash above 2^53
     * then matches the wrong row. So ids are collected as text here and looked
     * up by exact value, in the key column's own type (lookup()).
     *
     * @param  list<Row> $rows
     * @return Refs table => [ id as text => row ]
     */
    protected function resolve( array $rows ) {

        $refs = [
            'document'     => $this->lookup( 'document', array_column( $rows, 'document_id' ), 'id, url, page_title, page_type' ),
            'referer'      => $this->lookup( 'referer', array_column( $rows, 'referer_id' ), 'id, url' ),
            'ua'           => $this->lookup( 'ua', array_column( $rows, 'ua_id' ), 'id, ua, browser_type' ),
            'os'           => $this->lookup( 'os', array_column( $rows, 'os_id' ), 'id, name' ),
            'location_dim' => $this->lookup( 'location_dim', array_column( $rows, 'location_id' ),
                                  'id, country, country_code, state, city' ),
            'session'      => $this->lookup( 'session', array_column( $rows, 'session_id' ),
                                  'id, timestamp, prior_session_id' ),
            'source_dim'   => $this->lookup( 'source_dim', array_column( $rows, 'source_id' ), 'id, source_domain' ),
            'campaign_dim' => $this->lookup( 'campaign_dim', array_column( $rows, 'campaign_id' ), 'id, name' ),
            'ad_dim'       => $this->lookup( 'ad_dim', array_column( $rows, 'ad_id' ), 'id, name' ),
            'search_term_dim' => $this->lookup( 'search_term_dim',
                                  array_column( $rows, 'referring_search_term_id' ), 'id, terms' ),
            'visitor'      => $this->lookup( 'visitor', array_column( $rows, 'visitor_id' ),
                                  'id, first_session_timestamp' ),
        ];

        $refs['prior_session'] = $this->lookup( 'session',
            array_column( $refs['session'], 'prior_session_id' ), 'id, timestamp' );

        $refs['prior_sessions'] = $this->priorSessions( $rows, $this->sessionStarts( $rows, $refs ) );

        return $refs;
    }

    /**
     * When each of the batch's sessions began, in seconds: its v1 session
     * row's timestamp, or where v1 lost that row, the earliest of the
     * session's rows in this batch.
     *
     * @param  list<Row> $rows
     * @param  Refs $refs resolve()'s, which a subclass may add to
     * @return array<int|string, int> session id as text => timestamp
     */
    protected function sessionStarts( array $rows, array &$refs ) {

        $starts = [];

        foreach ( $rows as $r ) {

            $sid = (string) $r['session_id'];

            if ( isset( $refs['session'][ $sid ]['timestamp'] ) ) {

                $starts[ $sid ] = (int) $refs['session'][ $sid ]['timestamp'];

            } elseif ( ! isset( $starts[ $sid ] ) || (int) $r['timestamp'] < $starts[ $sid ] ) {

                $starts[ $sid ] = (int) $r['timestamp'];
            }
        }

        return $starts;
    }

    /**
     * How many sessions each of the batch's visitors had on the site before
     * each of its sessions, counted from v1's owa_session.
     *
     * v1's own two readings cannot be used: num_prior_sessions counted the
     * current session in some releases (a new visitor read 1), is_new_visitor was
     * written 0 for every session in others, and the two disagree. Counting the rows v1 kept gives one rule for any history: a visitor's
     * first retained session has none before it, and is its first visit.
     *
     * A visitor's sessions are its owa_session rows and the sessions its page
     * views name, which include those v1 lost the row for; left out, each such
     * session would read as a first visit. A session with a row
     * starts at the row; one without, at its earliest page view, as
     * sessionStarts() has it. Two queries per batch, on each table's
     * visitor_id index.
     *
     * @param  list<Row> $rows
     * @param  array<int|string, int> $starts session id as text => timestamp
     * @return array<int|string, int> session id as text => count
     */
    protected function priorSessions( array $rows, array $starts ) {

        $visitors = [];

        foreach ( $rows as $r ) {

            if ( self::isId( $r['visitor_id'] ?? null ) ) {

                $visitors[ (string) $r['visitor_id'] ] = true;
            }
        }

        if ( ! $visitors ) {

            return [];
        }

        $ids     = array_keys( $visitors );
        $integer = $this->integerColumn( 'session', 'visitor_id' );

        $sessions = $this->results( sprintf(
            'SELECT id, site_id, visitor_id, timestamp FROM %s WHERE visitor_id IN (%s)',
            $this->v1Table( 'session' ),
            $integer ? implode( ',', $ids ) : implode( ',', array_fill( 0, count( $ids ), '?' ) ) ),
            $integer ? [] : array_map( 'strval', $ids ) );

        $integer = $this->integerColumn( self::REQUESTS, 'visitor_id' );

        $viewed = $this->results( sprintf(
            'SELECT session_id AS id, site_id, visitor_id, MIN(timestamp) AS timestamp FROM %s'
            . ' WHERE visitor_id IN (%s) AND timestamp > 0 GROUP BY site_id, visitor_id, session_id',
            $this->v1Table( self::REQUESTS ),
            $integer ? implode( ',', $ids ) : implode( ',', array_fill( 0, count( $ids ), '?' ) ) ),
            $integer ? [] : array_map( 'strval', $ids ) );

        // site|visitor => session id => [ start, id ]; a session row's start wins.
        $by = [];

        foreach ( [ $sessions, $viewed ] as $source ) {

            foreach ( $source as $row ) {

                $row = (array) $row;

                if ( ! self::isId( $row['id'] ) ) {

                    continue;
                }

                $key = $row['site_id'] . '|' . $row['visitor_id'];

                if ( ! isset( $by[ $key ][ (string) $row['id'] ] ) ) {

                    $by[ $key ][ (string) $row['id'] ] = [ (int) $row['timestamp'], (int) $row['id'] ];
                }
            }
        }

        $counts = [];

        foreach ( $rows as $r ) {

            $sid = (string) $r['session_id'];

            if ( isset( $counts[ $sid ] ) || ! isset( $starts[ $sid ] ) ) {

                continue;
            }

            // Earlier by time, then by id, as entries() orders a session's requests.
            $mine = [ $starts[ $sid ], (int) $sid ];
            $n    = 0;

            foreach ( $by[ $r['site_id'] . '|' . $r['visitor_id'] ] ?? [] as $other ) {

                if ( $other[1] !== $mine[1] && $other < $mine ) {

                    $n++;
                }
            }

            $counts[ $sid ] = $n;
        }

        return $counts;
    }

    /**
     * Rows of one v1 table by id, compared in the id column's own type.
     *
     * @param  string $table   unprefixed
     * @param  array<mixed> $ids  as read, of any type
     * @param  string $columns
     * @return array<int|string, Row>  id as text => row
     */
    protected function lookup( $table, array $ids, $columns ) {

        $ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), [ __CLASS__, 'isId' ] ) ) );

        if ( ! $ids ) {

            return [];
        }

        $name = V1Tables::name( $table, $this->prefix );

        if ( $this->integerKey( $table ) ) {

            // Validated as integers above, so inlined as integer literals: an
            // integer compared with an integer, exactly.
            $sql    = sprintf( 'SELECT %s FROM %s WHERE id IN (%s)', $columns, $name, implode( ',', $ids ) );
            $params = [];

        } else {

            $sql    = sprintf( 'SELECT %s FROM %s WHERE id IN (%s)', $columns, $name,
                          implode( ',', array_fill( 0, count( $ids ), '?' ) ) );
            $params = $ids;
        }

        $out = [];

        foreach ( $this->results( $sql, $params ) as $row ) {

            $row = (array) $row;
            $out[ (string) $row['id'] ] = $row;
        }

        return $out;
    }

    /**
     * A v1 table's name under the prefix this migrator reads.
     *
     * @param  string $table  unprefixed
     * @return string
     */
    protected function v1Table( $table ) {

        return V1Tables::name( $table, $this->prefix );
    }

    /**
     * Whether a v1 table's `id` column is an integer type on this installation.
     *
     * @param  string $table  unprefixed
     * @return bool
     */
    protected function integerKey( $table ) {

        return $this->integerColumn( $table, 'id' );
    }

    /**
     * Whether a v1 column is an integer type on this installation: the same
     * columns are BIGINT on one and VARCHAR on another (V1Schema).
     *
     * @param  string $table  unprefixed
     * @param  string $column
     * @return bool
     */
    protected function integerColumn( $table, $column ) {

        $key = $table . '.' . $column;

        if ( ! isset( $this->integer_keys[ $key ] ) ) {

            $row = (array) $this->db()->get_row( sprintf( "SHOW COLUMNS FROM %s LIKE '%s'",
                V1Tables::name( $table, $this->prefix ), preg_replace( '/[^a-z_]/', '', $column ) ) );

            $this->integer_keys[ $key ] = (bool) preg_match( '/int/i', (string) ( $row['Type'] ?? '' ) );
        }

        return $this->integer_keys[ $key ];
    }

    /**
     * A signed 64-bit integer written as text, other than zero.
     *
     * @param  mixed $value  as read from v1, of any type
     * @return bool
     */
    public static function isId( $value ) {

        if ( ! is_int( $value ) && ! is_string( $value ) ) {

            return false;
        }

        $value = (string) $value;

        if ( ! preg_match( '/^-?[1-9][0-9]{0,18}$/', $value ) ) {

            return false;
        }

        // Within signed 64-bit: PHP would otherwise turn it into a float.
        return (string) (int) $value === $value;
    }

    /**
     * The next batch of one site's v1 rows after $after, in id order.
     *
     * @param  string      $site_id
     * @param  string|null $after  the last id passed; null from the start
     * @return list<Row>
     */
    protected function read( $site_id, $after ) {

        $name = V1Tables::name( static::SOURCE, $this->prefix );

        $where = 'site_id = ?';

        if ( $this->since !== null ) {

            $where .= ' AND yyyymmdd >= ' . (int) $this->since;
        }

        if ( $after !== null && $after !== '' ) {

            if ( ! self::isId( $after ) ) {

                throw new \RuntimeException( sprintf( 'v1 migration: the recorded position %s is not an id.', $after ) );
            }

            $where .= $this->integerKey( static::SOURCE )
                ? ' AND id > ' . $after
                : ' AND CAST(id AS SIGNED) > ' . $after;
        }

        // Ordered the way the position is compared: numerically, whatever the
        // column's type. A VARCHAR id sorted as text would skip rows on resume.
        $order = $this->integerKey( static::SOURCE ) ? 'id' : 'CAST(id AS SIGNED)';

        return array_map( function ( $r ) { return (array) $r; }, $this->results(
            sprintf( 'SELECT * FROM %s WHERE %s ORDER BY %s LIMIT %d', $name, $where, $order, $this->batch ),
            [ (string) $site_id ] ) );
    }

    /**
     * Insert the rows, skipping any already there.
     *
     * ON DUPLICATE KEY UPDATE rather than INSERT IGNORE: IGNORE also turns an
     * over-long value into a silent truncation, and strict mode refusing it is
     * the point.
     *
     * @param  list<array<string, mixed>> $rows  raw rows, as build() made them
     * @return int|false rows newly written
     */
    protected function write( array $rows ) {

        if ( ! $rows ) {

            return 0;
        }

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        /*
         * When these rows reached raw -- now, for the whole batch -- so a
         * routine build sees that something arrived and rebuilds (created_at,
         * EventRaw). Without it a migration into days a cube has already
         * built would be skipped.
         */
        $now = (int) round( microtime( true ) * 1000000 );

        foreach ( $rows as $i => $row ) {

            $rows[ $i ]['created_at'] = $now;
        }

        $columns = array_keys( $rows[0] );

        $placeholders = '(' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')';

        $params = [];

        foreach ( $rows as $row ) {

            foreach ( $columns as $column ) {

                $params[] = $row[ $column ];
            }
        }

        $ok = $this->db()->query( sprintf(
            'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE id = id',
            $table,
            implode( ',', array_map( function ( $c ) { return '`' . $c . '`'; }, $columns ) ),
            implode( ',', array_fill( 0, count( $rows ), $placeholders ) ) ), $params );

        if ( $ok === false ) {

            return false;
        }

        /*
         * The affected-row count, not a COUNT before and after. A duplicate is
         * left unchanged by `id = id`, which MySQL and MariaDB report as 0
         * affected, so this is how many rows were new. The two counts it
         * replaces looked each id up in every partition: 0.3 s a batch warm,
         * 4 s cold, on a 63-partition table.
         */
        return (int) $this->db()->getAffectedRows();
    }

    /**
     * This site's progress row, loaded or new.
     *
     * @param  string $site_id
     * @return Progress
     */
    protected function progress( $site_id ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );
        $id     = \OWA\Module\Base\Entity\MigrationProgress::idFor( static::progressKey(), $site_id );

        $entity->load( $id );

        if ( $entity->wasPersisted() ) {

            $p = $entity->_getProperties();

            // NULL is stored as 0 by the numeric column; both mean all history.
            $recorded = (int) $p['since'] > 0 ? (int) $p['since'] : null;

            if ( $recorded !== $this->since ) {

                throw new \RuntimeException( sprintf(
                    'v1 migration: site %s was started with %s; running it with %s would leave a gap.',
                    $site_id,
                    $recorded === null ? 'all history' : 'since=' . $recorded,
                    $this->since === null ? 'all history' : 'since=' . $this->since ) );
            }
            $p['refusals'] = (array) json_decode( (string) $p['refusals'], true );
            $p['persisted'] = true;

            foreach ( [ 'rows_read', 'rows_written', 'rows_refused' ] as $k ) {

                $p[ $k ] = (int) $p[ $k ];
            }

            return $p;
        }

        return [
            'id'           => $id,
            'source'       => static::progressKey(),
            'site_id'      => (string) $site_id,
            'last_id'      => null,
            'since'        => $this->since,
            'rows_read'    => 0,
            'rows_written' => 0,
            'rows_refused' => 0,
            'refusals'     => [],
            'started_at'   => time(),
            'completed_at' => null,
            'persisted'    => false,
        ];
    }

    /**
     * Write the progress row, and mark it persisted.
     *
     * @param  Progress $progress
     * @return bool
     */
    protected function save( array &$progress ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );

        $values = $progress;
        unset( $values['persisted'] );
        $values['refusals'] = json_encode( (object) $progress['refusals'] );

        $entity->setProperties( $values );

        $ok = $progress['persisted'] ? $entity->update() : $entity->create();

        if ( $ok !== false ) {

            $progress['persisted'] = true;

            return true;
        }

        return false;
    }

    /** @return \OWA\Core\Db */
    protected function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }
}
