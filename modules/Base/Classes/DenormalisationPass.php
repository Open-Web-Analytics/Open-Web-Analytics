<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The pass: raw events in one partition, enriched into owa_event.
 *
 * One statement and a swap. The statement builds every row of the partition
 * into a staging table; the swap exchanges that table with the live partition.
 * It creates nothing, deletes nothing and updates nothing in place, so rows in
 * equals rows out and a mismatch is a bug -- checked below, before the swap.
 *
 * IT RUNS ENTIRELY IN THE DATABASE. No row passes through PHP. The same shape
 * is a bulk load and a partition replace on a columnar engine, which is what
 * lets owa_event move to one later.
 *
 * CONVERGENT, NOT INCREMENTAL. Running it twice on a partition produces the
 * same partition, so the scheduler -- which is level-triggered and runs a
 * missed occurrence once -- needs no bookkeeping from it. A watermark-advancing
 * pass was rejected for the same reason: an event arriving after the watermark
 * passed its own timestamp would not be late, it would be lost.
 *
 * WHAT IT READS OUTSIDE THE PARTITION
 *   - the visitor store, for acquisition, which originates in a first session
 *     that may be years old;
 *   - one day before the partition, so a session or a visitor's previous event
 *     that started just before the boundary is still visible. Only rows inside
 *     the partition are written.
 *
 * Terminal values stay unstamped until the session closes and are filled by a
 * later rebuild. There is no session cap in the window to wait for:
 * session_length is an idle timeout, not a duration, so a session with a hit
 * every 25 minutes runs indefinitely.
 */
class DenormalisationPass {

    /**
     * Days of raw read before the partition starts.
     *
     * Covers a session, or a gap to a visitor's previous event, that straddles
     * the boundary. A session is bounded by the idle timeout and split at
     * midnight, so one day is far more slack than either needs; the cost is one
     * extra partition read.
     */
    const LOOKBACK_DAYS = 1;

    /** Suffix of the staging table, built and dropped around each swap. */
    const STAGING_SUFFIX = '_rebuild';

    /**
     * The longest a domain name can be, per RFC 1035.
     *
     * Shorter than the source column, so a host that passes cannot overflow it
     * and nothing needs truncating. EventEntityTest pins that relationship.
     */
    const MAX_HOSTNAME = 253;

    /** @var \OWA\Core\Db */
    protected $db;

    /** @var array table name by role */
    protected $tables = array();

    function __construct() {

        $this->db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( array(
            'target'   => 'base.event',
            'raw'      => 'base.event_raw',
            'visitors' => 'base.visitor_acquisition',
        ) as $role => $entity ) {

            $this->tables[ $role ] =
                \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName();
        }

        $this->tables['staging'] = $this->tables['target'] . self::STAGING_SUFFIX;
    }

    /**
     * The partitions of owa_event covering a date range, oldest first.
     *
     * A partition is the unit of work because the swap is, so a request for one
     * day rebuilds the partition holding it. The catch-all is never returned:
     * exchanging into it would put rows in a partition that does not describe
     * them, and a row reaching it at all is a rotation failure to fix rather
     * than to build on.
     *
     * @param int $from yyyymmdd
     * @param int $to   yyyymmdd, inclusive
     * @return array of ['name','start','less_than']
     */
    public function partitions( $from, $to ) {

        $spans = array();

        foreach ( $this->db->getPartitionSpans( $this->tables['target'] ) as $span ) {

            // less_than is exclusive, start inclusive.
            if ( (int) $span['less_than'] > (int) $from && (int) $span['start'] <= (int) $to ) {

                $spans[] = $span;
            }
        }

        return $spans;
    }

    /**
     * Rebuild one partition.
     *
     * @param array $span    from partitions()
     * @param bool  $dry_run build the statement, run nothing
     * @return array ['ok','partition','rows','sql']
     */
    public function rebuild( array $span, $dry_run = false ) {

        $built_at = (int) round( microtime( true ) * 1000000 );

        $sql = $this->buildSql( $span, $built_at, $this->closedBefore( $built_at ) );

        $result = array(
            'ok'        => false,
            'partition' => $span['name'],
            'rows'      => 0,
            'sql'       => $sql,
        );

        if ( $dry_run ) {

            $result['ok'] = true;

            return $result;
        }

        if ( ! $this->makeStaging() ) {

            return $result;
        }

        if ( $this->db->query( $sql ) === false ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Denormalisation pass: building %s failed; %s is unchanged.',
                $span['name'], $this->tables['target'] ) );

            $this->dropStaging();

            return $result;
        }

        $expected = $this->countRaw( $span );
        $built    = $this->countStaging();

        /*
         * The pass enriches; it does not create or drop. A mismatch means the
         * statement is wrong -- a join multiplying rows, a predicate excluding
         * them -- and swapping it in would publish that. Staging is discarded
         * and the live partition stays as it was.
         */
        if ( $built !== $expected ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Denormalisation pass: %s built %d rows from %d raw rows. Not swapped.',
                $span['name'], $built, $expected ) );

            $this->dropStaging();

            return $result;
        }

        if ( ! $this->db->exchangePartition(
                $this->tables['target'], $span['name'], $this->tables['staging'] ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Denormalisation pass: exchanging %s failed; %s is unchanged.',
                $span['name'], $this->tables['target'] ) );

            $this->dropStaging();

            return $result;
        }

        // Staging now holds the partition's previous contents.
        $this->dropStaging();

        $this->advanceLastSeen( $span );

        $result['ok']   = true;
        $result['rows'] = $built;

        return $result;
    }

    /**
     * An empty, unpartitioned copy of owa_event.
     *
     * Dropped first: a run that died before its swap left one behind, and it
     * holds a half-built partition that must not be swapped in.
     *
     * @return bool
     */
    protected function makeStaging() {

        $this->dropStaging();

        if ( ! $this->db->createTableLike( $this->tables['staging'], $this->tables['target'] ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Denormalisation pass: could not create %s.', $this->tables['staging'] ) );

            return false;
        }

        // CREATE TABLE LIKE copies the partitioning, and EXCHANGE PARTITION
        // refuses a partitioned table.
        if ( ! $this->db->removePartitioning( $this->tables['staging'] ) ) {

            \OWA\Core\CoreAPI::error( sprintf(
                'Denormalisation pass: could not flatten %s.', $this->tables['staging'] ) );

            $this->dropStaging();

            return false;
        }

        return true;
    }

    /** @return bool */
    protected function dropStaging() {

        return (bool) $this->db->query( sprintf(
            OWA_SQL_DROP_TABLE, $this->tables['staging'] ) );
    }

    /**
     * The instant a session must have ended before to count as closed.
     *
     * @param int $now microseconds
     * @return int microseconds
     */
    protected function closedBefore( $now ) {

        $length = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'session_length' );

        if ( $length <= 0 ) {

            $length = 1800;
        }

        return $now - ( $length * 1000000 );
    }

    /**
     * The INSERT ... SELECT that builds one partition.
     *
     * @param array $span
     * @param int   $built_at      microseconds
     * @param int   $closed_before microseconds
     * @return string
     */
    public function buildSql( array $span, $built_at, $closed_before ) {

        $raw_columns = $this->rawColumns();

        $start    = (int) $span['start'];
        $end      = (int) $span['less_than'];
        $lookback = (int) gmdate( 'Ymd',
            strtotime( $start . ' -' . self::LOOKBACK_DAYS . ' day' ) );

        $select = array();

        foreach ( $raw_columns as $column ) {

            $select[] = 'e.' . $column;
        }

        $session_host = $this->hostExpression( 'e.s_referer_url' );
        $acq_host     = $this->hostExpression( 'v.acq_referer_url' );
        $unresolved   = $this->literal( V2Event::UNRESOLVED );

        $select[] = $this->sourceExpression( 'e.s_tagged_source', $session_host );
        $select[] = $this->mediumExpression( 'e.s_tagged_medium', $session_host );
        $select[] = $this->textExpression( 'e.s_tagged_campaign' );
        $select[] = $this->textExpression( 'e.s_tagged_ad' );

        /*
         * search_terms is the tag only. §2.1 also gives it the search engine's
         * own query parameter as a fallback, which is not built here: pulling a
         * named parameter out of a referring URL needs percent-decoding, and
         * SQL has no operator for it -- storing the encoded form would make one
         * column hold two spellings of the same phrase. Resolving it at ingest
         * is the alternative, and it moves a list-driven reading into the layer
         * that is never rebuilt.
         */
        $select[] = $this->textExpression( 'e.s_tagged_search_terms' );

        $select[] = 'e.lp_location';
        $select[] = 'e.lp_path';
        $select[] = 'e.lp_query';
        $select[] = 'e.lp_title';

        $select[] = sprintf(
            'CASE WHEN e.id = e.session_last_id AND e.session_last_ts < %d THEN 1 ELSE 0 END',
            (int) $closed_before );

        // The sentinel is written where the visitor has no row at all. A row
        // holding NULL in one column is an ordinary absence in that column.
        $select[] = sprintf( 'CASE WHEN v.visitor_id IS NULL THEN %s ELSE %s END',
            $unresolved, $this->sourceExpression( 'v.acq_source', $acq_host ) );
        $select[] = sprintf( 'CASE WHEN v.visitor_id IS NULL THEN %s ELSE %s END',
            $unresolved, $this->mediumExpression( 'v.acq_medium', $acq_host ) );
        $select[] = sprintf( 'CASE WHEN v.visitor_id IS NULL THEN %s ELSE %s END',
            $unresolved, $this->textExpression( 'v.acq_campaign' ) );
        $select[] = sprintf( 'CASE WHEN v.visitor_id IS NULL THEN %s ELSE %s END',
            $unresolved, $this->textExpression( 'v.acq_ad' ) );
        $select[] = $this->textExpression( 'v.acq_search_terms' );

        $select[] = 'e.prev_event_ts';
        $select[] = (string) (int) $built_at;

        return sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM (%s) e '
          . 'LEFT OUTER JOIN %s v ON v.visitor_id = e.visitor_id '
          . 'WHERE e.yyyymmdd >= %d AND e.yyyymmdd < %d',
            $this->tables['staging'],
            implode( ', ', array_merge( $raw_columns, $this->derivedColumns() ) ),
            implode( ', ', $select ),
            $this->windowedRaw( $lookback, $end ),
            $this->tables['visitors'],
            $start,
            $end
        );
    }

    /**
     * Raw, with the session and visitor context each row needs on it.
     *
     * One window over the session serves the landing page, the tags and the
     * last event: the frame is the whole session, so FIRST_VALUE and LAST_VALUE
     * read its ends. A second window over the visitor gives the previous event.
     *
     * Window functions are why v2 requires MySQL 8.0.
     *
     * @param int $from yyyymmdd, inclusive
     * @param int $to   yyyymmdd, exclusive
     * @return string
     */
    protected function windowedRaw( $from, $to ) {

        $first = array(
            'lp_location'           => 'page_location',
            'lp_path'               => 'page_path',
            'lp_query'              => 'page_query',
            'lp_title'              => 'page_title',
            's_tagged_source'       => 'tagged_source',
            's_tagged_medium'       => 'tagged_medium',
            's_tagged_campaign'     => 'tagged_campaign',
            's_tagged_ad'           => 'tagged_ad',
            's_tagged_search_terms' => 'tagged_search_terms',
            's_referer_url'         => 'referer_url',
        );

        $columns = array( 'r.*' );

        foreach ( $first as $alias => $column ) {

            $columns[] = sprintf( 'FIRST_VALUE(r.%s) OVER session_w AS %s', $column, $alias );
        }

        $columns[] = 'LAST_VALUE(r.id) OVER session_w AS session_last_id';
        $columns[] = 'LAST_VALUE(r.ts) OVER session_w AS session_last_ts';
        $columns[] = 'LAG(r.ts) OVER visitor_w AS prev_event_ts';

        return sprintf(
            'SELECT %s FROM %s r WHERE r.yyyymmdd >= %d AND r.yyyymmdd < %d '
          . 'WINDOW session_w AS (PARTITION BY r.site_id, r.visitor_id, r.session_id '
          . 'ORDER BY r.ts, r.id ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING), '
          . 'visitor_w AS (PARTITION BY r.site_id, r.visitor_id ORDER BY r.ts, r.id)',
            implode( ', ', $columns ),
            $this->tables['raw'],
            (int) $from,
            (int) $to
        );
    }

    /**
     * A stored text value, or NULL where there is nothing in it.
     *
     * '' and a value that is only whitespace both mean absent, and absence is
     * NULL.
     *
     * @param string $column
     * @return string
     */
    protected function textExpression( $column ) {

        return sprintf( "NULLIF(TRIM(%s), '')", $column );
    }

    /**
     * source: the tag if there was one, else the referring host, else direct.
     *
     * @param string $tag  column holding the tagged value
     * @param string $host expression giving the referring host
     * @return string
     */
    protected function sourceExpression( $tag, $host ) {

        return sprintf( "COALESCE(NULLIF(TRIM(LOWER(%s)), ''), NULLIF(%s, ''), 'direct')",
            $tag, $host );
    }

    /**
     * medium: the tag if there was one, else the referrer classified.
     *
     * The classification is a list that grows and gets corrected, which is why
     * it is applied here and not at ingest: a correction is re-applied by
     * rebuilding, over raw evidence that never changed.
     *
     * @param string $tag
     * @param string $host
     * @return string
     */
    protected function mediumExpression( $tag, $host ) {

        $branches = array(
            sprintf( "WHEN %s IS NULL OR %s = '' THEN 'direct'", $host, $host ),
        );

        foreach ( array(
            'organic-search' => TrackingEventHelpers::getSearchEngineList(),
            'social-network' => TrackingEventHelpers::getSocialNetworkList(),
        ) as $medium => $list ) {

            $tests = array();

            foreach ( $list as $entry ) {

                if ( empty( $entry['domain'] ) ) {

                    continue;
                }

                // Substring containment, as isSearchEngine() matches: the list
                // holds 'google', not a hostname.
                $tests[ $entry['domain'] ] = sprintf( OWA_SQL_CONTAINS,
                    $this->literal( $entry['domain'] ), $host );
            }

            if ( $tests ) {

                $branches[] = sprintf( "WHEN %s THEN '%s'",
                    implode( ' OR ', $tests ), $medium );
            }
        }

        return sprintf( "COALESCE(NULLIF(TRIM(LOWER(%s)), ''), CASE %s ELSE 'referral' END)",
            $tag, implode( ' ', $branches ) );
    }

    /**
     * The host of a URL held in a column, lowercased and without a leading www,
     * or NULL where the column does not hold a URL.
     *
     * Two things are refused rather than parsed, so source only ever holds
     * something that could be a domain:
     *
     *   NO SCHEME. parse_url() finds no host without `://` -- a bare string,
     *   and even `example.com/foo`, resolve to nothing, and v1 answers `direct`
     *   there. SUBSTRING_INDEX has no such opinion: with nothing to cut on it
     *   returns the whole string, so a referrer that is not a URL would become
     *   a source hundreds of characters long.
     *
     *   OVER 253 CHARACTERS, which is the longest a domain name can be.
     *
     * Both answer NULL, and the COALESCE around this turns that into `direct`
     * -- v1's answer for a referrer it cannot read a host out of. referer_url
     * still holds what arrived.
     *
     * Nothing is truncated. A clamp would make a fake domain out of a string
     * that was never one.
     *
     * @param string $column
     * @return string
     */
    protected function hostExpression( $column ) {

        $host = sprintf( OWA_SQL_URL_HOST, $column );

        $stripped = sprintf( "LOWER(CASE WHEN %s LIKE 'www.%%' THEN SUBSTRING(%s, 5) ELSE %s END)",
            $host, $host, $host );

        return sprintf( "CASE WHEN %s OR %s = '' OR CHAR_LENGTH(%s) > %d THEN NULL ELSE %s END",
            sprintf( OWA_SQL_NOT_CONTAINS, "'://'", $column ),
            $stripped, $stripped, self::MAX_HOSTNAME, $stripped );
    }

    /**
     * A quoted, escaped SQL string literal.
     *
     * @param string $value
     * @return string
     */
    protected function literal( $value ) {

        return "'" . $this->db->prepare( $value ) . "'";
    }

    /** @return string[] owa_event_raw's columns, in declaration order */
    protected function rawColumns() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getColumns();
    }

    /**
     * The columns owa_event adds, in declaration order.
     *
     * Derived by difference rather than listed, so a column added to either
     * entity cannot leave this out of step with them.
     *
     * @return string[]
     */
    protected function derivedColumns() {

        return array_values( array_diff(
            \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getColumns(),
            $this->rawColumns() ) );
    }

    /**
     * Advance last_seen for every visitor seen in the partition.
     *
     * One set-based UPDATE, and the only write the pass makes outside its own
     * table. It stores a period, so it usually changes nothing; GREATEST keeps
     * it monotonic, so rebuilding partitions out of order cannot move it back.
     *
     * UPDATE ... JOIN is MySQL's spelling of a joined update; PostgreSQL writes
     * UPDATE ... FROM. One statement behind one dialect constant when a second
     * backend exists -- there is nothing to abstract over with one.
     *
     * @param array $span
     * @return bool
     */
    protected function advanceLastSeen( array $span ) {

        return (bool) $this->db->query( sprintf(
            'UPDATE %s v JOIN (SELECT visitor_id, FLOOR(MAX(yyyymmdd) / 100) AS period FROM %s '
          . 'WHERE yyyymmdd >= %d AND yyyymmdd < %d GROUP BY visitor_id) seen '
          . 'ON seen.visitor_id = v.visitor_id '
          . 'SET v.last_seen = GREATEST(COALESCE(v.last_seen, 0), seen.period)',
            $this->tables['visitors'],
            $this->tables['raw'],
            (int) $span['start'],
            (int) $span['less_than']
        ) );
    }

    /** @return int raw rows the partition should hold */
    protected function countRaw( array $span ) {

        return $this->count( $this->tables['raw'], sprintf(
            'WHERE yyyymmdd >= %d AND yyyymmdd < %d',
            (int) $span['start'], (int) $span['less_than'] ) );
    }

    /** @return int */
    protected function countStaging() {

        return $this->count( $this->tables['staging'], '' );
    }

    /**
     * @param string $table
     * @param string $where
     * @return int
     */
    protected function count( $table, $where ) {

        $row = $this->db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s %s', $table, $where ) );

        return $row ? (int) $row['n'] : 0;
    }
}

?>
