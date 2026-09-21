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
 * Registered beside v1's handlers on the same tracking events, and OFF unless a
 * site turns on `v2_raw_collection`. That is a development instrument, not the
 * architecture: v2 collects and v1 does not run beside it, and the two
 * pipelines are not meant to be compared live -- migrating v1's history into
 * v2 is the oracle. But the schema is not right until something writes to all
 * of it, and one site collecting into raw is how that gets found out.
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
     * @param object $event
     */
    function notify( $event ) {

        if ( ! self::isEnabledForSite( $event->get( 'site_id' ) ) ) {

            return OWA_EHS_EVENT_HANDLED;
        }

        $type = $event->getEventType();

        if ( ! \OWA\Module\Base\Classes\V2Event::isStorable( $type ) ) {

            \OWA\Core\CoreAPI::debug( sprintf(
                'v2 ingest: %s is not stored as an event.', $type ) );

            return OWA_EHS_EVENT_HANDLED;
        }

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
     * Whether this site collects into v2 yet.
     *
     * Profile-scoped, so one site can be switched on without touching the rest
     * of the installation -- which is the entire point of it being a setting.
     *
     * @param string $site_id
     * @return bool
     */
    public static function isEnabledForSite( $site_id ) {

        if ( ! $site_id ) {

            return false;
        }

        return (bool) \OWA\Core\CoreAPI::getSetting(
            'base', 'v2_raw_collection', 'profile', $site_id );
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
     * Build one raw row.
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
        if ( ! $site_id || ! $visitor_id || ! $session_id || ! $ts ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'v2 ingest: dropping %s, it carries no %s.',
                $name,
                ! $site_id ? 'site id' : ( ! $visitor_id ? 'visitor id'
                    : ( ! $session_id ? 'session id' : 'timestamp' ) ) ) );

            return null;
        }

        /*
         * The COMPLETE URL, not the canonical one. page_url has had the
         * campaign parameters and the site's query_string_filters stripped out
         * of it by the time a handler sees it, which is right for v1's document
         * identity and wrong for evidence -- the tags are parsed out of this.
         * page_location is sent by the v2 tracker and stashed by
         * TrackingEventHelpers::keepCompleteUrl() for older beacons; page_url
         * is the last resort, and then the query is genuinely gone.
         */
        $location = $event->get( 'page_location' ) ?: $event->get( 'page_url' );

        $page = \OWA\Module\Base\Classes\V2Event::parseUrl( $location );
        $target = \OWA\Module\Base\Classes\V2Event::parseUrl( $event->get( 'target_url' ) );
        $referer = \OWA\Module\Base\Classes\V2Event::parseUrl( $event->get( 'HTTP_REFERER' ) );

        $row = array(

            'id' => \OWA\Module\Base\Classes\V2Event::id(
                $site_id, $visitor_id, $session_id, $ts, $name ),

            'event_type' => $name,
            'site_id'    => $site_id,
            'visitor_id' => $visitor_id,
            'session_id' => $session_id,
            'user_id'    => $this->text( $event->get( 'user_id' ) ),

            'ts'                => $ts,
            'clock_offset_usec' => $this->clockOffset( $event, $ts ),
            'yyyymmdd'          => $event->get( 'yyyymmdd' ),

            'visitor_fsts'   => $this->number( $event->get( 'fsts' ) ),
            'prior_sessions' => $this->number( $event->get( 'num_prior_sessions' ) ),
            'prev_event_ts'  => $this->previousEventTs( $event, $ts ),

            'page_location' => $this->text( $location ),
            'page_path'     => $page['path'],
            'page_query'    => $page['query'],
            'page_title'    => $this->text( $event->get( 'page_title' ) ),
            'content_group' => $this->text( $event->get( 'content_group' ) ),
            'referer_url'   => $this->text( $event->get( 'HTTP_REFERER' ) ),
            'referer_host'  => $referer['host'],
            'referer_query' => $referer['query'],

            'browser'         => $this->text( $event->get( 'browser_type' ) ),
            'browser_type'    => $this->text( $event->get( 'browser_type' ) ),
            'browser_version' => $this->text( $event->get( 'browser' ) ),
            'os'              => $this->text( $event->get( 'os' ) ),
            'language'        => $this->text( $event->get( 'language' ) ),

            'country'      => $this->text( $event->get( 'country' ) ),
            'country_code' => $this->text( $event->get( 'country_code' ) ),
            'city'         => $this->text( $event->get( 'city' ) ),
            'region'       => $this->text( $event->get( 'state' ) ),

            'host'          => $page['host'] ?: $this->text( $event->get( 'host' ) ),
            'ip_address'    => $this->text( $event->get( 'ip_address' ) ),
            'consent_state' => $this->text( $event->get( 'consent_state' ) ),

            'click_x'     => $this->number( $event->get( 'click_x' ) ),
            'click_y'     => $this->number( $event->get( 'click_y' ) ),
            'page_width'  => $this->number( $event->get( 'page_width' ) ),
            'page_height' => $this->number( $event->get( 'page_height' ) ),

            'target_url'  => $this->text( $event->get( 'target_url' ) ),
            'target_host' => $target['host'],

            'element_path' => $this->text( $event->get( 'element_path' ) ),
            'element_tag'  => $this->text( $event->get( 'dom_element_tag' ) ),
            'element_id'   => $this->text( $event->get( 'dom_element_id' ) ),

            'scroll_depth'    => $this->number( $event->get( 'scroll_depth' ) ),
            'engagement_msec' => $this->number( $event->get( 'engagement_msec' ) ),

            // Set on the row a goal condition MATERIALISED, never on its
            // trigger. Nothing materialises goal events yet, so this is 0 on
            // every row -- written explicitly because the column is NOT NULL
            // and a boolean that can be absent groups as three values.
            'is_goal_event' => 0,

            'revenue'  => $this->number( $event->get( 'revenue' ) ),
            'currency' => $this->text( $event->get( 'currency' ) ),

            'raw_ua' => $this->text( $event->get( 'HTTP_USER_AGENT' ) ),
            'params' => $this->params( $event ),
        );

        $row += $this->deviceColumns( $event );
        $row += $this->taggedColumns( $event, $name );

        return $row;
    }

    /**
     * Server receipt minus the client's own clock at send, in microseconds.
     *
     * Skew becomes a stored number instead of a silent error. 1.x subtracts a
     * client clock from a server one -- last_req from timestamp -- and records
     * no provenance for either, so a device whose clock is a day out produces a
     * session length nobody can identify as wrong.
     *
     * NULL where the beacon carried no client time, which is not the same as
     * zero skew.
     *
     * NOT GA's event_server_timestamp_offset, which its schema defines as
     * collection time minus upload time. That is queue lag and answers a
     * different question.
     *
     * @param object $event
     * @param int    $ts  server receipt, microseconds
     * @return int|null
     */
    protected function clockOffset( $event, $ts ) {

        $client = $event->get( 'client_ts_usec' );

        if ( ! $client || ! is_numeric( $client ) ) {

            return null;
        }

        return (int) $ts - (int) $client;
    }

    /**
     * The visitor's previous event, in server time.
     *
     * The tracker sends last_req -- the prior request's time, read from the
     * session store BEFORE the session decision discards it, so the first event
     * of a new session carries the last event of the PREVIOUS one. That is
     * exactly what "time since last visit" means.
     *
     * Client-clock, so it is corrected by the same offset this row already
     * records. Without a client clock there is nothing to correct against and
     * the answer is NULL, which is the honest reading -- not zero, and not a
     * value silently mixing two clocks the way 1.x does.
     *
     * NULL is also right when the state store is gone: nothing knows when the
     * visitor was last here, and inventing an anchor would be worse.
     *
     * @param object $event
     * @param int    $ts  server receipt, microseconds
     * @return int|null microseconds
     */
    protected function previousEventTs( $event, $ts ) {

        $last_req = $event->get( 'last_req' );

        if ( ! $last_req || ! is_numeric( $last_req ) ) {

            return null;
        }

        $offset = $this->clockOffset( $event, $ts );

        if ( $offset === null ) {

            return null;
        }

        // last_req is seconds on the client's clock.
        return (int) ( $last_req * 1000000 ) + $offset;
    }

    /**
     * browser / os version and the device, from the one user-agent parse.
     *
     * Split out because all six come from the same parser object and asking it
     * once is the point -- resolveBrowserType() and friends each fetch the
     * browscap singleton separately, which is free only because it is cached.
     *
     * device_type is DERIVED here rather than read: ua-parser has no such
     * field. Its device rules answer brand, model and family, and 'Other' is
     * its word for "no rule matched" -- an answer about a desktop browser and
     * an absence about a phone. The OS family is what tells those apart, so the
     * rule reads the OS first and falls through to NULL rather than guessing
     * desktop, because a wrong 'desktop' is indistinguishable from a real one.
     *
     * @param object $event
     * @return array
     */
    protected function deviceColumns( $event ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $bcap    = $service->getBrowscap( $event->get( 'HTTP_USER_AGENT' ) );

        $brand = $this->text( $bcap->getDeviceBrand() );
        $model = $this->text( $bcap->getDeviceModel() );
        $os    = strtolower( (string) $bcap->getOsFamily() );

        $mobile_os = array( 'android', 'ios', 'windows phone', 'blackberry os',
                            'firefox os', 'kaios', 'harmonyos' );

        $desktop_os = array( 'windows', 'mac os x', 'macos', 'linux', 'ubuntu',
                             'chrome os', 'fedora', 'debian', 'freebsd' );

        $family = strtolower( (string) $bcap->getDeviceFamily() );

        if ( $family === 'ipad' || strpos( $family, 'tablet' ) !== false ) {

            $device_type = 'tablet';

        } elseif ( in_array( $os, $mobile_os, true ) ) {

            $device_type = 'mobile';

        } elseif ( in_array( $os, $desktop_os, true ) ) {

            $device_type = 'desktop';

        } else {

            $device_type = null;
        }

        return array(
            'browser_version' => $this->text( $bcap->getUaVersion() ),
            'os_version'      => $this->text( $bcap->getOsVersion() ),
            'device_type'     => $device_type,
            // 'Other' is the parser saying it has no rule, not a brand.
            'device_brand'    => strtolower( (string) $brand ) === 'other' ? null : $brand,
            'device_model'    => strtolower( (string) $model ) === 'other' ? null : $model,
        );
    }

    /**
     * The tagged_* columns -- evidence, on the landing event only.
     *
     * The landing URL rides the landing beacon and no other, so the tags reach
     * exactly one raw row per session and every later row holds NULL. The pass
     * reads that first event anyway, for the landing page, so carrying the
     * reading across the session adds no read.
     *
     * Transcription, never classification: what the URL CLAIMED. The answer
     * over it -- tag if there was one, else the referrer classified -- is the
     * pass's, on the cube row, where correcting the classifier is a
     * reprocess rather than an edit.
     *
     * @param object $event
     * @param string $name
     * @return array
     */
    protected function taggedColumns( $event, $name ) {

        $absent = array(
            'tagged_source'       => null,
            'tagged_medium'       => null,
            'tagged_campaign'     => null,
            'tagged_ad'           => null,
            'tagged_search_terms' => null,
        );

        /*
         * Which event is the landing one. session_start is materialised from
         * the session's first page_view and first_visit from the visitor's, so
         * all three of these are the same beacon -- the landing beacon -- and
         * each keeps its own copy, since a build reads whichever of them it
         * finds first.
         */
        $is_landing = $event->get( 'is_new_session_start' )
                   || $event->get( 'is_new_session' );

        if ( ! $is_landing ) {

            return $absent;
        }

        /*
         * TrackingEventHelpers::taggedValue() is the parse, and it already has
         * the precedence right: a tagged_* the beacon actually SENT wins, and
         * the parse of landing_url is the fallback. Trackers are cached in
         * browsers, so beacons from before the parse moved to the server keep
         * arriving; a value that was transmitted beats one re-derived from
         * evidence.
         *
         * tagged_terms is the one key whose two halves do not share a stem --
         * owa_search_terms on the URL, tagged_terms on the wire -- and v2 names
         * the column tagged_search_terms. Mapped here rather than renaming
         * either side, since both are already in the wild.
         *
         * tagged_ad_type is parsed by that helper and deliberately NOT stored:
         * v2's raw table has `ad` and no ad_type, and a column that exists only
         * to receive a value v1 happened to collect is how the star schema got
         * its width.
         */
        return array(
            'tagged_source'       => $this->tagged( $event, 'tagged_source' ),
            'tagged_medium'       => $this->tagged( $event, 'tagged_medium' ),
            'tagged_campaign'     => $this->tagged( $event, 'tagged_campaign' ),
            'tagged_ad'           => $this->tagged( $event, 'tagged_ad' ),
            'tagged_search_terms' => $this->tagged( $event, 'tagged_terms' ),
        );
    }

    /**
     * One tagged value off the landing URL, or null.
     *
     * @param object $event
     * @param string $name  a wire property name, e.g. tagged_source
     * @return string|null
     */
    protected function tagged( $event, $name ) {

        return $this->text(
            \OWA\Module\Base\Classes\TrackingEventHelpers::taggedValue( $event, $name ) );
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
        foreach ( $this->declaredParams( $event ) as $key ) {

            $value = $event->get( $key );

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
     * The param names this event type may carry.
     *
     * @param object $event
     * @return string[]
     */
    protected function declaredParams( $event ) {

        $by_type = array(
            'click'               => array( 'link_url', 'link_domain', 'link_text', 'outbound' ),
            'file_download'       => array( 'link_url', 'file_name', 'file_extension' ),
            'view_search_results' => array( 'search_term' ),
            'form_start'          => array( 'form_id', 'form_name' ),
            'form_submit'         => array( 'form_id', 'form_name' ),
            'purchase'            => array( 'transaction_id', 'tax', 'shipping', 'gateway', 'items' ),
            // The old four-slot action shape. Carried as params rather than
            // columns because it is v1's vocabulary, not v2's: a custom event
            // in v2 is a NAME plus params, and these are what an action's four
            // slots become when it is migrated.
            'custom_event'        => array( 'action_group', 'action_name', 'action_label', 'numeric_value' ),
        );

        $name = \OWA\Module\Base\Classes\V2Event::name( $event->getEventType() );

        return isset( $by_type[ $name ] ) ? $by_type[ $name ] : array();
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

        $db->endTransaction();

        return OWA_EHS_EVENT_HANDLED;
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

        $is_first_session = $event->get( 'is_new_visitor' )
                         || (string) $event->get( 'num_prior_sessions' ) === '0';

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

        if ( $entity->wasPersisted() ) {

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
