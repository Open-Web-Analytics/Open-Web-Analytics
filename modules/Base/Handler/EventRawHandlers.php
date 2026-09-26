<?php
namespace OWA\Module\Base\Handler;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v2 ingest: one beacon becomes its rows in owa_event_raw.
 *
 * Registered beside v1's handlers on the same tracking events, for EVERY site.
 * There is no setting: `v2_raw_collection` was a development instrument for
 * exercising ingest against one site, and it is gone now that the tracker sends
 * v2-shaped events.
 *
 * STILL NOT THE ARCHITECTURE. v2 is meant to collect where v1 does not run
 * beside it, and the two pipelines are not meant to be compared live --
 * migrating v1's history into v2 is the oracle. Both run today because the
 * reporting layer reads v1's tables and nothing reads owa_event yet: 151 metric
 * and dimension registrations, none of them over the cube. v1's ingest can stop
 * when that is no longer true, which is 2.25 step 4.
 *
 * WHAT THIS DOES THAT v1's HANDLERS DO NOT
 *
 *   - Writes ONE table. No session upsert, no dimension check-and-create, no
 *     foreign keys: a write is an insert with no lookups.
 *   - Derives the row id from the event's own content (Classes\V2Event::id),
 *     so a redelivered beacon derives the same ids instead of a second random
 *     one.
 *   - EXPANDS the beacon. The client sends one page_view carrying flags; the
 *     server raises session_start and first_visit from them, here, into raw.
 *     The test for which side a derivation falls on is whether it is a pure
 *     function of ONE beacon: these are, so they happen at ingest. Acquisition
 *     and session finalisation need other events, so they are the build's.
 *   - Records EVIDENCE and stops. The tagged values are transcribed; whether a
 *     referring host counts as organic search, a social network or a plain
 *     referral is a table that grows and gets corrected, so the reading is
 *     written by a build onto the cube row where a fix can be
 *     re-applied. A verdict is never written where it cannot be corrected.
 */
class EventRawHandlers extends \OWA\Core\Observer {

    /**
     * The wire prefixes that carry scope, matching the tracker's.
     *
     * Scope lives in the NAME so ingest never has to infer which bag a value
     * belongs to, and the same name in two scopes stays two different things.
     * See Tracker.EVENT_PROPERTY_PREFIX / USER_PROPERTY_PREFIX.
     */
    const EVENT_PROPERTY_PREFIX = 'ep_';
    const USER_PROPERTY_PREFIX  = 'up_';

    /**
     * The numeric halves, as GA spells them `epn.` and `upn.`.
     *
     * A query string carries no types, so a value arrives as text whatever the
     * site set. The prefix is the tracker saying which it meant, and it is the
     * only way to tell 42 from a string that merely looks like one -- a
     * version, a postcode, an order id with leading zeros.
     */
    const EVENT_PROPERTY_NUMBER_PREFIX = 'epn_';
    const USER_PROPERTY_NUMBER_PREFIX  = 'upn_';

    /**
     * How many custom properties one event may carry, per scope.
     *
     * The tracker refuses a 26th at the setter, where an author can see it.
     * This is the SECOND gate, because the tracker is not the only thing that
     * can post to the endpoint -- and because params is one JSON column on a
     * row already using most of MySQL's 65,535-byte limit, where an over-long
     * row is refused outright rather than truncated.
     */
    const MAX_CUSTOM_PROPERTIES = 25;


    /**
     * @param object $event
     */
    function notify( $event ) {

        $type = $event->getEventType();

        if ( ! \OWA\Module\Base\Classes\V2Event::isStorable( $type ) ) {

            \OWA\Core\CoreAPI::debug( sprintf(
                'v2 ingest: %s is not stored as an event.', $type ) );

            return OWA_EHS_EVENT_HANDLED;
        }

        /*
         * One beacon, before it becomes rows. A listener here sees the event
         * whole; after expand() there are three of them for a landing page_view
         * and no single place that means "the beacon".
         */
        $event = \OWA\Module\Base\Classes\Ingest::at( \OWA\Module\Base\Classes\Ingest::STORE_PRE, $event );

        $rows = $this->expand( $event );

        if ( ! $rows ) {

            return OWA_EHS_EVENT_HANDLED;
        }

        /*
         * IDEMPOTENCE, CHECKED ONCE, BEFORE ANY WRITE.
         *
         * A redelivered beacon derives the same ids, so every insert below
         * would fail on the primary key -- benignly, but indistinguishably from
         * an insert that failed for a real reason. Since the whole expansion
         * has to land together or not at all, "some inserts failed" cannot be
         * the signal to roll back unless duplicates are excluded first.
         *
         * So ask about the first row. Every row of one beacon is written in one
         * transaction, so the first one being present means all of them are,
         * and the beacon is already ingested. One lookup per beacon, which is
         * the same shape v1's RequestHandlers already pays.
         */
        if ( $this->alreadyIngested( $rows[0] ) ) {

            \OWA\Core\CoreAPI::debug( 'v2 ingest: beacon already stored, nothing to do.' );

            return OWA_EHS_EVENT_HANDLED;
        }

        return $this->store( $rows, $event );
    }

    /**
     * One beacon -> the rows it becomes, primary event first.
     *
     * ORDER MATTERS ONLY FOR THE CALLER's idempotence check, not for the ids:
     * each id is derived from the event name, so nothing here depends on the
     * order rows are built in.
     *
     * @param object $event
     * @return array[] each an array of column => value
     */
    protected function expand( $event ) {

        $name = \OWA\Module\Base\Classes\V2Event::name( $event->getEventType() );

        $primary = $this->row( $event, $name );

        if ( ! $primary ) {

            return array();
        }

        $rows = array( $primary );

        /*
         * The markers. Raised from flags that are already on this beacon, in
         * the same write, so a marker cannot be lost while its own page view
         * survives -- the beacon carrying the flag can be lost, and then the
         * session simply has no marker row, which a later build cannot repair
         * either way because re-reading raw reproduces the same partial state.
         *
         * is_new_session_start and is_new_visitor_created are REQUEST scoped:
         * they mark the one request that created the session or minted the
         * visitor. The page-scoped is_new_session / is_new_visitor ride every
         * event of a page or a session and answer a different question, so
         * they cannot raise a marker -- using them would raise one marker per
         * event of the first page.
         */
        if ( $name === 'page_view' ) {

            if ( $event->get( 'is_new_session_start' ) ) {

                $rows[] = $this->row(
                    $event, \OWA\Module\Base\Classes\V2Event::MARKER_SESSION_START );
            }

            if ( $event->get( 'is_new_visitor_created' ) ) {

                $rows[] = $this->row(
                    $event, \OWA\Module\Base\Classes\V2Event::MARKER_FIRST_VISIT );
            }
        }

        return array_values( array_filter( $rows ) );
    }

    /**
     * Build one raw row -- A MAPPING, NOT A DERIVATION.
     *
     * Every column is one property read off a formed event and coerced to what
     * the column stores. Nothing here works a value out: the registry declares
     * how each property is set and a callback sets it, so this method's whole
     * job is property name -> column name.
     *
     * It did not start that way. Sixteen columns were computed in here -- five
     * readings of the user agent, five campaign tags, six readings of a URL and
     * the three money conversions -- two of them in helper methods whose result
     * was merged with `+=`, which silently discards a key the literal already
     * set. That is how browser_version came to be the parser's answer in one
     * place and the beacon's claim in another.
     *
     * The rule now, and RowIsAMappingTest holds it: if a column exists, a
     * property backs it, and the property's value is what lands there. Two
     * things follow. A column cannot appear that no property declares, which is
     * the `revenue` bug -- this read a property of that name, the registry
     * declares none, and every purchase stored NULL. And the event handed to
     * Ingest::STORE_PRE is complete, so anything wanting to decide something
     * about a row can listen there rather than being a step in here.
     *
     * Four columns are not a property read, and RowIsAMappingTest lists each
     * with its reason: `id`, a hash OF the properties; `event_type`, which the
     * EXPANSION names -- one beacon becomes a page_view plus its session_start
     * and first_visit markers, so the property cannot answer for all three;
     * `is_goal_event`, raised by Classes\GoalMarking at Ingest::STORE_POST; and
     * `params`, which is by definition everything with no column.

     *
     * @param object $event
     * @param string $name  the v2 event name
     * @return array|null   null when the event cannot be identified
     */
    protected function row( $event, $name ) {

        $site_id    = (string) $event->get( 'site_id' );
        $visitor_id = $event->get( 'visitor_id' );
        $session_id = $event->get( 'session_id' );
        $ts         = $event->get( 'ts' );

        /*
         * A row that cannot say which site, visitor, session or instant it
         * belongs to is not an observation. Refused rather than stored with
         * gaps, because the id is derived from exactly these and a missing one
         * would silently collide every such event onto one id.
         */
        if ( ! $site_id || ! $visitor_id || ! $session_id || ! $ts || ! $name ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'v2 ingest: dropping an event, it carries no %s.',
                ! $site_id ? 'site id' : ( ! $visitor_id ? 'visitor id'
                    : ( ! $session_id ? 'session id'
                    : ( ! $ts ? 'timestamp' : 'event name' ) ) ) ) );

            return null;
        }

        /*
         * getSiteId() prefers `siteId` over `site_id`, where the guard above
         * reads `site_id`. Left as it was: the id hash and the stored column
         * agree with each other, which is the part that matters, and changing
         * which of the two names wins is not a change to the row builder.
         */
        $site_id = $event->getSiteId();

        $row = array(

            'id' => \OWA\Module\Base\Classes\V2Event::id(
                $site_id, $visitor_id, $session_id, $ts, $name ),

            'event_type' => $name,
            'site_id'    => $site_id,
            'visitor_id' => $visitor_id,
            'session_id' => $session_id,
            'user_id'    => $this->text( $event->get( 'user_id' ) ),

            'ts'                => $ts,
            'yyyymmdd'          => $event->get( 'yyyymmdd' ),

            'visitor_fsts'   => $this->number( $event->get( 'fsts' ) ),
            'prior_sessions' => $this->number( $event->get( 'num_prior_sessions' ) ),
            'session_start_ts'       => $this->number( $event->get( 'sts' ) ),
            'prior_session_start_ts' => $this->number( $event->get( 'psts' ) ),
            'event_seq'      => $this->number( $event->get( 'event_seq' ) ),
            'beacon_version' => $this->number( $event->get( 'beacon_version' ) ),

            'page_location' => $this->text( $event->get( 'page_location' ) ),
            'page_path'     => $this->text( $event->get( 'page_path' ) ),
            'page_query'    => $this->text( $event->get( 'page_query' ) ),
            'page_title'    => $this->text( $event->get( 'page_title' ) ),
            'content_group' => $this->text( $event->get( 'content_group' ) ),
            'referer_url'   => $this->text( $event->get( 'HTTP_REFERER' ) ),
            'referer_host'  => $this->text( $event->get( 'referer_host' ) ),
            'referer_query' => $this->text( $event->get( 'referer_query' ) ),

            'browser_type'    => $this->text( $event->get( 'browser_type' ) ),
            'browser_version' => $this->text( $event->get( 'browser_version' ) ),
            'os_version'      => $this->text( $event->get( 'os_version' ) ),
            'device_type'     => $this->text( $event->get( 'device_type' ) ),
            'device_brand'    => $this->text( $event->get( 'device_brand' ) ),
            'device_model'    => $this->text( $event->get( 'device_model' ) ),
            'os'              => $this->text( $event->get( 'os' ) ),
            'language'        => $this->text( $event->get( 'language' ) ),

            'country'      => $this->text( $event->get( 'country' ) ),
            'country_code' => $this->text( $event->get( 'country_code' ) ),
            'city'         => $this->text( $event->get( 'city' ) ),
            'region'       => $this->text( $event->get( 'state' ) ),

            'host'          => $this->text( $event->get( 'host' ) ),
            'ip_address'    => $this->text( $event->get( 'ip_address' ) ),
            'consent_state' => $this->text( $event->get( 'consent_state' ) ),

            'click_x'     => $this->number( $event->get( 'click_x' ) ),
            'click_y'     => $this->number( $event->get( 'click_y' ) ),
            'page_width'  => $this->number( $event->get( 'page_width' ) ),
            'page_height' => $this->number( $event->get( 'page_height' ) ),

            'tagged_source'       => $this->text( $event->get( 'tagged_source' ) ),
            'tagged_medium'       => $this->text( $event->get( 'tagged_medium' ) ),
            'tagged_campaign'     => $this->text( $event->get( 'tagged_campaign' ) ),
            'tagged_ad'           => $this->text( $event->get( 'tagged_ad' ) ),
            'tagged_search_terms' => $this->text( $event->get( 'tagged_terms' ) ),

            'target_url'  => $this->text( $event->get( 'target_url' ) ),
            'target_host' => $this->text( $event->get( 'target_host' ) ),

            'element_path' => $this->text( $event->get( 'element_path' ) ),
            'element_tag'  => $this->text( $event->get( 'dom_element_tag' ) ),
            'element_id'   => $this->text( $event->get( 'dom_element_id' ) ),

            'scroll_depth'    => $this->number( $event->get( 'scroll_depth' ) ),
            'engagement_msec' => $this->number( $event->get( 'engagement_msec' ) ),

            /*
             * Whether this row met a goal condition. GA's shape: the key event
             * IS the event, flagged -- no separate row, so eventCount stays a
             * count of what happened.
             *
             * 0 HERE, DECIDED AT Ingest::STORE_POST. The column is NOT NULL and
             * strict mode aborts an insert that hands it NULL, so the literal
             * carries the default and Classes\GoalMarking -- a listener on the
             * complete row -- is what raises it. It has to be the complete row:
             * device_type and the tagged_* columns are merged in below this
             * literal, and matching from the event could not see them.
             */
            'is_goal_event' => 0,

            /*
             * ct_total, ct_tax and ct_shipping are the names the wire has
             * carried since 1.x and the ones the registry declares for the
             * purchase event; the columns are minor units, and toMinorUnits()
             * has already converted each property to what its column stores.
             *
             * This read a property named `revenue`, which the registry does not
             * declare, so the column was NULL on every purchase ever stored.
             */
            'revenue'  => $this->number( $event->get( 'ct_total' ) ),
            'tax'      => $this->number( $event->get( 'ct_tax' ) ),
            'shipping' => $this->number( $event->get( 'ct_shipping' ) ),
            'currency' => $this->text( $event->get( 'currency' ) ),

            /*
             * The order's own id, under the name v2 reports it by. Nothing
             * derived: it is the merchant's identifier and the only thing that
             * makes one purchase countable once.
             */
            'transaction_id' => $this->text( $event->get( 'ct_order_id' ) ),

            'raw_ua'      => $this->text( $event->get( 'HTTP_USER_AGENT' ) ),
            'remote_host' => $this->text( $event->get( 'REMOTE_HOST' ) ),
            'params' => $this->params( $event ),
        );

        return $row;
    }

    /**
     * The params JSON -- what cannot be a column.
     *
     * Site-defined keys unknown at release time, and the per-event-type values
     * that are too narrow to earn a column of their own. Every field whose name
     * the release knows gets a column instead: the wide prototype measured
     * storage as a wash, JSON extraction is slower than a column, and MySQL 8's
     * INSTANT ADD COLUMN removes the ALTER cost for release-known fields.
     *
     * Returns null rather than '{}' for an event with nothing to carry, so
     * COUNT(params) answers how many events carried any.
     *
     * @param object $event
     * @return string|null JSON
     */
    protected function params( $event ) {

        $params = array();

        // Custom variables, by NAME. 1.x stores them in five numbered slots and
        // then has to report cv3 as a dimension whose meaning differs per site;
        // keyed by name they are ordinary param paths.
        $max = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );

        for ( $i = 1; $i <= $max; $i++ ) {

            $name  = $event->get( 'cv' . $i . '_name' );
            $value = $event->get( 'cv' . $i . '_value' );

            if ( $name && $name !== \OWA\Module\Base\Classes\TrackingEventHelpers::ABSENT_VALUE_LABEL ) {

                $params[ (string) $name ] = $value;
            }
        }

        /*
         * Custom event properties, by name, with the `ep_` prefix stripped.
         *
         * The prefix is how the beacon says which scope a value belongs to
         * (Tracker.setEventProperty), so this needs no allowlist and no
         * knowledge of the site's keys -- unlike the per-event-type params
         * below, which are names the release knows. `up_` is the other half and
         * goes to the visitor store, not here.
         */
        $custom = 0;

        foreach ( (array) $event->getProperties() as $key => $value ) {

            $key = (string) $key;

            /*
             * The numeric prefix is tested FIRST, because 'ep_' is a prefix of
             * nothing else but 'epn_' begins with neither -- test the shorter
             * one first and `epn_plan` is read as an event property named
             * `n_plan`, which is a real value under a name nobody set.
             */
            if ( strpos( $key, self::EVENT_PROPERTY_NUMBER_PREFIX ) === 0 ) {

                $name    = substr( $key, strlen( self::EVENT_PROPERTY_NUMBER_PREFIX ) );
                $numeric = true;

            } elseif ( strpos( $key, self::EVENT_PROPERTY_PREFIX ) === 0 ) {

                $name    = substr( $key, strlen( self::EVENT_PROPERTY_PREFIX ) );
                $numeric = false;

            } else {

                continue;
            }

            if ( $name === '' || $value === null || $value === false || $value === '' ) {

                continue;
            }

            if ( $custom >= self::MAX_CUSTOM_PROPERTIES ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'v2 ingest: refused custom event property "%s"; the cap is %d.',
                    $name, self::MAX_CUSTOM_PROPERTIES ) );

                continue;
            }

            $custom++;

            /*
             * A declared number that will not convert is DROPPED, not stored as
             * text: the prefix is a claim about the type, and storing a string
             * under it would make the claim unreliable for every reader that
             * trusted it.
             */
            if ( $numeric ) {

                if ( ! is_numeric( $value ) ) {

                    \OWA\Core\CoreAPI::notice( sprintf(
                        'v2 ingest: custom event property "%s" is declared numeric and is not.',
                        $name ) );

                    continue;
                }

                $value = $value + 0;
            }

            $params[ $name ] = $value;
        }

        /*
         * Event params the release knows by name but which belong to ONE event
         * type each. A column for them would be a column that is NULL on every
         * other row; these are what the dimension vocabulary reaches as
         * params.<name> paths.
         *
         * KEYED BY EVENT TYPE, not one flat list. A flat list collects any
         * property that happens to exist on the event with a falsy-but-real
         * value -- numeric_value is registered with a default of 0, so every
         * page_view came out carrying {"numeric_value": 0}, a param the event
         * does not have wearing a value it was never given.
         */
        foreach ( $this->declaredParams( $event ) as $wire => $key ) {

            $value = $event->get( $wire );

            if ( $value !== null && $value !== false && $value !== '' ) {

                $params[ $key ] = $value;
            }
        }

        if ( ! $params ) {

            return null;
        }

        /*
         * JSON_UNESCAPED_UNICODE keeps the column readable and, more to the
         * point, keeps it the same size as the value: escaping every non-ASCII
         * character to \uXXXX triples a Japanese page title for no gain.
         */
        $json = json_encode( $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // json_encode returns false on malformed UTF-8. A JSON column refuses
        // an invalid value outright under a strict sql_mode, so the row would
        // be LOST rather than truncated. Dropping the params keeps the event.
        if ( $json === false ) {

            \OWA\Core\CoreAPI::notice(
                'v2 ingest: event params could not be encoded as JSON and were dropped.' );

            return null;
        }

        return $json;
    }

    /**
     * The params this event type may carry, as wire name => params key.
     *
     * READ FROM THE REGISTRY. This was a map written by hand here, and the hand
     * was wrong: it listed `transaction_id, tax, shipping, gateway, items` for a
     * purchase while the wire sends `ct_order_id, ct_tax, ct_shipping,
     * ct_gateway, ct_line_items`, so every lookup missed, `params` came back
     * NULL, and a NULL params column is indistinguishable from an event that
     * carried none. Four of its click entries -- link_url, link_domain,
     * link_text, outbound -- named fields no tracker has ever sent.
     *
     * Every one of those is a fact the registry already holds: which events carry
     * a property, what the wire calls it, and what key it is reached by. Asking
     * it is how the three stop drifting apart.
     *
     * @param object $event
     * @return array  wire name => params key
     */
    protected function declaredParams( $event ) {

        return \OWA\Module\Base\Classes\TrackingEventHelpers::paramsForEvent(
            \OWA\Module\Base\Classes\V2Event::name( $event->getEventType() ) );
    }

    /**
     * Has this beacon already been written?
     *
     * @param array $row
     * @return bool
     */
    protected function alreadyIngested( $row ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        $entity->load( $row['id'], 'id',
            \OWA\Core\Db::factDateConstraint( $row['yyyymmdd'] ) );

        return (bool) $entity->wasPersisted();
    }

    /**
     * Write the rows, and the visitor store row if this is a first session.
     *
     * ONE ATOMIC WRITE OF EVERYTHING THE BEACON BECOMES. A page_view that
     * landed without its session_start is not something a later build can
     * repair: the flag was on the beacon that half-wrote, so re-reading raw
     * reproduces the same partial state. The guarantee has to be made where the
     * rows are created.
     *
     * 1.x does not do this. Db::beginTransaction() exists and the fact tables
     * are InnoDB, but its only callers are the schema-update CLI -- nothing
     * depended on it while one beacon meant one row.
     *
     * @param array[] $rows
     * @param object  $event
     * @return int
     */
    protected function store( $rows, $event ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->beginTransaction();

        foreach ( $rows as $row ) {

            /*
             * PER ROW, immediately before the INSERT, and the row is COMPLETE
             * here -- deviceColumns() and taggedColumns() are merged, which they
             * are not while row()'s literal is being built. A decision about the
             * row belongs here and nowhere earlier.
             *
             * The event rides as context so a listener can read it without being
             * able to swap it. Three rows from one landing page_view each reach
             * this on their own facts, which is what lets a goal target
             * session_start rather than the page view that materialised it.
             */
            $row = \OWA\Module\Base\Classes\Ingest::at( \OWA\Module\Base\Classes\Ingest::STORE_POST, $row, $event );

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );
            $entity->setProperties( $row );

            if ( $entity->create() !== true ) {

                $db->rollbackTransaction();

                \OWA\Core\CoreAPI::error( sprintf(
                    'v2 ingest: writing %s failed; the whole beacon was rolled back.',
                    $row['event_type'] ) );

                return OWA_EHS_EVENT_FAILED;
            }
        }

        if ( ! $this->writeVisitorAcquisition( $event, $rows[0] ) ) {

            $db->rollbackTransaction();

            return OWA_EHS_EVENT_FAILED;
        }

        if ( ! $this->writeUserProperties( $event, $rows[0] ) ) {

            $db->rollbackTransaction();

            return OWA_EHS_EVENT_FAILED;
        }

        $db->endTransaction();

        $this->announce( $event, $rows );

        return OWA_EHS_EVENT_HANDLED;
    }

    /**
     * Tell anything that cares what just happened.
     *
     * AFTER THE COMMIT, deliberately. Raised inside the transaction, a beacon
     * that then rolled back would still have announced a session that does not
     * exist -- and an email is not retractable.
     *
     * SYNCHRONOUS, and it needs nothing else. EventDispatch::notify() calls the
     * listeners in-process and logs "no listeners registered" when there are
     * none, so an event nobody wants costs a array_key_exists and stops.
     * asyncNotify() is a deprecated alias for exactly this.
     *
     * NOTHING ACCUMULATES. The queue is a RETRY queue, not a dispatch queue:
     * notify() only calls sendMessage() when a handler returns EVENT_FAILED.
     * So these raise no rows unless a listener actually fails, which is what
     * the queue is for.
     *
     * WHY HERE AND NOT FROM v1. base.new_session used to be raised by v1's
     * SessionHandlers, two hops downstream of the beacon -- page_request to
     * page_request_logged to the session write to the announcement. Every hop
     * was v1 machinery kept alive to deliver one signal. v2 knows all three
     * facts at ingest, because it is what materialises the marker rows.
     *
     * The event carries the BEACON's properties, which is what the
     * announcement templates read: visitor_id, user_name, host, city, country,
     * page_title, page_url.
     *
     * @param object  $event the incoming tracking event
     * @param array[] $rows  the rows just written, primary first
     * @return void
     */
    protected function announce( $event, array $rows ) {

        $names = array();

        foreach ( $rows as $row ) {

            $names[ (string) $row['event_type'] ] = true;
        }

        $dispatch = \OWA\Core\CoreAPI::getEventDispatch();

        $announcements = array(
            \OWA\Module\Base\Classes\V2Event::MARKER_SESSION_START => 'base.new_session',
            \OWA\Module\Base\Classes\V2Event::MARKER_FIRST_VISIT   => 'base.new_visitor',
            'page_view'                                             => 'base.new_page_view',
        );

        foreach ( $announcements as $marker => $announcement ) {

            if ( ! isset( $names[ $marker ] ) ) {

                continue;
            }

            $notice = $dispatch->makeEvent( $announcement );
            $notice->setProperties( $event->getProperties() );

            $dispatch->notify( $notice );
        }
    }

    /**
     * User-scoped custom properties, onto the visitor store.
     *
     * LAST VALUE WINS, which is the opposite discipline from the acquisition
     * columns beside them: acq_* is write-once evidence captured at the first
     * visit, and a property is mutable state a site sets whenever it likes. GA
     * resolves the same way -- "the most recent value of a user property for
     * each user".
     *
     * EACH CARRIES WHEN IT WAS SET, and the timestamp is a guard as well as a
     * record. The build stamps the CURRENT value onto every event row, so
     * without it a row says what the value is and not whether it applied yet;
     * and comparing it is what stops an out-of-order queue drain overwriting a
     * newer value with an older beacon. Same shape as GA's
     * set_timestamp_micros.
     *
     *   {"plan": {"v": "enterprise", "ts": 1790000000000000}}
     *
     * IN THE CALLER'S TRANSACTION, alongside the raw rows, so a visitor never
     * carries a property for an event that was not stored.
     *
     * @param object $event
     * @param array  $row  the primary row, already built
     * @return bool
     */
    protected function writeUserProperties( $event, $row ) {

        $incoming = array();

        foreach ( (array) $event->getProperties() as $key => $value ) {

            $key = (string) $key;

            // Longest prefix first; see the note in params().
            if ( strpos( $key, self::USER_PROPERTY_NUMBER_PREFIX ) === 0 ) {

                $name    = substr( $key, strlen( self::USER_PROPERTY_NUMBER_PREFIX ) );
                $numeric = true;

            } elseif ( strpos( $key, self::USER_PROPERTY_PREFIX ) === 0 ) {

                $name    = substr( $key, strlen( self::USER_PROPERTY_PREFIX ) );
                $numeric = false;

            } else {

                continue;
            }

            if ( $name === '' || $value === null || $value === false || $value === '' ) {

                continue;
            }

            if ( count( $incoming ) >= self::MAX_CUSTOM_PROPERTIES ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'v2 ingest: refused custom user property "%s"; the cap is %d.',
                    $name, self::MAX_CUSTOM_PROPERTIES ) );

                continue;
            }

            if ( $numeric && ! is_numeric( $value ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'v2 ingest: custom user property "%s" is declared numeric and is not.',
                    $name ) );

                continue;
            }

            $incoming[ $name ] = $numeric ? $value + 0 : (string) $value;
        }

        // Nothing set on this beacon, which is almost every beacon.
        if ( ! $incoming ) {

            return true;
        }

        $ts     = (int) $row['ts'];
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );

        $entity->load( $row['visitor_id'], 'visitor_id' );

        $existing = $entity->wasPersisted()
            ? (array) json_decode( (string) $entity->get( 'properties' ), true )
            : array();

        $merged  = $existing;
        $changed = false;

        foreach ( $incoming as $name => $value ) {

            // An older beacon never displaces a newer value. A queue drain can
            // deliver events out of order, and without this the last one
            // WRITTEN would win rather than the last one SET.
            if ( isset( $merged[ $name ]['ts'] ) && (int) $merged[ $name ]['ts'] > $ts ) {

                continue;
            }

            if ( isset( $merged[ $name ]['v'] ) && $merged[ $name ]['v'] === $value ) {

                continue;
            }

            $merged[ $name ] = array( 'v' => $value, 'ts' => $ts );
            $changed = true;
        }

        if ( ! $changed ) {

            return true;
        }

        $json = json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // json_encode answers false on malformed UTF-8, and a JSON column
        // refuses an invalid value outright under a strict sql_mode -- so the
        // row would be LOST rather than the properties dropped. Keeping the
        // event is worth more than the properties.
        if ( $json === false ) {

            return true;
        }

        if ( $entity->wasPersisted() ) {

            $entity->setProperties( array( 'properties' => $json ) );

            // update('visitor_id'): the no-argument form keys on an `id`
            // column and this table has none.
            if ( $entity->update( 'visitor_id' ) === false ) {

                \OWA\Core\CoreAPI::error( 'v2 ingest: writing user properties failed.' );

                return false;
            }

            return true;
        }

        $entity->setProperties( array(
            'visitor_id' => $row['visitor_id'],
            'site_id'    => $row['site_id'],
            'properties' => $json,
            'last_seen'  => (int) substr( (string) $row['yyyymmdd'], 0, 6 ),
        ) );

        /*
         * No acq_ts. The row exists to hold a property, and the acquisition is
         * still unknown -- which is exactly why the cube's sentinel tests
         * acq_ts rather than the row (2.26.6). A racing insert is benign: the
         * other writer put the row there, and the next beacon merges into it.
         */
        if ( $entity->create() !== true ) {

            $check = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );
            $check->load( $row['visitor_id'], 'visitor_id' );

            if ( $check->wasPersisted() ) {

                return true;
            }

            \OWA\Core\CoreAPI::error( 'v2 ingest: creating the row for user properties failed.' );

            return false;
        }

        return true;
    }

    /**
     * Insert-if-absent into the visitor store, for a visitor's first session.
     *
     * ANY EVENT OF THAT SESSION MAY WRITE IT, not only the one that raised
     * first_visit. A session cannot change its attribution part-way -- new tags
     * start a new session -- so every event of it resolves the same
     * acquisition, and whichever lands first writes the row while the rest are
     * no-ops. Losing one beacon therefore does not cost the acquisition, and
     * the failure mode stays `absent then present` rather than `wrong then
     * corrected`.
     *
     * A VISITOR WITH NO KNOWN ACQUISITION GETS NO ROW. A placeholder would be
     * found present when the real first_visit arrived late on a queue drain and
     * would block the real value permanently and silently; a build writes its
     * sentinel from the row being missing instead.
     *
     * @param object $event
     * @param array  $row  the primary row, already built
     * @return bool
     */
    protected function writeVisitorAcquisition( $event, $row ) {

        /*
         * prior_sessions == 0 alone. The session-scoped is_new_visitor flag
         * said the same thing and was ORed in for robustness -- ANY event of
         * the first session may write this row -- but that robustness is what
         * this half already gives: the count rides every beacon, so losing one
         * still leaves the rest able to write the acquisition.
         */
        $is_first_session = (string) $event->get( 'num_prior_sessions' ) === '0';

        if ( ! $is_first_session ) {

            return true;
        }

        $acquisition = array(
            'acq_source'       => $row['tagged_source'],
            'acq_medium'       => $row['tagged_medium'],
            'acq_campaign'     => $row['tagged_campaign'],
            'acq_ad'           => $row['tagged_ad'],
            'acq_search_terms' => $row['tagged_search_terms'],
            'acq_referer_url'  => $row['referer_url'],
            'acq_referer_host' => $row['referer_host'],
        );

        // Nothing to stamp, so no row. See above.
        if ( ! array_filter( $acquisition, function ( $v ) { return $v !== null && $v !== ''; } ) ) {

            return true;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );

        $entity->load( $row['visitor_id'], 'visitor_id' );

        /*
         * A ROW MAY EXIST WITHOUT AN ACQUISITION. A user property can create
         * one for a visitor whose acquisition is still unknown, so "the row is
         * there" no longer means "this is already captured" -- acq_ts does.
         *
         * Filling the columns of an acquisition-less row rather than skipping
         * it is what keeps write-once true across a late first_visit: a queue
         * drain that delivers it after a property write still lands, where
         * skipping on row-presence would have lost it permanently and
         * silently. Once acq_ts is set nothing here touches it again.
         */
        if ( $entity->wasPersisted() ) {

            if ( $entity->get( 'acq_ts' ) ) {

                return true;
            }

            $entity->setProperties( $acquisition + array( 'acq_ts' => $row['ts'] ) );

            // update('visitor_id'), not update(): the no-argument form keys on
            // an `id` column, and this table has none -- its primary key is
            // visitor_id. Called bare it builds WHERE id = NULL, matches
            // nothing, and reports failure having written nothing.
            if ( $entity->update( 'visitor_id' ) === false ) {

                \OWA\Core\CoreAPI::error(
                    'v2 ingest: filling the visitor acquisition row failed.' );

                return false;
            }

            return true;
        }

        $entity->setProperties( $acquisition + array(
            'visitor_id' => $row['visitor_id'],
            'site_id'    => $row['site_id'],
            'acq_ts'     => $row['ts'],
            // A PERIOD, yyyymm. A build advances it from there; ingest writes
            // the one it is creating the row in so the TTL has something to
            // read before a pass has ever run.
            'last_seen'  => (int) substr( (string) $row['yyyymmdd'], 0, 6 ),
        ) );

        if ( $entity->create() !== true ) {

            /*
             * A duplicate key here is the benign case -- two events of the same
             * first session racing, which is exactly what insert-if-absent
             * expects -- and it is indistinguishable from a real failure at this
             * level. Re-reading settles it: if the row is there now, someone
             * else wrote it and there is nothing to fix.
             */
            $check = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' );
            $check->load( $row['visitor_id'], 'visitor_id' );

            if ( $check->wasPersisted() ) {

                return true;
            }

            \OWA\Core\CoreAPI::error(
                'v2 ingest: writing the visitor acquisition row failed.' );

            return false;
        }

        return true;
    }

    /**
     * A text value, or null when it is absent.
     *
     * Absence is NULL in v2 -- no '(not set)' reaches a column. The label is
     * applied when a value is RENDERED, so a null groups with the other nulls
     * and is labelled once, at the edge.
     *
     * Control bytes are removed on the way in. The pass's unresolved sentinel
     * is one (Classes\V2Event::UNRESOLVED), and it only means anything if a
     * visitor cannot write it: a campaign tag carrying \x1A would otherwise
     * produce a row claiming OUR pipeline had failed to resolve it. Stripping
     * here keeps the two alphabets disjoint, and a VARCHAR wants it anyway.
     *
     * The strip happens BEFORE the emptiness tests below, so a value that was
     * nothing but control bytes lands as NULL rather than as ''.
     *
     * @param mixed $value
     * @return string|null
     */

    protected function text( $value ) {

        if ( $value === null || $value === false || $value === '' ) {

            return null;
        }

        $value = \OWA\Module\Base\Classes\V2Event::strip( (string) $value );

        if ( trim( $value ) === ''
             || $value === \OWA\Module\Base\Classes\TrackingEventHelpers::ABSENT_VALUE_LABEL
             || $value === '(unknown)' ) {

            return null;
        }

        return $value;
    }

    /**
     * A numeric value, or null when it is absent.
     *
     * Kept apart from text() because 0 is a real number and an empty string is
     * not a zero -- collapsing the two is how a falsy check turns a legitimate
     * 0 into NULL.
     *
     * @param mixed $value
     * @return int|null
     */
    protected function number( $value ) {

        if ( $value === null || $value === false || $value === '' || ! is_numeric( $value ) ) {

            return null;
        }

        return (int) $value;
    }
}

?>
