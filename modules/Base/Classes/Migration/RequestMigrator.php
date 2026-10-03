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
 * carries the new-session flag, and the new-visitor flag where v1 recorded a
 * new visitor, so ingest's own materialisers raise session_start and
 * first_visit exactly as for a live beacon (PLAN.html 2.21): once per session.
 *
 * Not v1's is_entry_page. On a real 1.x install it was set on two or more of a
 * session's requests in 1,445 sessions -- each then counted again -- and on
 * none in 9,279, which v1 had lost the session row for. Those still had page
 * views; each now gets its session_start, as live ingest would have given it.
 */
class RequestMigrator extends FactMigrator {

    const SOURCE = 'request';

    protected function resolve( array $rows ) {

        $refs = parent::resolve( $rows );
        $refs['entry'] = $this->entries( array_column( $rows, 'session_id' ) );

        return $refs;
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
     * @return array session id as text => entry request id as text
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

        return array_map( function ( $f ) { return $f['id']; }, $first );
    }

    protected function events( array $r, array $refs ) {

        $sid      = (string) $r['session_id'];
        $is_entry = isset( $refs['entry'][ $sid ] ) && $refs['entry'][ $sid ] === (string) $r['id'];

        // The session's own flag where v1 kept the session row and set it; the
        // request's otherwise.
        $flag        = $refs['session'][ $sid ]['is_new_visitor'] ?? null;
        $new_visitor = $flag !== null ? ! empty( $flag ) : ! empty( $r['is_new_visitor'] );

        $event = $this->baseEvent( $r, $refs, 'page_view', array(
            'is_new_session_start'   => $is_entry,
            'is_new_visitor_created' => $is_entry && $new_visitor,
        ) );

        $events = \OWA\Module\Base\Classes\MaterializedEvents::sessionStart( array( $event ) );

        return \OWA\Module\Base\Classes\MaterializedEvents::firstVisit( $events );
    }
}

?>
