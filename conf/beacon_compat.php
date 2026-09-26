<?php

/**
 * Every bridge between an OLD beacon and the current one, in one place.
 *
 * WHY THIS EXISTS. A browser caches the tracker, so beacons written by an
 * older one keep arriving for as long as that cache lives -- and OWA, unlike
 * GA, does not control delivery. GA serves gtag.js with a deliberately short
 * TTL and accepts a PageSpeed penalty for it; ours is a static file on the
 * customer's own origin with no Cache-Control at all, so browsers apply a
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
     * these four are renames and nothing more. A name already in the v2
     * vocabulary passes through untouched -- which is now every name the
     * current tracker sends, not just the newer ones. These fire only for a
     * beacon from the v1 line.
     */
    'event_names' => array(
        'base.page_request'     => 'page_view',
        'dom.click'             => 'click',
        'ecommerce.transaction' => 'purchase',
        'track.action'          => 'custom_event',
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
     * beacon_version deliberately has no short name: the tracker sends the
     * canonical one. Ten bytes a beacon against a split that has to be
     * remembered forever is not a trade worth making, and a new field was the
     * one chance not to make it.
     */
    'renames' => array(

        array( 'role' => 'wire',   'from' => 'nps',  'to' => 'num_prior_sessions' ),

        /*
         * page_url is v1's name for the page's URL, and v1's server CANONICALISED
         * it in place -- stripping the campaign parameters and the site's
         * query_string_filters -- which is why a second, untouched copy had to
         * ride alongside it as page_location. v2 stores the evidence exactly as it
         * arrived and derives page_path and page_query from it, so there is
         * nothing left for the duplicate to protect against.
         *
         * WIRE, not legacy: the current tracker still sends both. It becomes
         * deletable when the tracker sends only page_location.
         *
         * apply() leaves page_location alone whenever the beacon carries it, so a
         * tracker sending both is unaffected and one sending only the old name
         * gets its URL stored instead of dropped -- which is what the row builder
         * has claimed happens since the property registry stopped declaring
         * page_url.
         */
        array( 'role' => 'wire',   'from' => 'page_url', 'to' => 'page_location' ),

        /*
         * The two identity fields that became CUSTOM USER PROPERTIES.
         *
         * user_name and user_email were declared properties of the release
         * vocabulary; they are not any more (PLAN.html §2.26.1 -- two scopes, and
         * a value describing the person is the user one). So a beacon carrying
         * either under its bare name names nothing, and admitRequestParams()
         * would drop it: the bare spellings are not registered and carry no `up_`
         * prefix to be admitted by.
         *
         * Renamed onto the prefix instead, which puts them exactly where a site
         * calling setUserProperty() puts them today. `user_name` is LEGACY rather
         * than wire: the current tracker's setUserName() routes through
         * setUserProperty(), so it sends up_user_name and never reaches this.
         * `email_address` was already legacy -- its rename used to point at the
         * declared user_email property and now points at the prefix.
         */
        array( 'role' => 'legacy', 'from' => 'user_name',     'to' => 'up_user_name' ),
        array( 'role' => 'legacy', 'from' => 'email_address', 'to' => 'up_user_email' ),

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
