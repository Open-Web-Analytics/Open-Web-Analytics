<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's page views (owa_request) into page_view events.
 *
 * A session's entry is its EARLIEST request -- by time, then id -- and it
 * carries the new-session flag, and the new-visitor flag where the visitor had
 * no earlier session (FactMigrator::priorSessions()), so ingest's own materialisers raise session_start and
 * first_visit exactly as for a live beacon (PLAN.html 2.21): once per session.
 *
 * Not v1's is_entry_page. On a real 1.x install it was set on two or more of a
 * session's requests in 1,445 sessions -- each then counted again -- and on
 * none in 9,279, which v1 had lost the session row for. Those still had page
 * views; each now gets its session_start, as live ingest would have given it.
 */
class RequestMigrator extends FactMigrator {

    const SOURCE = 'request';

    /**
     * Where v1 lost a session's row, it began at its entry request: read
     * across all of the session's requests, so a session split over batches
     * has one start.
     */
    protected function sessionStarts( array $rows, array &$refs ) {

        $refs['entry'] = $this->entries( array_column( $rows, 'session_id' ) );

        $starts = parent::sessionStarts( $rows, $refs );

        foreach ( $refs['entry'] as $sid => $entry ) {

            if ( ! isset( $refs['session'][ $sid ]['timestamp'] ) ) {

                $starts[ $sid ] = $entry['ts'];
            }
        }

        return $starts;
    }

    /**
     * Each session's entry request: the earliest it migrates, by time then id.
     *
     * Read across all of the session's requests, not this batch's: a session
     * can straddle batches, and the cutoff (since) does not move where a
     * session began. A request the migration refuses (refusal()) is never the
     * entry, or the session would start on a row that is not written.
     *
     * @param  array $session_ids as read
     * @return array session id as text => array( 'id' => request id as text, 'ts' => seconds )
     */
    protected function entries( array $session_ids ) {

        $ids = array_values( array_unique( array_filter( array_map( 'strval', $session_ids ), array( __CLASS__, 'isId' ) ) ) );

        if ( ! $ids ) {

            return array();
        }

        $integer = $this->integerColumn( self::SOURCE, 'session_id' );

        $rows = (array) $this->db()->get_results( sprintf(
            'SELECT session_id, id, timestamp, visitor_id, yyyymmdd FROM %s WHERE session_id IN (%s)',
            $this->v1Table( self::SOURCE ),
            $integer ? implode( ',', $ids ) : implode( ',', array_fill( 0, count( $ids ), '?' ) ) ),
            $integer ? array() : $ids );

        $first = array();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $this->refusal( $row ) ) {

                continue;
            }

            $sid = (string) $row['session_id'];
            $key = array( (int) $row['timestamp'], (int) $row['id'] );

            if ( ! isset( $first[ $sid ] ) || $key < $first[ $sid ]['key'] ) {

                $first[ $sid ] = array( 'key' => $key, 'id' => (string) $row['id'] );
            }
        }

        return array_map( function ( $f ) { return array( 'id' => $f['id'], 'ts' => $f['key'][0] ); }, $first );
    }

    protected function events( array $r, array $refs ) {

        $sid      = (string) $r['session_id'];
        $is_entry = isset( $refs['entry'][ $sid ] ) && $refs['entry'][ $sid ]['id'] === (string) $r['id'];

        // A new visitor has no earlier session, counted (priorSessions()); v1's
        // is_new_visitor is not read.
        $new_visitor = ( $refs['prior_sessions'][ $sid ] ?? null ) === 0;

        $event = $this->baseEvent( $r, $refs, 'page_view', array(
            'is_new_session_start'   => $is_entry,
            'is_new_visitor_created' => $is_entry && $new_visitor,
        ) );

        $events = \OWA\Module\Base\Classes\MaterializedEvents::sessionStart( array( $event ) );

        return \OWA\Module\Base\Classes\MaterializedEvents::firstVisit( $events );
    }
}

?>
