<?php

/**
 * Every bridge between an OLD beacon and the current one, in one place.
 *
 * WHY THIS EXISTS. A browser caches the tracker, so beacons written by an
 * older one keep arriving for as long as that cache lives -- and OWA does not
 * control delivery. The tracker is a static file on the customer's own origin
 * with no Cache-Control at all, so browsers apply a
 * heuristic (commonly 10% of the file's age) and the window is days to weeks
 * and GROWS the longer the tracker goes unchanged.
 *
 * So bridges accumulate.
 *
 * WHAT THIS FILE IS. The INDEX. Every bridge is listed, whether or not this
 * file is what applies it -- and BeaconCompatInventoryTest asserts the listing
 * matches the code, in both directions, so neither can drift from the other.
 *
 * WHAT IT IS NOT. Not a runtime contract. Nothing looks a beacon up in here to
 * decide whether to accept it -- that is `row()`'s identity guard, which is
 * about one beacon and knows nothing of versions. This file is read by the
 * event-name resolver and by a test, and that is all.
 *
 * DELETING A GENERATION. When a tracker is old enough that nothing can still
 * be sending it, its entries come out of here and out of whatever applies
 * them, and the test stops demanding coverage. That decision wants evidence
 * rather than a guess about cache lifetimes: every beacon
 * carries a format version, owa_event_raw.beacon_version stores it, and each
 * version's emitted set is recorded standalone in
 * tests/fixtures/beacon_contracts.json. "Has generation N died out" is a query.
 *
 * THIS FILE IS THE v1 -> v2 MAPPING: nothing a v2 tracker sends reaches the
 * event-name renames below.
 */

return [

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * Event type names. 1.x namespaced its event types and v2 does not, so
     * these three are renames and nothing more. Applied by Beacon\Compat as
     * the first step of CoreAPI::logEvent() and of a queue drain, so the
     * admission gate, the dispatch name and the processor router only ever see
     * a current name. A name not listed passes through untouched -- every name
     * the current tracker sends. track.action is reshaped (event_reshapes,
     * below). Any other v1 spelling (dom.stream, base.feed_request) is not a
     * legal event name and is refused.
     */
    'event_names' => [
        'base.page_request'     => 'page_view',
        'dom.click'             => 'click',
        'ecommerce.transaction' => 'purchase',
    ],

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * Event types that become another event AND other properties, by a
     * function. Applied by Beacon\Compat at the same point as event_names.
     *
     *   reads  the properties the function is given, which the beacon
     *          endpoint admits for that reason (Compat::bridgedNames())
     *   by     the function: reads => value in, [ event_type, properties ] out,
     *          or null to leave the event as it is
     *
     * track.action: 1.x's one event type for every action, sent by a cached
     * 1.x tag, the PHP SDK's trackAction() and the tracker's deprecated
     * trackAction(). It becomes the custom event the upgrade makes from the
     * same 1.x action (ActionMigrator::customEvent()), so live and migrated
     * actions are one event. A name that leaves nothing stays track.action and
     * is refused.
     */
    'event_reshapes' => [
        'track.action' => [
            'reads' => \OWA\Module\Base\Classes\Migration\ActionMigrator::V1_PROPERTIES,
            'by'    => [ \OWA\Module\Base\Classes\Migration\ActionMigrator::class, 'customEvent' ],
        ],
    ],

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * Token renames. Classes\Beacon\Compat puts the current spelling on the
     * event for anything sent under an old one, ONCE, before any reader.
     *
     * Applied on PRESENCE of the old key, not on a falsy canonical value, so
     * "absent" and "present and false" stay distinct and a BOOLEAN can be
     * renamed too.
     *
     * `role` says when an entry may be DELETED, not how it is applied:
     *
     *   wire   the short name the CURRENT tracker sends. Deleting it breaks
     *          today's tracker, so it is permanent until the wire changes.
     *   legacy what an OLDER tracker sent. Deletable once beacon_version shows
     *          nothing is still sending it.
     *
     * The `wire` entries are generated from the tracker's own table,
     * modules/Base/src/tracker/WireNames.js, and BeaconWireNamesTest holds the
     * two to each other in both directions. The convention behind the codes is
     * documented there.
     */
    'renames' => [

        /*
         * THE CURRENT TRACKER'S SHORT KEYS, one per property it sends. A beacon
         * that has to fit one packet cannot spend 40 bytes on
         * is_new_visitor_created, and the server keeps the long names because
         * the registry, the columns and every reader already use them.
         */
        [ 'role' => 'wire',   'from' => '_v',      'to' => 'beacon_version' ],
        [ 'role' => 'wire',   'from' => 'e_t',     'to' => 'event_type' ],
        [ 'role' => 'wire',   'from' => 'e_sq',    'to' => 'event_seq' ],
        [ 'role' => 'wire',   'from' => 'e_ems',   'to' => 'engagement_msec' ],
        [ 'role' => 'wire',   'from' => 'site',    'to' => 'site_id' ],
        [ 'role' => 'wire',   'from' => 'v_id',    'to' => 'visitor_id' ],
        [ 'role' => 'wire',   'from' => 'v_fts',   'to' => 'fsts' ],
        [ 'role' => 'wire',   'from' => 'v_nps',   'to' => 'num_prior_sessions' ],
        [ 'role' => 'wire',   'from' => 'v_new',   'to' => 'is_new_visitor_created' ],
        [ 'role' => 'wire',   'from' => 'v_cs',    'to' => 'consent_state' ],
        [ 'role' => 'wire',   'from' => 'u_id',    'to' => 'user_id' ],
        [ 'role' => 'wire',   'from' => 's_id',    'to' => 'session_id' ],
        [ 'role' => 'wire',   'from' => 's_sts',   'to' => 'sts' ],
        [ 'role' => 'wire',   'from' => 's_new',   'to' => 'is_new_session_start' ],
        [ 'role' => 'wire',   'from' => 'p_l',     'to' => 'page_location' ],
        [ 'role' => 'wire',   'from' => 'p_t',     'to' => 'page_title' ],
        [ 'role' => 'wire',   'from' => 'p_r',     'to' => 'HTTP_REFERER' ],
        [ 'role' => 'wire',   'from' => 'p_cg',    'to' => 'content_group' ],
        [ 'role' => 'wire',   'from' => 'p_w',     'to' => 'page_width' ],
        [ 'role' => 'wire',   'from' => 'p_h',     'to' => 'page_height' ],
        [ 'role' => 'wire',   'from' => 'p_q',     'to' => 'search_term' ],
        [ 'role' => 'wire',   'from' => 'd_sr',    'to' => 'screen_resolution' ],
        [ 'role' => 'wire',   'from' => 'el_tg',   'to' => 'dom_element_tag' ],
        [ 'role' => 'wire',   'from' => 'el_id',   'to' => 'dom_element_id' ],
        [ 'role' => 'wire',   'from' => 'el_cl',   'to' => 'dom_element_class' ],
        [ 'role' => 'wire',   'from' => 'el_nm',   'to' => 'dom_element_name' ],
        [ 'role' => 'wire',   'from' => 'el_tx',   'to' => 'dom_element_text' ],
        [ 'role' => 'wire',   'from' => 'el_lu',   'to' => 'target_url' ],
        [ 'role' => 'wire',   'from' => 'el_lo',   'to' => 'is_outbound' ],
        [ 'role' => 'wire',   'from' => 'el_a',    'to' => 'element_anchor' ],
        [ 'role' => 'wire',   'from' => 'el_ax',   'to' => 'anchor_x' ],
        [ 'role' => 'wire',   'from' => 'el_ay',   'to' => 'anchor_y' ],
        [ 'role' => 'wire',   'from' => 'c_x',     'to' => 'click_x' ],
        [ 'role' => 'wire',   'from' => 'c_y',     'to' => 'click_y' ],
        [ 'role' => 'wire',   'from' => 'sc_d',    'to' => 'scroll_depth' ],
        [ 'role' => 'wire',   'from' => 'f_nm',    'to' => 'file_name' ],
        [ 'role' => 'wire',   'from' => 'f_ext',   'to' => 'file_extension' ],
        [ 'role' => 'wire',   'from' => 'fm_id',   'to' => 'form_id' ],
        [ 'role' => 'wire',   'from' => 'fm_nm',   'to' => 'form_name' ],
        [ 'role' => 'wire',   'from' => 'fm_len',  'to' => 'form_length' ],
        [ 'role' => 'wire',   'from' => 'fm_dst',  'to' => 'form_destination' ],
        [ 'role' => 'wire',   'from' => 'fm_stx',  'to' => 'form_submit_text' ],
        [ 'role' => 'wire',   'from' => 'fm_ffid', 'to' => 'first_field_id' ],
        [ 'role' => 'wire',   'from' => 'fm_ffnm', 'to' => 'first_field_name' ],
        [ 'role' => 'wire',   'from' => 'fm_fft',  'to' => 'first_field_type' ],
        [ 'role' => 'wire',   'from' => 'fm_ffp',  'to' => 'first_field_position' ],
        [ 'role' => 'wire',   'from' => 'o_id',    'to' => 'ct_order_id' ],
        [ 'role' => 'wire',   'from' => 'o_tot',   'to' => 'ct_total' ],
        [ 'role' => 'wire',   'from' => 'o_tax',   'to' => 'ct_tax' ],
        [ 'role' => 'wire',   'from' => 'o_shp',   'to' => 'ct_shipping' ],
        [ 'role' => 'wire',   'from' => 'o_val',   'to' => 'ct_value' ],
        [ 'role' => 'wire',   'from' => 'o_cur',   'to' => 'currency' ],
        [ 'role' => 'wire',   'from' => 'o_cpn',   'to' => 'coupon' ],
        [ 'role' => 'wire',   'from' => 'o_gw',    'to' => 'ct_gateway' ],
        [ 'role' => 'wire',   'from' => 'o_src',   'to' => 'ct_order_source' ],
        [ 'role' => 'wire',   'from' => 'o_items', 'to' => 'ct_line_items' ],

        /*
         * `nps` is what 1.x and early v2 trackers send for num_prior_sessions. Every other property they sent went on the wire
         * under its own name, which the gate admits as it is.
         */
        [ 'role' => 'legacy', 'from' => 'nps',  'to' => 'num_prior_sessions' ],

        /*
         * page_url is v1's name for the page's URL, and v1's server CANONICALISED
         * it in place -- stripping the campaign parameters and the site's
         * query_string_filters -- which is why a second, untouched copy had to
         * ride alongside it as page_location. v2 stores the evidence as it
         * arrived, less only the query_string_filters parameters, and derives
         * page_path and page_query from it before those are removed.
         *
         * LEGACY: the tracker sends page_location alone. Deletable once
         * beacon_version shows nothing still sends page_url.
         *
         * apply() leaves page_location alone whenever the beacon carries it, so a
         * tracker sending both is unaffected and one sending only the old name
         * gets its URL stored instead of dropped.
         */
        [ 'role' => 'legacy', 'from' => 'page_url', 'to' => 'page_location' ],

        /*
         * The two identity fields that became CUSTOM USER PROPERTIES.
         *
         * user_name and user_email are not declared properties (there are two
         * scopes, and a value describing the person is the user one). So a beacon carrying
         * either under its bare name names nothing, and admitRequestParams()
         * would drop it: the bare spellings are not registered and carry no `vps_`
         * prefix to be admitted by.
         *
         * Renamed onto the prefix instead, which puts them exactly where a site
         * calling setUserProperty() puts them today. `user_name` is LEGACY rather
         * than wire: the current tracker's setUserName() writes the PAGE store
         * under the vps_ prefix, so the beacon already carries vps_user_name and
         * never reaches this. `email_address` is legacy too.
         */
        [ 'role' => 'legacy', 'from' => 'user_name',     'to' => 'vps_user_name' ],
        [ 'role' => 'legacy', 'from' => 'email_address', 'to' => 'vps_user_email' ],

    ],

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * The second decode. A beacon whose format version (`_v`, beacon_version)
     * is below this one has every value URL-decoded again after PHP has
     * decoded the request (RequestContainer::decodeRequestParams()), as 1.x
     * did: a cached 1.x tag, and the PHP SDK, send no version. A beacon at
     * this version or later is decoded once, by PHP, so each value is what the
     * page had.
     *
     * Applied by Beacon\Compat::decodeOlderBeacon(), which log.php calls in
     * place of the decode. For an older beacon it also keeps the page URL and
     * referrer as they were before the second decode
     * (TrackingEventHelpers::arrivedUrls()), which the readings that decode
     * cuts are taken from (arrivedUrlIfCut()). A current beacon needs neither.
     */
    'decoded_once_from_version' => 2,

    /*
     * ---- APPLIED ELSEWHERE, INDEXED HERE ----------------------------------
     */
    'indexed' => [

        /*
         * Value ENCODINGS, where a value counting from zero was sent as a
         * string by one generation and a number by the next. Every one of these
         * is a test that has to accept both spellings; each is a place where
         * `! $value` alone would read a legitimate zero as an absence.
         */
        [ 'kind' => 'coercion', 'from' => '"0"', 'to' => '0',
               'in' => 'modules/Base/Classes/TrackingEventHelpers.php', 'needle' => '$value !== 0 && $value !== "0"' ],
        [ 'kind' => 'coercion', 'from' => '"0"', 'to' => '0',
               'in' => 'modules/Base/Handler/EventRawHandlers.php', 'needle' => "num_prior_sessions' ) === '0'" ],

    ],
];
