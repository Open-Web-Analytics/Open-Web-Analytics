<?php
namespace OWA\Module\Base\Classes\Migration;

/**
 * The visitor store (owa_visitor_acquisition) from each visitor's first v1
 * session.
 *
 * Reads a site's sessions and keeps the ones that were their visitor's first
 * (owa_visitor.first_session_id). What it records is evidence, as ingest does:
 * the first session's referrer, for the cube to classify, and the tags only
 * where that session recorded a campaign or an ad -- the attribution rule of
 * PLAN.html 2.21. A first session with no evidence at all was a direct visit,
 * and gets a row with nothing in it and acq_ts set, which the build reads as
 * direct -- the session is the landing, so its having neither a referrer nor
 * a campaign is the answer, not a gap. A visitor v2 already has is left alone.
 *
 * All of a site's sessions, whatever cutoff the page views were migrated with:
 * a visitor's acquisition is stamped on every later session they have.
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-import-type Progress from FactMigrator
 */
class VisitorMigrator extends FactMigrator {

    const SOURCE = 'session';

    protected static function progressKey() {

        return 'visitor_acquisition';
    }

    /** A session row is keyed by its own id, not by a session_id column. */
    protected function refusal( array $r ) {

        return null;
    }

    protected function events( array $r, array $refs ) {

        return [];
    }

    /** This pass writes no raw rows of its own, so there is nothing to count. */
    public function reconcileSite( $site_id ) {

        return null;
    }

    protected function apply( array $rows, array &$progress ) {

        $out = $this->acquisitions( $rows, $progress );

        if ( ! $out ) {

            return 0;
        }

        $table   = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();
        $columns = array_keys( $out[0] );
        $params  = [];

        foreach ( $out as $row ) {

            foreach ( $columns as $column ) {

                $params[] = $row[ $column ];
            }
        }

        $ids    = array_map( 'intval', array_column( $out, 'visitor_id' ) );
        $before = $this->present_rows( $table, $ids );

        // Insert-if-absent, on the unique visitor_id: a row v2 already has wins.
        $ok = $this->db()->query( sprintf( 'INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE visitor_id = visitor_id',
            $table,
            implode( ',', $columns ),
            implode( ',', array_fill( 0, count( $out ), '(' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')' ) ) ),
            $params );

        return $ok === false ? false : $this->present_rows( $table, $ids ) - $before;
    }

    /**
     * The acquisition rows a batch of sessions yields.
     *
     * @param  list<Row> $rows      v1 session rows
     * @param  Progress  $progress  updated in place
     * @return list<array{visitor_id: string, site_id: string, acq_source: string|null, acq_medium: string|null, acq_campaign: string|null, acq_ad: string|null, acq_search_terms: string|null, acq_referer_url: string|null, acq_referer_host: string|null, acq_ts: int, last_seen: int}>
     */
    public function acquisitions( array $rows, array &$progress ) {

        $visitors = $this->lookup( 'visitor', array_column( $rows, 'visitor_id' ),
            'id, first_session_id, first_session_source, first_session_medium, first_session_campaign,'
            . ' first_session_ad, first_session_search_terms' );
        $referers = $this->lookup( 'referer', array_column( $rows, 'referer_id' ), 'id, url' );

        $out = [];

        foreach ( $rows as $r ) {

            $progress['rows_read']++;

            $visitor = $visitors[ (string) $r['visitor_id'] ] ?? null;

            // Only the visitor's FIRST session speaks for their acquisition.
            if ( ! $visitor || (string) $visitor['first_session_id'] !== (string) $r['id'] ) {

                continue;
            }

            $referer = self::v1Text( $referers[ (string) ( $r['referer_id'] ?? '' ) ]['url'] ?? '' );
            $tagged  = self::present( $visitor['first_session_campaign'] ?? null )
                    || self::present( $visitor['first_session_ad'] ?? null );

            $row = [
                'visitor_id'       => (string) $r['visitor_id'],
                'site_id'          => (string) $r['site_id'],
                'acq_source'       => $tagged ? self::value( $visitor['first_session_source'] ) : null,
                'acq_medium'       => $tagged ? self::value( $visitor['first_session_medium'] ) : null,
                'acq_campaign'     => $tagged ? self::value( $visitor['first_session_campaign'] ) : null,
                'acq_ad'           => $tagged ? self::value( $visitor['first_session_ad'] ) : null,
                'acq_search_terms' => $tagged ? self::value( $visitor['first_session_search_terms'] ) : null,
                'acq_referer_url'  => $referer !== '' ? $referer : null,
                'acq_referer_host' => $referer !== ''
                    ? ( \OWA\Module\Base\Classes\V2Event::parseUrl( $referer )['host'] ?: null ) : null,
                'acq_ts'           => (int) $r['timestamp'] * 1000000,
                'last_seen'        => (int) substr( (string) $r['yyyymmdd'], 0, 6 ),
            ];

            $out[] = $row;
        }

        return $out;
    }

    /** Remove exactly the rows this wrote: a migrated row's acq_ts is a whole second. */
    public function revertSite( $site_id ) {

        $table   = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();
        $deleted = 0;
        $after   = null;
        $scratch = [ 'rows_read' => 0, 'rows_refused' => 0, 'refusals' => [] ];

        while ( $rows = $this->read( $site_id, $after ) ) {

            foreach ( $this->acquisitions( $rows, $scratch ) as $row ) {

                $this->db()->query( sprintf( 'DELETE FROM %s WHERE visitor_id = ? AND acq_ts = ?', $table ),
                    [ $row['visitor_id'], $row['acq_ts'] ] );

                $deleted++;
            }

            $last  = end( $rows );
            $after = (string) $last['id'];
        }

        $this->forget( $site_id );

        return $deleted;
    }

    /**
     * How many of these visitors the table holds a row for.
     *
     * @param  string    $table
     * @param  list<int> $ids
     * @return int
     */
    private function present_rows( $table, array $ids ) {

        return (int) ( ( $this->row( sprintf( 'SELECT COUNT(*) AS n FROM %s WHERE visitor_id IN (%s)',
            $table, implode( ',', $ids ) ) ) )['n'] ?? 0 );
    }

    /**
     * A v1 value as text, or null where it holds nothing.
     *
     * @param  mixed $v  as v1 stored it
     * @return string|null
     */
    private static function value( $v ) {

        return self::present( $v ) ? self::v1Text( $v ) : null;
    }
}
