<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

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
 */
abstract class FactMigrator {

    /**
     * The mediums v1 assigned itself rather than read from a link: its
     * default, and its three readings of the referrer (resolveMedium()).
     */
    const GENERATED_MEDIUMS = array( 'direct', 'organic-search', 'social-network', 'referral' );

    /** The v1 table, unprefixed; each subclass names its own. */
    const SOURCE = '';

    /**
     * What progress is recorded under: the table, unless two passes read the
     * same one.
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

    private $prefix;

    private $batch;

    /** @var int|null the oldest day migrated, as yyyymmdd; null migrates all */
    private $since;

    /** @var array "table.column" => bool, whether that v1 column is an integer type */
    private $integer_keys = array();

    /**
     * @param string $prefix the prefix the v1 tables are read under
     * @param int    $batch  v1 rows per transaction
     * @param int|null $since the oldest day to migrate, as yyyymmdd; null for all
     */
    function __construct( $prefix = 'owa_', $batch = self::BATCH, $since = null ) {

        $this->prefix = $prefix;
        $this->batch  = max( 1, (int) $batch );
        $this->since  = $since === null ? null : (int) $since;
    }

    /**
     * How much v1 holds, per site and year: what the preflight shows before an
     * administrator chooses a cutoff.
     *
     * @return array[] site_id, year, rows, known (whether the site still exists)
     */
    public function volume() {

        $known = $this->knownSites();

        return array_map( function ( $r ) use ( $known ) {

            $r = (array) $r;

            return array( 'site_id' => (string) $r['site_id'], 'year' => (int) $r['year'], 'rows' => (int) $r['n'],
                'known' => isset( $known[ (string) $r['site_id'] ] ) );

        }, (array) $this->db()->get_results( sprintf(
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

        $row = (array) $this->db()->get_row( sprintf( 'SELECT MIN(yyyymmdd) AS d FROM %s WHERE yyyymmdd >= %d',
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

        $rows = (array) $this->db()->get_results( sprintf(
            'SELECT DISTINCT site_id FROM %s', V1Tables::name( static::SOURCE, $this->prefix ) ) );

        $known = $this->knownSites();

        return array_values( array_filter( array_map( function ( $r ) {

            return (string) ( (array) $r )['site_id'];

        }, $rows ), function ( $site_id ) use ( $known ) {

            return $site_id !== '' && isset( $known[ $site_id ] );
        } ) );
    }

    /** @return array site_id => true, for every site row there is */
    protected function knownSites() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName();

        $known = array();

        foreach ( (array) $this->db()->get_results( sprintf( 'SELECT site_id FROM %s', $table ) ) as $r ) {

            $known[ (string) ( (array) $r )['site_id'] ] = true;
        }

        return $known;
    }

    /**
     * Migrate one site's page views, from where the last run stopped.
     *
     * @param  string   $site_id
     * @param  int|null $max_batches stop after this many batches; null runs to the end
     * @return array    the progress row
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
     * @param string $site_id
     * @param array  $rows     v1 request rows, in id order
     * @param array  $progress updated in place
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
     * @return int|false how many rows it wrote
     */
    protected function apply( array $rows, array &$progress ) {

        $tally   = array();
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
     * @param array  $tally from build()
     * @return bool
     */
    protected function recordTally( $site_id, array $tally ) {

        $source  = static::progressKey();
        $table   = \OWA\Core\CoreAPI::entityFactory( 'base.migration_tally' )->getTableName();
        $entries = array();

        foreach ( $tally['read'] ?? array() as $d => $n ) {

            $entries[] = array( $d, 'read', '', $n, 0, 0, null );
        }

        foreach ( $tally['refused'] ?? array() as $d => $reasons ) {

            foreach ( $reasons as $reason => $n ) {

                $entries[] = array( $d, 'refused', $reason, $n, 0, 0, null );
            }
        }

        foreach ( $tally['events'] ?? array() as $d => $types ) {

            foreach ( $types as $type => $t ) {

                $entries[] = array( $d, 'event', $type, $t['n'], $t['revenue'], $t['items'], $t['max_ts'] );
            }
        }

        foreach ( $entries as list( $d, $kind, $name, $n, $revenue, $items, $max_ts ) ) {

            // Added to rather than replaced: a day spans batches.
            $ok = $this->db()->query( sprintf(
                'INSERT INTO %s (id, source, site_id, yyyymmdd, kind, name, n, revenue, items, max_ts)'
              . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = n + ?, revenue = revenue + ?,'
              . ' items = items + ?, max_ts = GREATEST(COALESCE(max_ts, 0), COALESCE(?, 0))', $table ),
                array( \OWA\Module\Base\Entity\MigrationTally::idFor( $source, $site_id, $d, $kind, $name ),
                    $source, $site_id, (int) $d, $kind, $name, (int) $n, (int) $revenue, (int) $items, $max_ts,
                    (int) $n, (int) $revenue, (int) $items, $max_ts ) );

            if ( $ok === false ) {

                return false;
            }
        }

        $visitors = array();

        foreach ( $tally['visitors'] ?? array() as $d => $ids ) {

            foreach ( array_keys( $ids ) as $visitor_id ) {

                $visitors[] = array( \OWA\Module\Base\Entity\MigrationDayVisitor::idFor( $source, $site_id, $d, $visitor_id ),
                    $source, $site_id, (int) $d, (string) $visitor_id );
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

    /** Drop a site's tally for this pass: it starts over, or is reverted. */
    protected function forgetTally( $site_id ) {

        foreach ( array( 'base.migration_tally', 'base.migration_day_visitor' ) as $entity ) {

            $this->db()->query( sprintf( 'DELETE FROM %s WHERE source = ? AND site_id = ?',
                \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName() ),
                array( static::progressKey(), (string) $site_id ) );
        }
    }

    /**
     * The raw rows a batch of v1 rows becomes, counting refusals into $progress.
     *
     * @return array[]
     */
    protected function build( array $rows, array &$progress, ?array &$tally = null ) {

        $refs = $this->resolve( $rows );

        $out = array();

        foreach ( $rows as $r ) {

            $progress['rows_read']++;

            $refused = $this->refusal( $r );

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

            foreach ( $this->events( $r, $refs ) as $event ) {

                $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $event );

                if ( ! $row ) {

                    continue;
                }

                $out[] = $row;

                if ( $tally !== null ) {

                    $d    = (int) $row['yyyymmdd'];
                    $type = (string) $row['event_type'];
                    $t    = $tally['events'][ $d ][ $type ] ?? array( 'n' => 0, 'revenue' => 0, 'items' => 0, 'max_ts' => 0 );

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
     * Undo one site's migration: delete exactly the raw rows it wrote.
     *
     * The ids are derived from the v1 rows, which the migration never
     * touches, so reading them again with the cutoff the site was migrated
     * with derives the same ids. Nothing else in owa_event_raw is touched.
     * Once v1 is dropped there is nothing to roll back to.
     *
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
        $scratch = array( 'rows_read' => 0, 'rows_refused' => 0, 'refusals' => array() );

        while ( $rows = $this->read( $site_id, $after ) ) {

            $ids = array_map( 'intval', array_column( $this->build( $rows, $scratch ), 'id' ) );

            if ( $ids ) {

                $before = $this->count( $table, array_map( function ( $id ) { return array( 'id' => $id ); }, $ids ) );

                if ( $this->db()->query( sprintf( 'DELETE FROM %s WHERE id IN (%s)', $table, implode( ',', $ids ) ) ) === false ) {

                    throw new \RuntimeException( sprintf( 'v1 migration: reverting site %s failed.', $site_id ) );
                }

                $deleted += $before;
            }

            $last  = end( $rows );
            $after = (string) $last['id'];
        }

        $entity->delete();
        $this->forgetTally( $site_id );

        return $deleted;
    }

    /**
     * What v1 holds for one site against what reached owa_event_raw, per day
     * (PLAN.html 2.22).
     *
     * The expected side is what each batch recorded as it wrote
     * (recordTally()): per day, v1 rows read and refused, and per event type
     * the rows written with their revenue and line items, and the visitors.
     * The present side is counted from raw: this pass's event types, up to the
     * latest ts it wrote -- v1 stopped before v2 began recording, so a row
     * after that is live traffic, not migrated history.
     *
     * Counted, not rebuilt. Reconciliation used to read v1 again and rebuild
     * every row to know what to expect, which cost as much as the migration:
     * 26 of 54 minutes on a real 1.x install.
     *
     * A site reconciles when every expected row is present with the same
     * revenue and visitors. Refused rows are the accounted-for difference.
     *
     * @return array|null yyyymmdd => day; null where the pass writes no raw rows
     */
    public function reconcileSite( $site_id ) {

        $source  = static::progressKey();
        $site_id = (string) $site_id;
        $raw     = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $days    = array();
        $types   = array();
        $bound   = 0;

        $day = function ( $d ) use ( &$days ) {

            if ( ! isset( $days[ $d ] ) ) {

                $days[ $d ] = array( 'read' => 0, 'refused' => array(), 'types' => array(),
                    'visitors_expected' => 0, 'visitors_present' => 0 );
            }
        };

        foreach ( (array) $this->db()->get_results( sprintf(
                'SELECT yyyymmdd, kind, name, n, revenue, items, max_ts FROM %s WHERE source = ? AND site_id = ?',
                \OWA\Core\CoreAPI::entityFactory( 'base.migration_tally' )->getTableName() ),
                array( $source, $site_id ) ) as $row ) {

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

        foreach ( (array) $this->db()->get_results( sprintf(
                'SELECT yyyymmdd, COUNT(*) AS n FROM %s WHERE source = ? AND site_id = ? GROUP BY yyyymmdd',
                \OWA\Core\CoreAPI::entityFactory( 'base.migration_day_visitor' )->getTableName() ),
                array( $source, $site_id ) ) as $row ) {

            $row = (array) $row;
            $day( (int) $row['yyyymmdd'] );
            $days[ (int) $row['yyyymmdd'] ]['visitors_expected'] = (int) $row['n'];
        }

        if ( $types ) {

            $in    = implode( ',', array_fill( 0, count( $types ), '?' ) );
            $span  = array_keys( $days );
            $where = sprintf( 'site_id = ? AND event_type IN (%s) AND ts <= %d AND yyyymmdd BETWEEN %d AND %d',
                $in, $bound, min( $span ), max( $span ) );
            $args  = array_merge( array( $site_id ), array_keys( $types ) );

            foreach ( (array) $this->db()->get_results( sprintf(
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

            foreach ( (array) $this->db()->get_results( sprintf(
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
     * @param  array $days from reconcileSite()
     * @return string[]
     */
    public static function discrepancies( array $days ) {

        $lines = array();

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
     * @param  array $days from reconcileSite()
     * @return string
     */
    public static function summary( array $days ) {

        $read = $present = 0;
        $refused = array();

        foreach ( $days as $day ) {

            $read += $day['read'];

            foreach ( $day['refused'] as $reason => $n ) {

                $refused[ $reason ] = ( $refused[ $reason ] ?? 0 ) + $n;
            }

            foreach ( $day['types'] as $t ) {

                $present += (int) ( $t['present'] ?? 0 );
            }
        }

        $reasons = array();

        foreach ( $refused as $reason => $n ) {

            $reasons[] = $reason . ' ' . $n;
        }

        // More rows in v2 than v1 is expected: a session's first page view also
        // writes its session_start and first_visit markers.
        return sprintf( '%d day(s), %d v1 row(s), %d refused%s, %d row(s) in v2 with their markers',
            count( $days ), $read, array_sum( $refused ), $reasons ? ' (' . implode( ', ', $reasons ) . ')' : '',
            $present );
    }

    /**
     * How many line items a built row's params carry.
     *
     * @param  string|array|null $params
     * @return int
     */
    protected static function itemCount( $params ) {

        $doc = is_array( $params ) ? $params : json_decode( (string) $params, true );

        return is_array( $doc ) && is_array( $doc['items'] ?? null ) ? count( $doc['items'] ) : 0;
    }

    /** Drop a site's progress row and tally, so the pass starts over. */
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
     * @return object[] events, the row's own first
     */
    abstract protected function events( array $r, array $refs );

    /**
     * An event of $type carrying what every v1 fact row carries: identity,
     * time, the page, referrer, user agent, location, attribution and custom
     * variables, with the URL readings made by ingest's own callbacks.
     *
     * @return \OWA\Module\Base\Classes\Event
     */
    protected function baseEvent( array $r, array $refs, $type, array $extra = array() ) {

        $doc      = $refs['document'][ (string) ( $r['document_id'] ?? '' ) ] ?? array();
        $referer  = $refs['referer'][ (string) ( $r['referer_id'] ?? '' ) ] ?? array();
        $ua       = $refs['ua'][ (string) ( $r['ua_id'] ?? '' ) ] ?? array();
        $os       = $refs['os'][ (string) ( $r['os_id'] ?? '' ) ] ?? array();
        $location = self::repairedLocation(
            $refs['location_dim'][ (string) ( $r['location_id'] ?? '' ) ] ?? array() );
        $session  = $refs['session'][ (string) $r['session_id'] ] ?? array();
        $visitor  = $refs['visitor'][ (string) $r['visitor_id'] ] ?? array();
        $prior    = isset( $session['prior_session_id'] )
            ? ( $refs['prior_session'][ (string) $session['prior_session_id'] ] ?? array() )
            : array();

        $event = new \OWA\Module\Base\Classes\Event;
        $event->setEventType( $type );

        $properties = array(
            'site_id'    => (string) $r['site_id'],
            'visitor_id' => (string) $r['visitor_id'],
            'session_id' => (string) $r['session_id'],
            'ts'         => self::microseconds( $r ),
            'yyyymmdd'   => (int) $r['yyyymmdd'],
            'user_id'    => $r['user_name'] ?? null,

            'page_location'   => $doc['url'] ?? null,
            'page_title'      => $doc['page_title'] ?? null,
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
        ) + $extra;

        $properties += $this->attribution( $r, $refs );

        for ( $i = 1; $i <= 5; $i++ ) {

            $properties[ 'cv' . $i . '_name' ]  = $r[ 'cv' . $i . '_name' ] ?? null;
            $properties[ 'cv' . $i . '_value' ] = $r[ 'cv' . $i . '_value' ] ?? null;
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
     * migrated referrer as it would a live beacon (PLAN.html 2.21).
     *
     * EXCEPT WHAT v1 FILLED IN ITSELF. A campaign link without utm_medium or
     * utm_source still got a medium and a source: v1 defaulted the medium to
     * `direct` or classified the referrer (resolveMedium()), and took the
     * source from the referring host (resolveSource()). Those are verdicts
     * too, and as tags they would be read as what the link said -- `direct`
     * matches no channel rule, so every such session was Unassigned. They are
     * dropped, and the cube derives both from the referrer. A link that did
     * say utm_medium=referral cannot be told from v1's reading and loses it;
     * with a referrer the cube reads referral again.
     *
     * @return array tracking properties
     */
    protected function attribution( array $r, array $refs ) {

        $campaign = $refs['campaign_dim'][ (string) ( $r['campaign_id'] ?? '' ) ]['name'] ?? null;
        $ad       = $refs['ad_dim'][ (string) ( $r['ad_id'] ?? '' ) ]['name'] ?? null;

        if ( ! self::present( $campaign ) && ! self::present( $ad ) ) {

            return array();
        }

        $medium = strtolower( trim( (string) ( $r['medium'] ?? '' ) ) );
        $source = strtolower( trim( (string) (
            $refs['source_dim'][ (string) ( $r['source_id'] ?? '' ) ]['source_domain'] ?? '' ) ) );

        $referer = $refs['referer'][ (string) ( $r['referer_id'] ?? '' ) ]['url'] ?? null;
        $host    = strtolower( (string) ( \OWA\Module\Base\Classes\V2Event::parseUrl( $referer )['host'] ?? '' ) );

        if ( $source === '(direct)' || ( $host !== '' && in_array( $source, array( $host, preg_replace( '/^www\./', '', $host ) ), true ) ) ) {

            $source = '';
        }

        return array(
            'tagged_source'   => $source !== '' ? $source : null,
            'tagged_medium'   => $medium !== '' && ! in_array( $medium, self::GENERATED_MEDIUMS, true ) ? $medium : null,
            'tagged_campaign' => $campaign,
            'tagged_ad'       => $ad,
            'tagged_terms'    => $refs['search_term_dim'][ (string) ( $r['referring_search_term_id'] ?? '' ) ]['terms'] ?? null,
        );
    }

    /**
     * A v1 location's names, with any double-encoded one undone, and its
     * country code a code or nothing.
     *
     * The geolocation reader once encoded MaxMind's names a second time --
     * "MÃ¼nchen" for "München" (#742). 1.14 ships repair-geo-encoding for the
     * rows already stored, but nothing makes an administrator run it, and v2
     * does not have it, so the migration repairs them on the way through.
     *
     * v1 upper-cased the country code, its "(not set)" sentinel included, so
     * "(NOT SET)" got past the sentinel check and into a CHAR(2) column, which
     * strict mode refuses -- and with it the whole batch. Measured on a 1.x
     * install: 745 of 11,741 locations, plus one "VATICAN CITY STATE)". A code
     * is two letters; anything else is not one, and is stored as no code.
     */
    public static function repairedLocation( array $location ) {

        foreach ( array( 'country', 'state', 'city' ) as $column ) {

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
     * A v1 address, or a v1 proxy chain resolved the way ingest resolves one.
     *
     * 1.x sometimes stored the whole X-Forwarded-For chain -- "client, proxy,
     * ..." -- which is not an address and is longer than the column: strict
     * mode refused the batch. Measured on a 1.x install: 5 of 724,000 rows.
     * A single address is kept as v1 stored it; 1.x already chose it.
     *
     * @param mixed $value
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
     * is live, and "(not set)" is ingest's own sentinel to drop. One row
     * measured on a 1.x install holds "0.20504800 1616979699" -- a microtime,
     * longer than any language ingest writes and than the column.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function language( $value ) {

        $value = trim( (string) $value );

        return $value !== '' && strlen( $value ) <= 16 ? $value : null;
    }

    /** A stored value, as opposed to empty or v1's "(not set)". */
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
     * @return array table => [ id as text => row ]
     */
    protected function resolve( array $rows ) {

        $refs = array(
            'document'     => $this->lookup( 'document', array_column( $rows, 'document_id' ), 'id, url, page_title' ),
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
        );

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
     * @param  array $rows
     * @param  array $refs resolve()'s, which a subclass may add to
     * @return array session id as text => timestamp
     */
    protected function sessionStarts( array $rows, array &$refs ) {

        $starts = array();

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
     * v1's own two readings cannot be used. num_prior_sessions counted the
     * current session for years (a new visitor read 1), and is_new_visitor was
     * written 0 for every session for others; on a real 1.x install the two
     * disagree with each other from 2016 to 2019, and each is wrong somewhere.
     * Counting the rows v1 kept gives one rule for any history: a visitor's
     * first retained session has none before it, and is its first visit.
     *
     * A session v1 lost the row for is not counted as an earlier session of
     * a later one. One query per batch, on owa_session's visitor_id index.
     *
     * @param  array $rows
     * @param  array $starts session id as text => timestamp
     * @return array session id as text => count
     */
    protected function priorSessions( array $rows, array $starts ) {

        $visitors = array();

        foreach ( $rows as $r ) {

            if ( self::isId( $r['visitor_id'] ?? null ) ) {

                $visitors[ (string) $r['visitor_id'] ] = true;
            }
        }

        if ( ! $visitors ) {

            return array();
        }

        $ids     = array_keys( $visitors );
        $integer = $this->integerColumn( 'session', 'visitor_id' );

        $sessions = (array) $this->db()->get_results( sprintf(
            'SELECT id, site_id, visitor_id, timestamp FROM %s WHERE visitor_id IN (%s)',
            $this->v1Table( 'session' ),
            $integer ? implode( ',', $ids ) : implode( ',', array_fill( 0, count( $ids ), '?' ) ) ),
            $integer ? array() : array_map( 'strval', $ids ) );

        $by = array();

        foreach ( $sessions as $row ) {

            $row = (array) $row;
            $by[ $row['site_id'] . '|' . $row['visitor_id'] ][] = array( (int) $row['timestamp'], (int) $row['id'] );
        }

        $counts = array();

        foreach ( $rows as $r ) {

            $sid = (string) $r['session_id'];

            if ( isset( $counts[ $sid ] ) || ! isset( $starts[ $sid ] ) ) {

                continue;
            }

            // Earlier by time, then by id, as entries() orders a session's requests.
            $mine = array( $starts[ $sid ], (int) $sid );
            $n    = 0;

            foreach ( $by[ $r['site_id'] . '|' . $r['visitor_id'] ] ?? array() as $other ) {

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
     * @param  array  $ids     as read, of any type
     * @param  string $columns
     * @return array  id as text => row
     */
    protected function lookup( $table, array $ids, $columns ) {

        $ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), array( __CLASS__, 'isId' ) ) ) );

        if ( ! $ids ) {

            return array();
        }

        $name = V1Tables::name( $table, $this->prefix );

        if ( $this->integerKey( $table ) ) {

            // Validated as integers above, so inlined as integer literals: an
            // integer compared with an integer, exactly.
            $sql    = sprintf( 'SELECT %s FROM %s WHERE id IN (%s)', $columns, $name, implode( ',', $ids ) );
            $params = array();

        } else {

            $sql    = sprintf( 'SELECT %s FROM %s WHERE id IN (%s)', $columns, $name,
                          implode( ',', array_fill( 0, count( $ids ), '?' ) ) );
            $params = $ids;
        }

        $out = array();

        foreach ( (array) $this->db()->get_results( $sql, $params ) as $row ) {

            $row = (array) $row;
            $out[ (string) $row['id'] ] = $row;
        }

        return $out;
    }

    /** A v1 table's name under the prefix this migrator reads. */
    protected function v1Table( $table ) {

        return V1Tables::name( $table, $this->prefix );
    }

    /** Whether a v1 table's `id` column is an integer type on this installation. */
    protected function integerKey( $table ) {

        return $this->integerColumn( $table, 'id' );
    }

    /**
     * Whether a v1 column is an integer type on this installation: the same
     * columns are BIGINT on one and VARCHAR on another (V1Schema).
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

    /** A signed 64-bit integer written as text, other than zero. */
    public static function isId( $value ) {

        $value = (string) $value;

        if ( ! preg_match( '/^-?[1-9][0-9]{0,18}$/', $value ) ) {

            return false;
        }

        // Within signed 64-bit: PHP would otherwise turn it into a float.
        return (string) (int) $value === $value;
    }

    /** The next batch of one site's v1 rows after $after, in id order. */
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

        return array_map( function ( $r ) { return (array) $r; }, (array) $this->db()->get_results(
            sprintf( 'SELECT * FROM %s WHERE %s ORDER BY %s LIMIT %d', $name, $where, $order, $this->batch ),
            array( (string) $site_id ) ) );
    }

    /**
     * Insert the rows, skipping any already there.
     *
     * ON DUPLICATE KEY UPDATE rather than INSERT IGNORE: IGNORE also turns an
     * over-long value into a silent truncation, and strict mode refusing it is
     * the point.
     *
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

        $params = array();

        foreach ( $rows as $row ) {

            foreach ( $columns as $column ) {

                $params[] = $row[ $column ];
            }
        }

        $before = $this->count( $table, $rows );

        $ok = $this->db()->query( sprintf(
            'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE id = id',
            $table,
            implode( ',', array_map( function ( $c ) { return '`' . $c . '`'; }, $columns ) ),
            implode( ',', array_fill( 0, count( $rows ), $placeholders ) ) ), $params );

        if ( $ok === false ) {

            return false;
        }

        return $this->count( $table, $rows ) - $before;
    }

    /** How many of these rows' ids are already stored. */
    protected function count( $table, array $rows ) {

        $ids = array_map( 'intval', array_column( $rows, 'id' ) );

        $row = (array) $this->db()->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s WHERE id IN (%s)',
            $table, implode( ',', $ids ) ) );

        return (int) ( $row['n'] ?? 0 );
    }

    /** This site's progress row, loaded or new. */
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

            foreach ( array( 'rows_read', 'rows_written', 'rows_refused' ) as $k ) {

                $p[ $k ] = (int) $p[ $k ];
            }

            return $p;
        }

        return array(
            'id'           => $id,
            'source'       => static::progressKey(),
            'site_id'      => (string) $site_id,
            'last_id'      => null,
            'since'        => $this->since,
            'rows_read'    => 0,
            'rows_written' => 0,
            'rows_refused' => 0,
            'refusals'     => array(),
            'started_at'   => time(),
            'completed_at' => null,
            'persisted'    => false,
        );
    }

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

    protected function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }
}

?>
