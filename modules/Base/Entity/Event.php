<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_event -- v2's denormalised event table. The one queries hit.
 *
 * Every column of owa_event_raw, unchanged and in the same order, then the
 * seventeen the denormalisation pass derives.
 *
 * IT EXTENDS EventRaw RATHER THAN RESTATING IT
 * "The same columns in the same order" is a requirement, not a description: the
 * pass swaps a staging table into a partition of this one, and EXCHANGE
 * PARTITION refuses two tables that differ anywhere. A hand-copied list would
 * drift the first time raw gained a column.
 *
 * NULLABILITY FOLLOWS WHAT THE PASS CAN PRODUCE
 * Copies -- landing_page_*, prev_event_ts -- are as nullable as the column they
 * are copied from. Resolutions -- source, medium, acq_* -- always produce a
 * value or the sentinel, so they are NOT NULL and a NULL in one is a bug.
 *
 * campaign, ad and search_terms are copies despite being attribution: a
 * campaign exists only because a URL was tagged with one, so no tag is an
 * absence, not an unresolved reading.
 */
class Event extends EventRaw {

    function __construct() {

        // Raw's 54 columns, its primary key, its indexes and its partition
        // column, in that order.
        parent::__construct();

        $this->setTableName( 'event' );

        /*
         * The session's attribution, read from its first event -- the only row
         * of the session carrying tags, since the landing URL rides the landing
         * beacon and no other.
         *
         * This is the only place the verdict is stored. Not in the cookie,
         * which cannot be revisited when the model changes, and not in raw,
         * which is not rebuilt. Here a corrected classifier is re-applied by
         * rebuilding the partition.
         */
        $this->setProperty( $this->resolved( 'source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->resolved( 'medium', OWA_DTD_VARCHAR64 ) );

        $this->setProperty( $this->column( 'campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'search_terms', OWA_DTD_VARCHAR255 ) );

        /*
         * The session's landing page, copied from that same first event. Makes
         * a landing-page report a GROUP BY: 215ms against 3.1s on the measured
         * corpus.
         *
         * Path and query are copied rather than re-parsed, so they cannot
         * disagree with the row they came from. Exit needs no twin -- is_exit
         * makes it a filter over page_location.
         */
        $this->setProperty( $this->column( 'landing_page_location', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'landing_page_path', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'landing_page_query', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'landing_page_title', OWA_DTD_VARCHAR512 ) );

        /*
         * The one terminal value. Stamped only on a session whose last event is
         * older than the idle timeout; a later rebuild fills in the rest. A
         * rebuild REPLACES the partition, so no session ever has two exits.
         *
         * NOT NULL DEFAULT 0, like every boolean here: a nullable one holds
         * three values and GROUP BY gives each a bucket. 0 therefore covers the
         * not-yet-knowable case, which does not earn a third state.
         *
         * Engagement time, bounce and duration are NOT columns. They are
         * read-time aggregates over the session's rows, and materialising them
         * would be a second authority to keep in step.
         */
        $is_exit = $this->column( 'is_exit', OWA_DTD_BOOLEAN, false );
        $is_exit->setNotNull();
        $is_exit->setDefaultValue( 0 );
        $this->setProperty( $is_exit );

        /*
         * The visitor's acquisition, from the visitor store -- the pass's only
         * read outside the partition it is building, and the reason that store
         * exists.
         *
         * Write-once at first_visit: a later campaign moves `source`, never
         * these. Where the store has no row the pass writes the sentinel.
         *
         * acq_search_terms is nullable: an acquisition with no search terms is
         * an ordinary absence, and past the store's retention it stays NULL
         * rather than falling back to a later event.
         */
        $this->setProperty( $this->resolved( 'acq_source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->resolved( 'acq_medium', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->resolved( 'acq_campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->resolved( 'acq_ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'acq_search_terms', OWA_DTD_VARCHAR255 ) );

        // The visitor's previous event, from a window function. NULL on their
        // first in the window. Serves daysSinceLastVisit and
        // timeSinceLastVisit, which had no anchor; both sides of the
        // subtraction are the same server clock.
        $this->setProperty( $this->column( 'prev_event_ts', OWA_DTD_BIGINT ) );

        // When the pass last wrote this partition, in microseconds. Carries the
        // as-of a report envelope reports, and the provenance of a bad rebuild.
        $built_at = $this->column( 'built_at', OWA_DTD_BIGINT, false );
        $built_at->setNotNull();
        $this->setProperty( $built_at );
    }

    /**
     * A column the pass always fills: NOT NULL, and no default.
     *
     * A default would let a row be written without the value. Nothing writes
     * these rows but the pass, so an insert missing one should fail.
     *
     * @param string $name
     * @param string $type an OWA_DTD_* value
     * @return \OWA\Module\Base\Classes\DbColumn
     */
    private function resolved( $name, $type ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );
        $column->setNotNull();

        return $column;
    }

    /**
     * As EventRaw::column(), which is private to it.
     *
     * @param string $name
     * @param string $type
     * @param bool   $nullable
     * @return \OWA\Module\Base\Classes\DbColumn
     */
    private function column( $name, $type, $nullable = true ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );

        if ( $nullable ) {

            $column->setNullable();
        }

        return $column;
    }
}

?>
