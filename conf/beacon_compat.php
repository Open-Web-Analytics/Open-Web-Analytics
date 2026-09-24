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
 * rather than a guess about cache lifetimes -- which is what storing a beacon
 * format version on the row would give, and does not exist yet.
 */

return array(

    /*
     * ---- APPLIED FROM HERE ------------------------------------------------
     *
     * Event type names. 1.x namespaced its event types and v2 does not, so
     * these four are renames and nothing more. A name already in the v2
     * vocabulary passes through untouched, which is what lets the tracker send
     * `scroll` or `file_download` with no line here.
     */
    'event_names' => array(
        'base.page_request'     => 'page_view',
        'dom.click'             => 'click',
        'ecommerce.transaction' => 'purchase',
        'track.action'          => 'custom_event',
    ),

    /*
     * ---- APPLIED ELSEWHERE, INDEXED HERE ----------------------------------
     *
     * Each entry says where the bridge actually lives. The test asserts it is
     * still there, so removing a bridge without removing its entry fails, and
     * so does adding one without indexing it.
     *
     * `kind` is descriptive here, not dispatched on: nothing reads these at
     * runtime.
     *
     * A `needle` NAMING A FUNCTION CARRIES ITS OPENING PAREN. Without it the
     * match is a substring: renaming keepCompleteUrl to keepCompleteUrlRENAMED
     * still contains "function keepCompleteUrl", so the guard passed while the
     * bridge was gone. Measured.
     */
    'indexed' => array(

        /*
         * Declarative renames, in the property registry. Applied by
         * TrackingEventHelpers when the canonical key has no value.
         *
         * TRUTHINESS, NOT PRESENCE -- `if ( ! $value && $value !== 0 && $value
         * !== "0" )`. Fine for these six, which are counters and strings with
         * the zero cases carved out, and the reason a BOOLEAN cannot use this
         * mechanism: a flag legitimately sent as false would be indistinguishable
         * from one that was never sent, and the fallback would fire on it. That
         * is why the flag fallbacks below are open-coded instead.
         */
        /*
         * `role` SEPARATES TWO THINGS alternative_key was doing at once, which
         * is only visible once they are listed together:
         *
         *   wire   the short name the CURRENT tracker sends. Not a bridge at
         *          all -- the registry's canonical name is simply longer than
         *          the one on the wire. Deleting it breaks today's tracker.
         *   legacy the name an OLDER tracker sent. A real bridge, and a
         *          candidate for deletion once beacon_version says nothing is
         *          sending it.
         *
         * Indexing them as one kind made every entry look permanent. Most of
         * them are not.
         *
         * beacon_version deliberately has NO short name: the tracker sends the
         * canonical one. Ten bytes a beacon against a wire/canonical split that
         * has to be remembered forever is not a trade worth making, and the new
         * field was the one chance to not make it.
         */
        array( 'kind' => 'rename', 'role' => 'wire',   'from' => 'nps',  'to' => 'num_prior_sessions', 'in' => 'registry' ),

        array( 'kind' => 'rename', 'role' => 'legacy', 'from' => 'dsfs',          'to' => 'days_since_first_session', 'in' => 'registry' ),
        array( 'kind' => 'rename', 'role' => 'legacy', 'from' => 'dsps',          'to' => 'days_since_prior_session', 'in' => 'registry' ),
        array( 'kind' => 'rename', 'role' => 'legacy', 'from' => 'email_address', 'to' => 'user_email',               'in' => 'registry' ),

        /*
         * A COLLISION, indexed so it is not rediscovered. `sid` is also the
         * tracker's store key for the SESSION id -- sent on the wire as
         * session_id, so the two do not meet today. A beacon carrying a literal
         * `sid` would resolve it into feed_subscription_id, which is a value
         * from a retired feature: nothing has written a feed request since
         * 2021. Left alone rather than removed, because removing it is a
         * behaviour change on a path nobody can currently observe.
         */
        array( 'kind' => 'rename', 'role' => 'legacy', 'from' => 'sid', 'to' => 'feed_subscription_id', 'in' => 'registry' ),

        /*
         * The page-scoped flag standing in for the request-scoped one, on
         * trackers cached from before the two were split.
         *
         * Open-coded because it cannot be declared (see above), and applied in
         * only TWO of the three readers ON PURPOSE. `is_new_session` rides
         * every event of the landing PAGE, so using it to raise a marker would
         * write one session_start per event of that page. Both sites that DO
         * fall back are ones where a wrong "yes" is harmless: a session create
         * falls through to an update, and the tagged columns want every landing
         * event to keep a copy. EventRawHandlers::rows() deliberately has no
         * fallback, and a pre-split tracker therefore gets no marker row --
         * an accepted loss, because the alternative is duplicates.
         */
        array( 'kind' => 'flag_fallback', 'from' => 'is_new_session', 'to' => 'is_new_session_start',
               'in' => 'modules/Base/Handler/SessionHandlers.php', 'needle' => "is_new_session'" ),
        array( 'kind' => 'flag_fallback', 'from' => 'is_new_session', 'to' => 'is_new_session_start',
               'in' => 'modules/Base/Handler/EventRawHandlers.php', 'needle' => "is_new_session' )" ),

        /*
         * The complete URL. page_url has had campaign parameters and the site's
         * query_string_filters stripped from it by the time a handler sees it,
         * which is right for 1.x's document identity and wrong for evidence.
         * page_location is what the v2 tracker sends; keepCompleteUrl() stashes
         * it for older beacons, and page_url is the last resort -- at which
         * point the query string is genuinely gone.
         */
        array( 'kind' => 'chain', 'from' => 'page_url', 'to' => 'page_location',
               'in' => 'modules/Base/Handler/EventRawHandlers.php', 'needle' => "?: \$event->get( 'page_url' )" ),
        array( 'kind' => 'chain', 'from' => 'page_url', 'to' => 'page_location',
               'in' => 'modules/Base/Classes/TrackingEventHelpers.php', 'needle' => 'function keepCompleteUrl(' ),

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
         * The visitor's prior-session interval, which the tracker used to
         * measure itself and now sends as an anchor. The callback returns
         * whatever an older tracker sent under the alternative key, unchanged.
         */
        array( 'kind' => 'callback_passthrough', 'from' => 'dsps', 'to' => 'days_since_prior_session',
               'in' => 'modules/Base/Classes/TrackingEventHelpers.php', 'needle' => 'function deriveDaysSincePriorSession(' ),
        array( 'kind' => 'callback_passthrough', 'from' => 'dsfs', 'to' => 'days_since_first_session',
               'in' => 'modules/Base/Classes/TrackingEventHelpers.php', 'needle' => 'function deriveDaysSinceFirstSession(' ),
    ),
);

?>
