<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_event_raw -- v2's raw event store.
 *
 * One row per tracked interaction, as received. Append-only: written a beacon
 * at a time, never updated, and the record realtime queries read. Everything a
 * pass has to derive belongs in the cube instead -- the cut is
 * that a column lives here if it is an OBSERVATION or a MECHANICAL RESOLUTION
 * (geo from the address, device from the user agent), and there if deriving it
 * needs other rows.
 *
 * WHY THIS EXTENDS Core\Entity AND NOT Core\Entity\FactTable
 * FactTable's constructor is the star schema: ten dimension foreign keys, eight
 * date parts and a set of denormalised flags, all of which v2 exists to remove.
 * Inheriting it to delete most of it would leave the columns declared, and an
 * entity's declarations are what CREATE TABLE emits. The one thing worth
 * inheriting -- partitioning on yyyymmdd -- is two lines, and the partition
 * commands now select on that property rather than on the base class, so this
 * table joins them without joining the star.
 *
 * SIGNED BIGINT, NOT UNSIGNED
 * The design note asks for BIGINT UNSIGNED on the id columns. Signed, and
 * deliberately: v1 has negative visitor ids -- enough of them to need their own
 * fix -- and they are real ids that have to round-trip. UNSIGNED refuses them
 * under STRICT_ALL_TABLES and clamps them to 0 without it, so the migrator
 * either dies on them or merges strangers into visitor 0. The headroom UNSIGNED
 * buys is not needed: wideStringGuid() tops out at 63 bits by construction and
 * a microsecond timestamp is nowhere near it.
 *
 * ABSENCE IS NULL
 * Every column that can be absent is declared nullable, so an unset value is
 * stored as NULL rather than '' or a sentinel. v1 cannot do this -- its columns
 * already hold '(not set)' on historical rows and half a column in each
 * spelling would be worse than either -- but this table has no history to keep
 * the shape of.
 */
class EventRaw extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'event_raw' );

        /*
         * Identity and time. None of these is nullable: a row that cannot say
         * which site, visitor, session or instant it belongs to is not an
         * observation, and the id is derived from all of them.
         */

        // wideStringGuid( site_id + visitor_id + session_id + ts + event_name ).
        // Derived from the event's own content, never minted, so a redelivered
        // beacon derives the same ids and collapses on the primary key.
        $this->setProperty( $this->column( 'id', OWA_DTD_BIGINT, false ) );
        $this->properties['id']->setPrimaryKey();

        $this->setProperty( $this->column( 'event_type', OWA_DTD_VARCHAR24, false ) );
        $this->setProperty( $this->column( 'site_id', OWA_DTD_VARCHAR64, false ) );

        $this->setProperty( $this->column( 'visitor_id', OWA_DTD_BIGINT, false ) );
        $this->properties['visitor_id']->setIndex();

        // Not unique on its own: the id embeds its creation second, so two
        // visitors starting together can share one. A session is counted as a
        // distinct (visitor_id, session_id).
        $this->setProperty( $this->column( 'session_id', OWA_DTD_BIGINT, false ) );
        $this->properties['session_id']->setIndex();

        $this->setProperty( $this->column( 'user_id', OWA_DTD_VARCHAR255 ) );

        // Microseconds, zone-less, stamped once where the beacon arrives. The
        // RESOLUTION is load-bearing: it is what stops two events of one
        // session colliding on the derived id.
        $this->setProperty( $this->column( 'ts', OWA_DTD_BIGINT, false ) );

        $this->setProperty( $this->column( 'yyyymmdd', OWA_DTD_INT, false ) );
        $this->setPartitionColumn( 'yyyymmdd' );

        $this->setProperty( $this->column( 'visitor_fsts', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'prior_sessions', OWA_DTD_INT ) );

        /*
         * The session's start, and the PREVIOUS session's start.
         *
         * Anchors, both of them: GA ships raw anchors and derives offsets from
         * them, and an offset computed at collection cannot be re-derived when
         * the rule for it changes. `psts` is what daysSinceLastVisit reads --
         * the tracker's own comment says so, and says why the seconds interval
         * it replaced was wrong ("a continuous seconds value gives one bucket
         * per distinct second, so it was a metric wearing a dimension's
         * clothes. Days bucket; seconds do not").
         *
         * Both were already on the wire and reached NO column, while
         * prev_event_ts was written to a column nothing read. The value that
         * was stored was not the one anybody wanted.
         */
        $this->setProperty( $this->column( 'session_start_ts', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'prior_session_start_ts', OWA_DTD_BIGINT ) );

        /*
         * The event's position in its session, counted on the DEVICE.
         *
         * NULLABLE, and that is the contract: a tracker cached from before this
         * existed sends nothing, and the sort has to read that as "no device
         * order for this session" rather than as position zero. Every row of an
         * old session is NULL together, so those sessions fall back to arrival
         * order and behave exactly as they did.
         *
         * Counted from 1, so a stored 0 is not a position and never appears.
         */
        $this->setProperty( $this->column( 'event_seq', OWA_DTD_INT ) );

        /*
         * The page. page_location is the evidence; every reading of it is its
         * own column, parsed at ingest. A GROUP BY over a parsing expression
         * cannot use an index, and the expression would have to be written once
         * per dialect.
         */
        $this->setProperty( $this->column( 'page_location', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'page_path', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'page_query', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'page_title', OWA_DTD_VARCHAR512 ) );
        $this->setProperty( $this->column( 'content_group', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'referer_url', OWA_DTD_VARCHAR1024 ) );

        // Parsed at ingest beside host and target_host, by the same parser.
        // A build classifies it -- which search engine, which social network
        // -- but does not parse it: SQL has no URL parser, and a chain of
        // SUBSTRING_INDEX cannot tell a URL from a string that is not one.
        $this->setProperty( $this->column( 'referer_host', OWA_DTD_VARCHAR255 ) );

        // The referrer's query string, kept for the same reason page_query is:
        // it is the evidence a reading is taken from. A search engine that
        // still sends the term puts it here, and the cube's compute step reads
        // it -- pulling a named parameter out and percent-decoding it is PHP,
        // because SQL has no decode.
        $this->setProperty( $this->column( 'referer_query', OWA_DTD_VARCHAR1024 ) );

        /*
         * Attribution EVIDENCE, never a verdict. The landing URL rides the
         * landing beacon and no other, so these are populated on the session's
         * landing event and NULL on every later row of it. A build reads that
         * first event anyway, for the landing page, and turns the pair of
         * (tags, referer) into source and medium on the cube row --
         * where a classifier fix can be re-applied, which is the whole reason
         * the reading is not stored here.
         */
        $this->setProperty( $this->column( 'tagged_source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'tagged_medium', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'tagged_campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'tagged_ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'tagged_search_terms', OWA_DTD_VARCHAR255 ) );

        /*
         * Device and browser, resolved from raw_ua at ingest so realtime can
         * report on them. raw_ua is kept beside them so a parser fix can be
         * re-applied to history.
         */
        $this->setProperty( $this->column( 'browser', OWA_DTD_VARCHAR128 ) );
        $this->setProperty( $this->column( 'browser_type', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'browser_version', OWA_DTD_VARCHAR32 ) );
        $this->setProperty( $this->column( 'os', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'os_version', OWA_DTD_VARCHAR32 ) );
        $this->setProperty( $this->column( 'device_type', OWA_DTD_VARCHAR32 ) );
        $this->setProperty( $this->column( 'device_brand', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'device_model', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'language', OWA_DTD_VARCHAR16 ) );

        // Geography, from ip_address at ingest.
        $this->setProperty( $this->column( 'country', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'country_code', OWA_DTD_CHAR2 ) );
        $this->setProperty( $this->column( 'city', OWA_DTD_VARCHAR128 ) );
        $this->setProperty( $this->column( 'region', OWA_DTD_VARCHAR128 ) );

        $this->setProperty( $this->column( 'host', OWA_DTD_VARCHAR255 ) );

        // Nullable because anonymise-on-write is a setting, and geography is
        // already resolved by the time it would be dropped.
        $this->setProperty( $this->column( 'ip_address', OWA_DTD_VARCHAR45 ) );

        // What the page declared at send. Per event rather than per session
        // because it can change mid-session, and a row with no consent recorded
        // is not the same thing as one with consent denied.
        $this->setProperty( $this->column( 'consent_state', OWA_DTD_VARCHAR32 ) );

        // Interaction geometry. NULL on any event that is not a click; the
        // viewport is what a coordinate has to be placed against.
        $this->setProperty( $this->column( 'click_x', OWA_DTD_INT ) );
        $this->setProperty( $this->column( 'click_y', OWA_DTD_INT ) );
        $this->setProperty( $this->column( 'page_width', OWA_DTD_INT ) );
        $this->setProperty( $this->column( 'page_height', OWA_DTD_INT ) );

        // Where a click, download or outbound link went. target_host is parsed
        // at ingest beside host, so "which domains do people leave to" is a
        // group-by rather than a parse.
        $this->setProperty( $this->column( 'target_url', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'target_host', OWA_DTD_VARCHAR255 ) );

        $this->setProperty( $this->column( 'element_path', OWA_DTD_VARCHAR512 ) );
        $this->setProperty( $this->column( 'element_tag', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'element_id', OWA_DTD_VARCHAR255 ) );

        // Percentage. TINYINT holds 0-100 with room to spare.
        $this->setProperty( $this->column( 'scroll_depth', OWA_DTD_TINYINT4 ) );

        // The per-event DELTA, not a session total: time accrued on this page
        // since the last report. Losing a beacon costs one increment rather
        // than one page.
        $this->setProperty( $this->column( 'engagement_msec', OWA_DTD_INT ) );

        // Set on the row a goal condition MATERIALISED, never on the ordinary
        // event that triggered it -- flagging both would double-count and blur
        // which row is the conversion. NOT NULL with a default because a
        // boolean holding three values groups as three things.
        $is_goal_event = $this->column( 'is_goal_event', OWA_DTD_BOOLEAN, false );
        $is_goal_event->setNotNull();
        $is_goal_event->setDefaultValue( 0 );
        $this->setProperty( $is_goal_event );

        // Minor units, with the currency beside it -- without which a
        // multi-currency store sums minor units of different things.
        $this->setProperty( $this->column( 'revenue', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'currency', OWA_DTD_CHAR3 ) );

        $this->setProperty( $this->column( 'raw_ua', OWA_DTD_VARCHAR1024 ) );

        // Only what cannot be a column: site-defined keys unknown at release
        // time, and nested arrays. Everything the release knows the name of
        // gets a column of its own.
        $this->setProperty( $this->column( 'params', OWA_DTD_JSON ) );

        /*
         * Indexes, as measured in the prototype. The two composites are the
         * report shapes: every query bounds site_id and a date range, and the
         * event-type one serves a breakdown of a single event over a period.
         * Two single-column indexes are not a substitute -- MySQL picks one and
         * filters the rest.
         */
        $this->addCompositeIndex( 'site_date', array( 'site_id', 'yyyymmdd' ) );
        $this->addCompositeIndex( 'site_type_date', array( 'site_id', 'event_type', 'yyyymmdd' ) );
    }

    /**
     * A column, nullable unless told otherwise.
     *
     * Absence is NULL in v2, so nullable is the default here and the exceptions
     * are the ones worth seeing: identity, time, the partition key and a
     * boolean that must group as two values rather than three.
     *
     * @param string $name
     * @param string $type     an OWA_DTD_* value
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
