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
 * So bridges accumulate. They already had: six `alternative_key` renames, four
 * event-name maps, two callbacks returning an older tracker's value unchanged,
 * two open-coded flag fallbacks, three value-encoding coercions, a URL
 * fallback chain, and one mechanism that was dead and unnoticed. Seven
 * mechanisms, no two alike, and nothing able to answer "is that all of them".
 *
 * WHAT THIS FILE IS. The INDEX. Every bridge is listed, whether or not this
 * file is what applies it -- and BeaconCompatInventoryTest asserts the listing
 * matches the code, in both directions, so neither can drift from the other.
 * Entries migrate to being applied from here over time; being listed is what
 * makes that possible, and it is the part that had to come first.
 *
 * WHAT IT IS NOT. Not a runtime contract. Nothing looks a beacon up in here to
 * decide whether to accept it -- that is `row()`'s identity guard, which is
 * about one beacon and knows nothing of versions. This file is read by the
 * event-name resolver and by a test, and that is all.
 *
 * DELETING A GENERATION. When a tracker is old enough that nothing can still
 * be sending it, its entries come out of here and out of whatever applies
 * them, and the test stops demanding coverage. That decision wants evidence
 * rather than a guess about cache lifetimes, and now has it: every beacon
 * carries a format version, owa_event_raw.beacon_version stores it, and each
 * version's emitted set is recorded standalone in
 * tests/fixtures/beacon_contracts.json. "Has generation N died out" is a query.
 *
 * THIS FILE IS THE v1 -> v2 MAPPING, and only became so when the v2 tracker
 * stopped emitting v1 event names. While it still sent base.page_request, the
 * rename below fired on every beacon the CURRENT tracker produced -- so it was
 * a translation the live path depended on rather than a bridge from a dead
 * generation, and it could never have been deleted. Now nothing a v2 tracker
 * sends reaches these four lines.
 */

return array(

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * Event type names. 1.x namespaced its event types and v2 does not, so
     * these three are renames and nothing more. Applied by Beacon\Compat as
     * the first step of CoreAPI::logEvent() and of a queue drain, so the
     * admission gate, the dispatch name and the processor router only ever see
     * a current name. A name not listed passes through untouched -- every name
     * the current tracker sends. Any other v1 spelling (dom.stream,
     * base.feed_request, track.action) is not a legal event name and is refused.
     */
    'event_names' => array(
        'base.page_request'     => 'page_view',
        'dom.click'             => 'click',
        'ecommerce.transaction' => 'purchase',
        /*
         * track.action -> custom_event is REMOVED with the event type itself.
         *
         * v1 had one event type for everything a site tracked, told apart by an
         * action_name field. v2 retires that shape: an event name is a name, and
         * the group, label and value are parameters describing it -- which is
         * the reason eventName is a dimension. So there is no custom_event to
         * rename TO.
         *
         * A tracker cached from before this sends track.action, which is not a
         * legal custom event name (the dot fails the pattern) and is not
         * first-class, so it is refused rather than stored under a name that means
         * nothing. Decided: no compat for it.
         */
    ),

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * Token renames. Classes\Beacon\Compat puts the current spelling on the
     * event for anything sent under an old one, ONCE, before any reader.
     *
     * This replaces `alternative_key` in the property registry, and the change
     * is not only where it lives. That mechanism fired when the canonical key
     * was FALSY, so it could not tell "absent" from "present and false" -- fine
     * for counters and strings with the zero cases carved out by hand, and the
     * reason a BOOLEAN could never use it. Which is why the flag fallbacks had
     * to be open-coded in three places instead. Presence has no such hole.
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
    'renames' => array(

        /*
         * THE CURRENT TRACKER'S SHORT KEYS, one per property it sends. A beacon
         * that has to fit one packet cannot spend 40 bytes on
         * is_new_visitor_created, and the server keeps the long names because
         * the registry, the columns and every reader already use them.
         */
        array( 'role' => 'wire',   'from' => '_v',      'to' => 'beacon_version' ),
        array( 'role' => 'wire',   'from' => 'e_t',     'to' => 'event_type' ),
        array( 'role' => 'wire',   'from' => 'e_sq',    'to' => 'event_seq' ),
        array( 'role' => 'wire',   'from' => 'e_ems',   'to' => 'engagement_msec' ),
        array( 'role' => 'wire',   'from' => 'site',    'to' => 'site_id' ),
        array( 'role' => 'wire',   'from' => 'v_id',    'to' => 'visitor_id' ),
        array( 'role' => 'wire',   'from' => 'v_fts',   'to' => 'fsts' ),
        array( 'role' => 'wire',   'from' => 'v_nps',   'to' => 'num_prior_sessions' ),
        array( 'role' => 'wire',   'from' => 'v_new',   'to' => 'is_new_visitor_created' ),
        array( 'role' => 'wire',   'from' => 'v_cs',    'to' => 'consent_state' ),
        array( 'role' => 'wire',   'from' => 'u_id',    'to' => 'user_id' ),
        array( 'role' => 'wire',   'from' => 's_id',    'to' => 'session_id' ),
        array( 'role' => 'wire',   'from' => 's_sts',   'to' => 'sts' ),
        array( 'role' => 'wire',   'from' => 's_pts',   'to' => 'psts' ),
        array( 'role' => 'wire',   'from' => 's_new',   'to' => 'is_new_session_start' ),
        array( 'role' => 'wire',   'from' => 'p_l',     'to' => 'page_location' ),
        array( 'role' => 'wire',   'from' => 'p_t',     'to' => 'page_title' ),
        array( 'role' => 'wire',   'from' => 'p_r',     'to' => 'HTTP_REFERER' ),
        array( 'role' => 'wire',   'from' => 'p_cg',    'to' => 'content_group' ),
        array( 'role' => 'wire',   'from' => 'p_w',     'to' => 'page_width' ),
        array( 'role' => 'wire',   'from' => 'p_h',     'to' => 'page_height' ),
        array( 'role' => 'wire',   'from' => 'p_q',     'to' => 'search_term' ),
        array( 'role' => 'wire',   'from' => 'd_sr',    'to' => 'screen_resolution' ),
        array( 'role' => 'wire',   'from' => 'el_tg',   'to' => 'dom_element_tag' ),
        array( 'role' => 'wire',   'from' => 'el_id',   'to' => 'dom_element_id' ),
        array( 'role' => 'wire',   'from' => 'el_cl',   'to' => 'dom_element_class' ),
        array( 'role' => 'wire',   'from' => 'el_nm',   'to' => 'dom_element_name' ),
        array( 'role' => 'wire',   'from' => 'el_tx',   'to' => 'dom_element_text' ),
        array( 'role' => 'wire',   'from' => 'el_lu',   'to' => 'target_url' ),
        array( 'role' => 'wire',   'from' => 'el_lo',   'to' => 'is_outbound' ),
        array( 'role' => 'wire',   'from' => 'c_x',     'to' => 'click_x' ),
        array( 'role' => 'wire',   'from' => 'c_y',     'to' => 'click_y' ),
        array( 'role' => 'wire',   'from' => 'sc_d',    'to' => 'scroll_depth' ),
        array( 'role' => 'wire',   'from' => 'f_nm',    'to' => 'file_name' ),
        array( 'role' => 'wire',   'from' => 'f_ext',   'to' => 'file_extension' ),
        array( 'role' => 'wire',   'from' => 'fm_id',   'to' => 'form_id' ),
        array( 'role' => 'wire',   'from' => 'fm_nm',   'to' => 'form_name' ),
        array( 'role' => 'wire',   'from' => 'fm_len',  'to' => 'form_length' ),
        array( 'role' => 'wire',   'from' => 'fm_dst',  'to' => 'form_destination' ),
        array( 'role' => 'wire',   'from' => 'fm_stx',  'to' => 'form_submit_text' ),
        array( 'role' => 'wire',   'from' => 'fm_ffid', 'to' => 'first_field_id' ),
        array( 'role' => 'wire',   'from' => 'fm_ffnm', 'to' => 'first_field_name' ),
        array( 'role' => 'wire',   'from' => 'fm_fft',  'to' => 'first_field_type' ),
        array( 'role' => 'wire',   'from' => 'fm_ffp',  'to' => 'first_field_position' ),
        array( 'role' => 'wire',   'from' => 'o_id',    'to' => 'ct_order_id' ),
        array( 'role' => 'wire',   'from' => 'o_tot',   'to' => 'ct_total' ),
        array( 'role' => 'wire',   'from' => 'o_tax',   'to' => 'ct_tax' ),
        array( 'role' => 'wire',   'from' => 'o_shp',   'to' => 'ct_shipping' ),
        array( 'role' => 'wire',   'from' => 'o_val',   'to' => 'ct_value' ),
        array( 'role' => 'wire',   'from' => 'o_cur',   'to' => 'currency' ),
        array( 'role' => 'wire',   'from' => 'o_cpn',   'to' => 'coupon' ),
        array( 'role' => 'wire',   'from' => 'o_gw',    'to' => 'ct_gateway' ),
        array( 'role' => 'wire',   'from' => 'o_src',   'to' => 'ct_order_source' ),
        array( 'role' => 'wire',   'from' => 'o_items', 'to' => 'ct_line_items' ),

        /*
         * `nps` is what 1.x and the v2 tracker before the scoped keys sent for
         * num_prior_sessions. Every other property they sent went on the wire
         * under its own name, which the gate admits as it is.
         */
        array( 'role' => 'legacy', 'from' => 'nps',  'to' => 'num_prior_sessions' ),

        /*
         * page_url is v1's name for the page's URL, and v1's server CANONICALISED
         * it in place -- stripping the campaign parameters and the site's
         * query_string_filters -- which is why a second, untouched copy had to
         * ride alongside it as page_location. v2 stores the evidence exactly as it
         * arrived and derives page_path and page_query from it, so there is
         * nothing left for the duplicate to protect against.
         *
         * LEGACY since the tracker stopped sending it (it sends page_location
         * alone). Deletable once beacon_version shows nothing still does.
         *
         * apply() leaves page_location alone whenever the beacon carries it, so a
         * tracker sending both is unaffected and one sending only the old name
         * gets its URL stored instead of dropped -- which is what the row builder
         * has claimed happens since the property registry stopped declaring
         * page_url.
         */
        array( 'role' => 'legacy', 'from' => 'page_url', 'to' => 'page_location' ),

        /*
         * The two identity fields that became CUSTOM USER PROPERTIES.
         *
         * user_name and user_email were declared properties of the release
         * vocabulary; they are not any more (PLAN.html §2.26.1 -- two scopes, and
         * a value describing the person is the user one). So a beacon carrying
         * either under its bare name names nothing, and admitRequestParams()
         * would drop it: the bare spellings are not registered and carry no `vps_`
         * prefix to be admitted by.
         *
         * Renamed onto the prefix instead, which puts them exactly where a site
         * calling setUserProperty() puts them today. `user_name` is LEGACY rather
         * than wire: the current tracker's setUserName() writes the PAGE store
         * under the vps_ prefix, so the beacon already carries vps_user_name and
         * never reaches this. `email_address` was already legacy -- its rename used
         * to point at the declared user_email property and now points at the
         * prefix.
         */
        array( 'role' => 'legacy', 'from' => 'user_name',     'to' => 'vps_user_name' ),
        array( 'role' => 'legacy', 'from' => 'email_address', 'to' => 'vps_user_email' ),

        /*
         * dsfs -> days_since_first_session and dsps -> days_since_prior_session
         * were here, and are REMOVED because their DESTINATION is gone, not
         * because the generation that sent them has died out.
         *
         * Both properties left the registry: the server derived each day count
         * from an anchor it already stores and then had nowhere to put it, so the
         * offsets go and visitor_fsts / prior_session_start_ts / session_start_ts
         * stay. A rename whose target no property declares cannot do anything
         * except put a value on the event for nobody to read.
         *
         * An old tracker still sending dsfs or dsps now has those keys dropped at
         * the endpoint like any other unregistered name. Nothing is lost that was
         * being kept: the counts were discarded on arrival either way.
         */

        /*
         * `sid` -> feed_subscription_id was here, and it is REMOVED rather
         * than kept.
         *
         * It bridged a feed subscription id, and feeds are retired -- nothing
         * has written a feed request since 2021, and V2Event::NOT_EVENTS
         * refuses the type outright. So the bridge could only ever fire for a
         * value no live path produces.
         *
         * It was also a collision waiting to happen: `sid` is the tracker's
         * store key for the SESSION id. The two do not meet today because the
         * session goes on the wire as session_id, but any beacon carrying a
         * literal `sid` would have had its session id resolved into a feed
         * column. A bridge that can only misfire is worse than no bridge.
         *
         * feed_subscription_id has since left the property registry too, with the
         * other seven spellings v2 does not use: the registry holds what v2 CALLS
         * things, and a retired feature's field is not one of them. The v1 feed
         * handler still exists and is still unregistered on v2 -- removing it is
         * its own decision, not a side effect of tidying the compat layer.
         */
    ),

    /*
     * ---- APPLIED ELSEWHERE, INDEXED HERE ----------------------------------
     */
    'indexed' => array(

        /*
         * THE FLAG FALLBACK IS GONE, and it is worth saying why rather than
         * just deleting the entries.
         *
         * is_new_session (page-scoped) stood in for is_new_session_start
         * (request-scoped) on trackers cached from before the two were split.
         * It could not be sequestered here, because page-scoped to
         * request-scoped is lossy: every event of the landing page carries the
         * page-scoped flag, so a rename would raise one session_start marker
         * per event of it.
         *
         * The pair no longer exists. v1's session listener was the reason for
         * the split -- it decided create-vs-update on the flag -- and v2
         * materialises a session_start EVENT from the request-scoped one alone.
         * So the tracker sends one flag, the twin is removed, and the bridge
         * has nothing left to bridge. A tracker cached from before this sends
         * neither and gets no marker, which was already the outcome.
         *
         */

        /*
         * Value ENCODINGS, where a value counting from zero was sent as a
         * string by one generation and a number by the next. Every one of these
         * is a test that has to accept both spellings; each is a place where
         * `! $value` alone would read a legitimate zero as an absence.
         */
        array( 'kind' => 'coercion', 'from' => '"0"', 'to' => '0',
               'in' => 'modules/Base/Classes/TrackingEventHelpers.php', 'needle' => '$value !== 0 && $value !== "0"' ),
        array( 'kind' => 'coercion', 'from' => '"0"', 'to' => '0',
               'in' => 'modules/Base/Handler/EventRawHandlers.php', 'needle' => "num_prior_sessions' ) === '0'" ),

        /*
         * The two callback_passthrough entries for dsps and dsfs were here.
         *
         * Each named a derivation that returned whatever an older tracker had
         * sent under the short key when it could not compute the count itself.
         * Both derivations are deleted with their properties, so there is no
         * callback left to index -- and the inventory test reads this list
         * against the code in BOTH directions, which is what makes leaving a
         * stale entry here a failure rather than a comment.
         */
    ),
);

?>
