<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's page views (owa_request) into owa_event_raw.
 *
 * Each v1 row becomes the event a live beacon would have been, and is stored
 * through the same row builder as ingest (EventRawHandlers::rowFor), so ids,
 * column mapping and "(not set)" handling are ingest's own. What ingest does
 * beyond building rows is deliberately not run:
 *
 *   - no announcements: a migrated session is not news;
 *   - no goal marking: v1 recorded its own completions, and marking history
 *     against today's goal definitions would be retroactive conversion;
 *   - no acquisition write: the migration fills the visitor store itself.
 *
 * The session markers ARE raised, by ingest's own materialisers: the entry
 * request of a session carries the new-session flag, and the new-visitor flag
 * where v1 recorded a new visitor (PLAN.html 2.21).
 *
 * READS v1 BY COLUMN NAME AND NEVER BY TYPE. Installations hold the same
 * columns with different types; see resolve().
 *
 * Batched and resumable: owa_migration_progress records the last v1 id passed,
 * in the same transaction as the rows written, and a batch replayed after an
 * interruption derives the same ids and writes nothing twice.
 */
class RequestMigrator {

    const SOURCE = 'request';

    const BATCH = 500;

    /** Refusal reasons, as they are counted in owa_migration_progress. */
    const NO_VISITOR   = 'no_visitor';
    const NO_SESSION   = 'no_session';
    const NO_TIMESTAMP = 'no_timestamp';
    const NO_DAY       = 'no_day';

    private $prefix;

    private $batch;

    /** @var array table => bool, whether its `id` column is an integer type */
    private $integer_keys = array();

    /**
     * @param string $prefix the prefix the v1 tables are read under
     * @param int    $batch  v1 rows per transaction
     */
    function __construct( $prefix = 'owa_', $batch = self::BATCH ) {

        $this->prefix = $prefix;
        $this->batch  = max( 1, (int) $batch );
    }

    /**
     * The sites v1 holds page views for.
     *
     * @return string[]
     */
    public function sites() {

        $rows = (array) $this->db()->get_results( sprintf(
            'SELECT DISTINCT site_id FROM %s', V1Tables::name( self::SOURCE, $this->prefix ) ) );

        return array_values( array_filter( array_map( function ( $r ) {

            return (string) ( (array) $r )['site_id'];
        }, $rows ), 'strlen' ) );
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
    private function migrateBatch( $site_id, array $rows, array &$progress ) {

        $refs = $this->resolve( $rows );

        $out = array();

        foreach ( $rows as $r ) {

            $progress['rows_read']++;

            $refused = $this->refusal( $r );

            if ( $refused ) {

                $progress['rows_refused']++;
                $progress['refusals'][ $refused ] = ( $progress['refusals'][ $refused ] ?? 0 ) + 1;

                continue;
            }

            foreach ( $this->eventsFor( $r, $refs ) as $event ) {

                $row = \OWA\Module\Base\Handler\EventRawHandlers::rowFor( $event );

                if ( $row ) {

                    $out[] = $row;
                }
            }
        }

        $last = end( $rows );
        $progress['last_id'] = (string) $last['id'];

        $db = $this->db();
        $db->beginTransaction();

        $written = $this->write( $out );

        if ( $written === false ) {

            $db->rollbackTransaction();

            throw new \RuntimeException( sprintf(
                'v1 migration: writing a batch of %s for site %s failed; nothing from it was kept.',
                self::SOURCE, $site_id ) );
        }

        $progress['rows_written'] += $written;

        if ( ! $this->save( $progress ) ) {

            $db->rollbackTransaction();

            throw new \RuntimeException( 'v1 migration: recording progress failed; the batch was rolled back.' );
        }

        $db->endTransaction();
    }

    /**
     * Why a v1 row cannot become an event, or null.
     *
     * The same things the row builder refuses without, named so the run can
     * say how many of each it met.
     */
    private function refusal( array $r ) {

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
     * The event a live beacon would have been, and the markers it raises.
     *
     * @return object[]
     */
    private function eventsFor( array $r, array $refs ) {

        $doc      = $refs['document'][ (string) $r['document_id'] ] ?? array();
        $referer  = $refs['referer'][ (string) $r['referer_id'] ] ?? array();
        $ua       = $refs['ua'][ (string) $r['ua_id'] ] ?? array();
        $os       = $refs['os'][ (string) $r['os_id'] ] ?? array();
        $location = $refs['location_dim'][ (string) $r['location_id'] ] ?? array();
        $session  = $refs['session'][ (string) $r['session_id'] ] ?? array();
        $visitor  = $refs['visitor'][ (string) $r['visitor_id'] ] ?? array();
        $prior    = isset( $session['prior_session_id'] )
            ? ( $refs['prior_session'][ (string) $session['prior_session_id'] ] ?? array() )
            : array();

        $event = new \OWA\Module\Base\Classes\Event;
        $event->setEventType( 'page_view' );

        $is_entry = ! empty( $r['is_entry_page'] );

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
            'ip_address'      => $r['ip_address'] ?? null,
            'language'        => $r['language'] ?? null,

            'country'      => $location['country'] ?? null,
            'country_code' => $location['country_code'] ?? null,
            'city'         => $location['city'] ?? null,
            'state'        => $location['state'] ?? null,

            'num_prior_sessions' => $r['num_prior_sessions'] ?? null,
            'fsts' => $visitor['first_session_timestamp'] ?? null,
            'sts'  => $session['timestamp'] ?? null,
            'psts' => $prior['timestamp'] ?? null,

            'is_new_session_start'   => $is_entry,
            'is_new_visitor_created' => $is_entry && ! empty( $r['is_new_visitor'] ),
        );

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

        $events = \OWA\Module\Base\Classes\MaterializedEvents::sessionStart( array( $event ) );
        $events = \OWA\Module\Base\Classes\MaterializedEvents::firstVisit( $events );

        return $events;
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
     * @return array tracking properties
     */
    private function attribution( array $r, array $refs ) {

        $campaign = $refs['campaign_dim'][ (string) ( $r['campaign_id'] ?? '' ) ]['name'] ?? null;
        $ad       = $refs['ad_dim'][ (string) ( $r['ad_id'] ?? '' ) ]['name'] ?? null;

        if ( ! self::present( $campaign ) && ! self::present( $ad ) ) {

            return array();
        }

        return array(
            'tagged_source'   => $refs['source_dim'][ (string) ( $r['source_id'] ?? '' ) ]['source_domain'] ?? null,
            'tagged_medium'   => $r['medium'] ?? null,
            'tagged_campaign' => $campaign,
            'tagged_ad'       => $ad,
            'tagged_terms'    => $refs['search_term_dim'][ (string) ( $r['referring_search_term_id'] ?? '' ) ]['terms'] ?? null,
        );
    }

    /** A stored value, as opposed to empty or v1's "(not set)". */
    private static function present( $value ) {

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
    private function resolve( array $rows ) {

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

        return $refs;
    }

    /**
     * Rows of one v1 table by id, compared in the id column's own type.
     *
     * @param  string $table   unprefixed
     * @param  array  $ids     as read, of any type
     * @param  string $columns
     * @return array  id as text => row
     */
    private function lookup( $table, array $ids, $columns ) {

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

    /** Whether a v1 table's `id` column is an integer type on this installation. */
    private function integerKey( $table ) {

        if ( ! isset( $this->integer_keys[ $table ] ) ) {

            $column = (array) $this->db()->get_row( sprintf( "SHOW COLUMNS FROM %s LIKE 'id'",
                V1Tables::name( $table, $this->prefix ) ) );

            $this->integer_keys[ $table ] = (bool) preg_match( '/int/i', (string) ( $column['Type'] ?? '' ) );
        }

        return $this->integer_keys[ $table ];
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
    private function read( $site_id, $after ) {

        $name = V1Tables::name( self::SOURCE, $this->prefix );

        $where = 'site_id = ?';

        if ( $after !== null && $after !== '' ) {

            if ( ! self::isId( $after ) ) {

                throw new \RuntimeException( sprintf( 'v1 migration: the recorded position %s is not an id.', $after ) );
            }

            $where .= $this->integerKey( self::SOURCE )
                ? ' AND id > ' . $after
                : ' AND CAST(id AS SIGNED) > ' . $after;
        }

        // Ordered the way the position is compared: numerically, whatever the
        // column's type. A VARCHAR id sorted as text would skip rows on resume.
        $order = $this->integerKey( self::SOURCE ) ? 'id' : 'CAST(id AS SIGNED)';

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
    private function write( array $rows ) {

        if ( ! $rows ) {

            return 0;
        }

        $table   = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
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
    private function count( $table, array $rows ) {

        $ids = array_map( 'intval', array_column( $rows, 'id' ) );

        $row = (array) $this->db()->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s WHERE id IN (%s)',
            $table, implode( ',', $ids ) ) );

        return (int) ( $row['n'] ?? 0 );
    }

    /** This site's progress row, loaded or new. */
    private function progress( $site_id ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );
        $id     = \OWA\Module\Base\Entity\MigrationProgress::idFor( self::SOURCE, $site_id );

        $entity->load( $id );

        if ( $entity->wasPersisted() ) {

            $p = $entity->_getProperties();
            $p['refusals'] = (array) json_decode( (string) $p['refusals'], true );
            $p['persisted'] = true;

            foreach ( array( 'rows_read', 'rows_written', 'rows_refused' ) as $k ) {

                $p[ $k ] = (int) $p[ $k ];
            }

            return $p;
        }

        return array(
            'id'           => $id,
            'source'       => self::SOURCE,
            'site_id'      => (string) $site_id,
            'last_id'      => null,
            'rows_read'    => 0,
            'rows_written' => 0,
            'rows_refused' => 0,
            'refusals'     => array(),
            'started_at'   => time(),
            'completed_at' => null,
            'persisted'    => false,
        );
    }

    private function save( array &$progress ) {

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

    private function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }
}

?>
