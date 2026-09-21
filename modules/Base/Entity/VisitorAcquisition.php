<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_visitor_acquisition -- v2's visitor store.
 *
 * Not a fact table. A lookup with one job: holding the acquisition of a
 * visitor's first session so it can be stamped onto sessions they have years
 * later. That value originates in one event and no later beacon carries it,
 * which is the only reason this table exists -- everything else about a visitor
 * already rides every event, so `how many sessions has this visitor had` is
 * MAX(prior_sessions) in a group-by rather than a lookup here.
 *
 * INSERT-IF-ABSENT, NEVER UPDATE
 * Any event of the visitor's first session may write the row, not only the one
 * that raised first_visit. A session cannot change its attribution part-way --
 * new tags start a new session -- so every candidate event resolves the same
 * acquisition and whichever lands first wins. That makes losing a beacon cost
 * nothing, and keeps the failure mode `absent then present` rather than `wrong
 * then corrected`.
 *
 * A VISITOR WITH NO KNOWN ACQUISITION HAS NO ROW
 * The table is not a census. A placeholder row would answer no question, and it
 * would break the write rule above: insert-if-absent would find it present when
 * the real first_visit arrived late on a queue drain, and block the real value
 * permanently and silently. Keeping the row absent leaves that path open, and
 * the pass writes its sentinel from the row being missing.
 *
 * NOT PARTITIONED, DELIBERATELY
 * Partitioning on last_seen is refused by MySQL rather than merely expensive:
 * every unique key on a partitioned table must contain all partitioning
 * columns, so the key becomes (visitor_id, last_seen) and uniqueness on
 * visitor_id alone -- which insert-if-absent depends on -- stops being
 * enforceable. Partitioning on first-seen would fix the cost objections and buy
 * nothing, because expiry is by INACTIVITY and a first-seen partition holds a
 * mix of long-dead and still-active visitors. Under a TTL this holds active
 * visitors only: 6,761 rows against 445,055 requests on the measured install,
 * so expiry is a plain DELETE against an index.
 */
class VisitorAcquisition extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'visitor_acquisition' );

        // The whole key. Uniqueness on this column alone is what makes
        // insert-if-absent a database guarantee rather than a race.
        $visitor_id = new \OWA\Module\Base\Classes\DbColumn( 'visitor_id', OWA_DTD_BIGINT );
        $visitor_id->setPrimaryKey();
        $this->setProperty( $visitor_id );

        // Recorded, not keyed. Whether one visitor id can be seen by two sites
        // on one installation is open (3.1); keying on it would answer that
        // question by accident, and in the direction that silently splits one
        // person into two acquisitions.
        $this->setProperty( $this->column( 'site_id', OWA_DTD_VARCHAR64 ) );

        /*
         * Acquisition, as collected. Write-once: these are the tagged values
         * from the first session, transcribed, never a classification -- the
         * reading over them is the pass's, the same as for an event.
         */
        $this->setProperty( $this->column( 'acq_source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'acq_medium', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'acq_campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'acq_ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'acq_search_terms', OWA_DTD_VARCHAR255 ) );

        // The referring URL of the first session, kept beside the tags for the
        // same reason raw keeps referer_url: it is the evidence a corrected
        // classifier re-reads when there were no tags to transcribe.
        $this->setProperty( $this->column( 'acq_referer_url', OWA_DTD_VARCHAR1024 ) );

        // Its host, parsed at ingest like owa_event_raw.referer_host, so the
        // pass classifies an acquisition the same way it classifies a session
        // and neither one parses a URL in SQL.
        $this->setProperty( $this->column( 'acq_referer_host', OWA_DTD_VARCHAR255 ) );

        // Microseconds, matching owa_event_raw.ts. Not the TTL input -- that is
        // last_seen -- but the tie-break a full rebuild from raw orders by.
        $this->setProperty( $this->column( 'acq_ts', OWA_DTD_BIGINT ) );

        /*
         * A PERIOD, not a timestamp: yyyymm. Three things follow, and they are
         * why maintaining it does not add a second keyed write per event.
         *
         *   - the pass advances it, not ingest -- one set-based UPDATE over the
         *     visitors seen in the partition being rebuilt;
         *   - a period changes at most once per visitor per month, so the
         *     common case is a write that changes nothing;
         *   - GREATEST(last_seen, <partition max>) is monotonic, so rebuilding
         *     a partition twice is a no-op and the column cannot go backwards
         *     when partitions are rebuilt out of order.
         *
         * Indexed because the only thing that reads it is the TTL sweep:
         * DELETE ... WHERE last_seen < cutoff.
         */
        $last_seen = $this->column( 'last_seen', OWA_DTD_INT );
        $last_seen->setIndex();
        $this->setProperty( $last_seen );
    }

    /**
     * A nullable column. Absence is NULL here as it is in owa_event_raw.
     *
     * @param string $name
     * @param string $type
     * @return \OWA\Module\Base\Classes\DbColumn
     */
    private function column( $name, $type ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );
        $column->setNullable();

        return $column;
    }
}

?>
