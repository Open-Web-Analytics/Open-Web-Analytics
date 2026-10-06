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
        $this->properties['id']->setDescription(
            'The event\'s id, derived from its site, visitor, session, timestamp and event name, '
          . 'so a beacon delivered twice is stored once.' );

        /*
         * Wide enough for any name a site may give a custom event -- 40
         * characters, TrackingEventHelpers::CUSTOM_NAME_PATTERN -- and NOT
         * TRUNCATABLE. It was VARCHAR(24), and the entity layer trims an
         * over-long value to fit, so a 25-40 character name was stored cut while
         * the row id was derived from the whole name: two events sharing their
         * first 24 characters reported as one. Refused rather than trimmed now,
         * because a trimmed name is a different event. Update055.
         */
        $event_type = $this->column( 'event_type', OWA_DTD_VARCHAR64, false );
        $event_type->setTruncatable( false );
        $this->setProperty( $event_type );
        $this->setProperty( $this->column( 'site_id', OWA_DTD_VARCHAR64, false ) );

        $this->setProperty( $this->column( 'visitor_id', OWA_DTD_BIGINT, false ) );
        $this->properties['visitor_id']->setIndex();

        // 62 random bits from the current tracker (Util.generateRandomGuid),
        // so unique on its own in practice. Ids minted before 2.0 -- and so
        // migrated v1 history -- carry a creation second and ~30 random bits
        // per second, and two visitors starting together can share one; the
        // funnel keys a session on (visitor_id, session_id) for that reason.
        $this->setProperty( $this->column( 'session_id', OWA_DTD_BIGINT, false ) );
        $this->properties['session_id']->setIndex();

        $this->setProperty( $this->column( 'user_id', OWA_DTD_VARCHAR255 ) );

        // Microseconds, zone-less, stamped once where the beacon arrives. The
        // RESOLUTION is load-bearing: it is what stops two events of one
        // session colliding on the derived id.
        $this->setProperty( $this->column( 'ts', OWA_DTD_BIGINT, false ) );

        /*
         * Microseconds: when the row REACHED RAW, stamped by create(). Not ts,
         * which is when the edge received the beacon -- a queued event lands
         * later carrying an earlier ts. A build compares this with a
         * partition's built_at to know whether anything has arrived since
         * (Cube\Builder::isCurrent()). Raw only: the cube entity drops it.
         * NULL on rows written before the column existed.
         */
        $this->setProperty( $this->column( 'created_at', OWA_DTD_BIGINT ) );
        $this->properties['created_at']->setDescription(
            'When the row reached this table, in microseconds. A build compares it with a partition\'s '
          . 'build time to tell whether anything has arrived since. Not carried into the cube.' );

        $this->setProperty( $this->column( 'yyyymmdd', OWA_DTD_INT, false ) );
        $this->setPartitionColumn( 'yyyymmdd' );

        $this->setProperty( $this->column( 'visitor_fsts', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'prior_sessions', OWA_DTD_INT ) );

        /*
         * The session's start, and the PREVIOUS session's start.
         *
         * Anchors, both of them: offsets are derived from them downstream,
         * because an offset computed at collection cannot be re-derived when
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
         * Which BEACON FORMAT generation wrote this row.
         *
         * Stored rather than merely sniffed, and that is the whole point: the
         * compat bridges between an old beacon and the current one can only be
         * deleted on evidence that nothing is still sending the old shape, and
         * the only place that evidence can come from is the rows themselves.
         * OWA hands a static file to the customer's origin and loses sight of
         * how long it is cached, so counting is the only way to know.
         *
         *     SELECT beacon_version, COUNT(*) ... GROUP BY 1
         *
         * NULL is generation 0 -- a tracker from before versioning, which is
         * every tracker in the wild on the day this shipped. Nullable for
         * exactly that reason, and it must stay nullable while any of them can
         * still be cached.
         */
        $this->setProperty( $this->column( 'beacon_version', OWA_DTD_INT ) );

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
         *
         * `browser` was here too, VARCHAR(128), written from the same property as
         * browser_type and read by nothing -- dimensions.php declares browserType
         * against browser_type. Dropped in Update052: on a table whose row cannot
         * exceed 65,535 bytes, a second copy of a value is budget a real
         * dimension does not get.
         */
        $this->setProperty( $this->column( 'browser_type', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'browser_version', OWA_DTD_VARCHAR32 ) );
        $this->setProperty( $this->column( 'os', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'os_version', OWA_DTD_VARCHAR32 ) );
        $this->setProperty( $this->column( 'device_type', OWA_DTD_VARCHAR32 ) );
        $this->setProperty( $this->column( 'device_brand', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'device_model', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'language', OWA_DTD_VARCHAR16 ) );

        // The device's screen, WIDTHxHEIGHT in CSS pixels, as the tracker read it.
        $this->setProperty( $this->column( 'screen_resolution', OWA_DTD_VARCHAR16 ) );

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

        // Where a click or download went. target_host is parsed at ingest beside
        // host, so "which domains do people leave to" is a group-by rather than a
        // parse, and is_outbound below is the comparison of the two.
        $this->setProperty( $this->column( 'target_url', OWA_DTD_VARCHAR1024 ) );
        $this->setProperty( $this->column( 'target_host', OWA_DTD_VARCHAR255 ) );

        /*
         * Whether that target was off-site, decided at ingest from target_url's
         * host against page_location's.
         *
         * NOT NULL WITH A DEFAULT, like is_goal_event and for the same reason: a
         * boolean holding three values groups as three things, and the boolean
         * formatter renders NULL and 0 both as 'No' -- two GROUP BY buckets under
         * one label, which is the isNewVisitor pie defect. It can be two-valued
         * because the question is asked of the ROW: a page_view is not an
         * outbound click, and 0 says so truthfully.
         */
        $is_outbound = $this->column( 'is_outbound', OWA_DTD_BOOLEAN, false );
        $is_outbound->setNotNull();
        $is_outbound->setDefaultValue( 0 );
        $this->setProperty( $is_outbound );

        /*
         * element_path is GONE -- see Update053. It was a CSS selector built by
         * walking up to eight ancestors with :nth-of-type() indexes, which no
         * report, widget or overlay ever read, and which a template edit
         * renumbers wholesale. The heatmap places clicks by coordinate.
         */
        $this->setProperty( $this->column( 'element_tag', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'element_id', OWA_DTD_VARCHAR255 ) );

        /*
         * THE DOWNLOAD, as columns -- the only two of the eleven param-bound
         * first-class properties promoted (Update054).
         *
         * A downloads report is a question every install asks, and a params key is
         * unreportable in v2 until someone registers it as a CUSTOM dimension:
         * without these, every site would spend one of its 20 registration slots
         * on a value OWA set itself.
         *
         * The element and form params STAY in the bag on purpose. Most installs
         * will never group by an element class or a form name, and a column is
         * width on every row of every Property whether or not anyone reads it.
         * Measured on MySQL 8.4: promoting nine of them took the custom-dimension
         * ceiling from 62 to 37.
         *
         * file_name is the PATH, without host, query or fragment. The extension
         * is bounded by the tracker's downloadExtensions list, so 32 is generous.
         */
        $this->setProperty( $this->column( 'file_name', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'file_extension', OWA_DTD_VARCHAR32 ) );

        /*
         * THE SITE-SEARCH TERM, promoted out of `params` for the same reason as the
         * two above: a params key is unreportable until a site registers it as a
         * custom dimension, and "what do people search for" is not a question an
         * install should spend one of its twenty slots on.
         *
         * NO MIGRATION, unlike the two above. Update034 creates owa_event_raw from
         * THIS ENTITY, so every upgrade path that reaches 34 builds the table with
         * whatever columns are declared here -- and v2 has never shipped, so no
         * install exists that was created before this line. Update053 and Update054
         * are no-ops on the same reasoning; they served exactly one dev install.
         * While v2 is unshipped, adding a column here is the whole change.
         *
         * 255 because it is what somebody typed into a box. Longer than that is a
         * paste, and strict mode refuses an over-long value rather than truncating
         * -- so the entity's own fitToColumn() is what keeps a pasted essay from
         * aborting the insert.
         */
        $this->setProperty( $this->column( 'search_term', OWA_DTD_VARCHAR255 ) );

        // Percentage. TINYINT holds 0-100 with room to spare.
        $this->setProperty( $this->column( 'scroll_depth', OWA_DTD_TINYINT4 ) );

        // The per-event DELTA, not a session total: time accrued on this page
        // since the last report. Losing a beacon costs one increment rather
        // than one page.
        $this->setProperty( $this->column( 'engagement_msec', OWA_DTD_INT ) );

        /*
         * Set on the event that MET the condition -- a goal event is an
         * ordinary event flagged, so eventCount stays a count of what happened
         * and a conversion needs no row of its own. Decided per
         * event by Classes\GoalMarking on Ingest::TRACKING_EVENTS_PRE_SAVE.
         *
         * NOT NULL with a default, because a boolean holding three values
         * groups as three things.
         */
        $is_goal_event = $this->column( 'is_goal_event', OWA_DTD_BOOLEAN, false );
        $is_goal_event->setNotNull();
        $is_goal_event->setDefaultValue( 0 );
        $this->setProperty( $is_goal_event );

        /*
         * THE PURCHASE, as columns.
         *
         * Minor units, with the currency beside it -- without which a
         * multi-currency store sums minor units of different things.
         *
         * Tax and shipping are columns rather than params because each is a
         * SUMMED METRIC, and a metric needs a column to sum: taxRevenue and
         * shippingRevenue cannot be expressed over a JSON document without a
         * generated column to index. The gateway and the order source stay
         * params, because nothing adds them up.
         *
         * transaction_id is a column for a different reason: it is what makes a
         * purchase countable once. Without it, two beacons for one order are two
         * transactions and there is nothing to group a line item back to.
         */
        $this->setProperty( $this->column( 'transaction_id', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'revenue', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'tax', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'shipping', OWA_DTD_BIGINT ) );
        $this->setProperty( $this->column( 'currency', OWA_DTD_CHAR3 ) );

        $this->setProperty( $this->column( 'raw_ua', OWA_DTD_VARCHAR1024 ) );

        /*
         * THE VISITOR'S NETWORK, reverse DNS of their address -- a third host,
         * and not either of the other two.
         *
         *   host         the page's own hostname, cut from page_location
         *   HTTP_HOST    this server's, the Host header of the beacon request
         *   remote_host  the visitor's, which is what this is
         *
         * v1 reduced it to a registered domain through the Public Suffix List and
         * reported it as the `host` dimension -- Entity\Host says "the visitor
         * came from some host" -- and v2 gave that NAME to the page's host. So the
         * fact needs a column of its own or it has nowhere to live.
         *
         * Usually empty, and that is the server's choice rather than ours: Apache
         * fills REMOTE_HOST only with HostnameLookups On, which is off by default
         * because it costs a DNS round trip per request.
         */
        $this->setProperty( $this->column( 'remote_host', OWA_DTD_VARCHAR255 ) );

        // Only what cannot be a column: site-defined keys unknown at release
        // time, and nested arrays. Everything the release knows the name of
        // gets a column of its own.
        $this->setProperty( $this->column( 'params', OWA_DTD_JSON ) );
        $this->properties['params']->setDescription(
            'The event\'s values that have no column of their own, as a JSON document keyed by name: '
          . 'custom variables and event properties, form and element details, and purchase line items.' );

        /*
         * THE VISITOR'S LAST NON-DIRECT TOUCH BEFORE THIS SESSION, as evidence:
         * the tags it was collected with, its referring host and when it was.
         * Read from the visitor store at ingest, on the landing beacon of a
         * returning visitor's session (TrackingEventHelpers::priorTouch()).
         *
         * Stamped rather than looked up later because the store holds only the
         * LATEST touch: by the time a cube is built, or rebuilt a year on, the
         * row may hold one that came after this session. The cube decides what
         * it means -- classification and the lookback window are both applied
         * there, so a change to either reaches history on rebuild (PLAN 2.29).
         *
         * Not on the cubes: the cube's attributed_* columns are the reading.
         */
        $this->setProperty( $this->column( 'prior_touch_source', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'prior_touch_medium', OWA_DTD_VARCHAR64 ) );
        $this->setProperty( $this->column( 'prior_touch_campaign', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'prior_touch_ad', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'prior_touch_referer_host', OWA_DTD_VARCHAR255 ) );
        $this->setProperty( $this->column( 'prior_touch_ts', OWA_DTD_BIGINT ) );

        /*
         * Indexes, as measured in the prototype. The two composites are the
         * report shapes: every query bounds site_id and a date range, and the
         * event-type one serves a breakdown of a single event over a period.
         * Two single-column indexes are not a substitute -- MySQL picks one and
         * filters the rest.
         */
        $this->addCompositeIndex( 'site_date', array( 'site_id', 'yyyymmdd' ) );
        $this->addCompositeIndex( 'site_type_date', array( 'site_id', 'event_type', 'yyyymmdd' ) );

        // A purchase already stored under this transaction id, looked up per
        // purchase at ingest (Classes\PurchaseDeduplication). Update057.
        $this->addCompositeIndex( 'site_transaction', array( 'site_id', 'transaction_id' ) );

        // The realtime screen's window: a site's last thirty minutes by ts, so
        // its cost follows the window rather than the day (Classes\Realtime).
        // Raw only -- nothing reads the cube by time. Update064.
        $this->addCompositeIndex( 'site_ts', array( 'site_id', 'ts' ) );
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
    /**
     * Written with the moment it reached raw.
     *
     * Here rather than in each writer, so no path that writes raw through the
     * entity can leave it out. A value already set is kept: a writer that
     * stamped a whole batch at once has said when it arrived.
     *
     * @return bool
     */
    function create() {

        if ( isset( $this->properties['created_at'] ) && ! $this->get( 'created_at' ) ) {

            $this->set( 'created_at', (int) round( microtime( true ) * 1000000 ) );
        }

        return parent::create();
    }

    private function column( $name, $type, $nullable = true ) {

        $column = new \OWA\Module\Base\Classes\DbColumn( $name, $type );

        if ( $nullable ) {

            $column->setNullable();
        }

        return $column;
    }
}

?>
