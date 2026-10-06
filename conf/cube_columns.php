<?php

/**
 * How each column of the reporting cube gets filled.
 *
 * The cube's column list is the map, and this is the map. Every column
 * owa_event adds past owa_event_raw has exactly one entry; a column with none
 * is a fatal at boot, and so is a second entry for one. The previous shape
 * appended expressions to a list in an order that had to match the entity's by
 * hand, and a reordered entity would have put the right values in the wrong
 * columns with no error anywhere.
 *
 * A DEFINITION NAMES A KIND, AND AN IMPLEMENTATION RENDERS IT -- the same rule
 * metrics and dimensions follow, for the same reason. Nothing here carries SQL,
 * and nothing here names a join alias: `session.tagged_source` is a logical
 * source that the kind resolves. A definition holding `s.s_tagged_source` would
 * be the A.1.23 mistake in a new place, and the first non-MySQL store would
 * rewrite every line of this file by hand.
 *
 * WHAT CONFIG CANNOT DO
 *   - invent a column. The entity declares the schema; this says how a declared
 *     column is filled. An entry naming a column owa_event does not have is a
 *     fatal, in the same breath as a column with no entry.
 *   - compute. A `compute` entry names a class because the computation is PHP
 *     by definition -- that is the whole point of the kind. The boundary is the
 *     same one metrics have: a kind is code, a definition is data.
 *
 * Kinds:
 *
 *   copy     a value lifted from a joined row. `text` collapses '' to NULL;
 *            the landing page columns do not use it, because a copy that edits
 *            its source can disagree with the row it came from.
 *   source   the tag, else the referring host, else direct.
 *   medium   the tag, else the referrer classified against the engine and
 *            social lists -- the reading that has to stay rebuildable.
 *   is_exit  the session's last event, once the session has closed.
 *   is_entrance
 *            the session's first event, in the same device order.
 *   literal  one value for the whole build.
 *   new_vs_returning
 *            whether the session was the visitor's first, as the label a
 *            report groups by rather than a flag a renderer has to name.
 *   is_engaged_session
 *            whether the session was engaged: ten seconds of engagement, two
 *            page views, or a goal event. Stamped on every row of it.
 *   compute  PHP works it out; see Classes\Cube\ComputeStep.
 *
 * `absent` names the test that is true when the visitor's ACQUISITION was never
 * captured, which is what distinguishes "unresolved" from an ordinary NULL in
 * one column. Not the row: a user property can put a row there for a visitor
 * whose acquisition is still unknown.
 *
 * `description` says what the column holds, for an administrator. The build
 * ignores it; the generated wiki shows it.
 */

return array(

    // The session's attribution, read from its first event -- the only row of
    // the session carrying tags, since the landing URL rides the landing beacon.
    'source' => array(
        'kind' => 'source',
        'tag'  => 'session.tagged_source',
        'host' => 'session.referer_host',
        'description' => 'Where the session came from: its tagged source, else the referring site\'s host, else (direct).',
    ),

    'medium' => array(
        'kind' => 'medium',
        'tag'  => 'session.tagged_medium',
        'host' => 'session.referer_host',
        'description' => 'How the session arrived: its tagged medium, else (none) with no referrer, ai-agent from an AI assistant, organic from a search engine, or referral from any other site.',
    ),

    // The tag, else (not set) for a tagged visit with no campaign, else a
    // placeholder mirroring the medium: (direct), (organic), (referral).
    'campaign' => array(
        'kind'   => 'campaign',
        'tag'    => 'session.tagged_campaign',
        'tags'   => array( 'session.tagged_source', 'session.tagged_medium', 'session.tagged_ad' ),
        'medium' => array( 'tag' => 'session.tagged_medium', 'host' => 'session.referer_host' ),
        'description' => 'The session\'s tagged campaign. Empty, shown as (not set), when the visit was tagged without one. An untagged visit gets a placeholder named for how it arrived: (direct), (organic), (ai-agent) or (referral).',
    ),
    // The channel rules (conf/channels.php), from this row's source, medium and campaign.
    'channel' => array( 'kind' => 'channel', 'source' => 'source', 'medium' => 'medium', 'campaign' => 'campaign',
        'description' => 'What kind of traffic the session is, from this row\'s source, medium and campaign by the channel rules (conf/channels.php).' ),
    'ad'       => array( 'kind' => 'copy', 'from' => 'session.tagged_ad', 'text' => true,
        'description' => 'The ad the session was tagged with.' ),

    /*
     * The tag, else what the engine put in its own query parameter -- which is
     * PHP, because SQL has no percent-decode. Worth almost nothing by volume
     * (0.075% of organic sessions across 2022-2026, and only yandex.ru still
     * does it at all) and built for the mechanism rather than the number.
     */
    'search_terms' => array(
        'kind'  => 'compute',
        'class' => '\OWA\Module\Base\Classes\Cube\SearchTermsStep',
        'description' => 'The session\'s tagged search terms, else the terms a search engine put in its referring URL. Empty when neither has them.',
    ),

    // The session's landing page, copied from that same first event. Makes a
    // landing-page report a group-by: 215ms against 3.1s on the measured corpus.
    'landing_page_location' => array( 'kind' => 'copy', 'from' => 'session.page_location',
        'description' => 'The complete URL of the page the session landed on.' ),
    'landing_page_path'     => array( 'kind' => 'copy', 'from' => 'session.page_path',
        'description' => 'The path of the page the session landed on.' ),
    'landing_page_query'    => array( 'kind' => 'copy', 'from' => 'session.page_query',
        'description' => 'The query string of the page the session landed on.' ),
    'landing_page_title'    => array( 'kind' => 'copy', 'from' => 'session.page_title',
        'description' => 'The title of the page the session landed on.' ),

    'is_exit' => array( 'kind' => 'is_exit',
        'description' => '1 on the session\'s last page view once the session has closed, otherwise 0.' ),

    /*
     * New or Returning, read off prior_sessions on the row itself -- so no
     * join, and no second authority for a fact the row already carries.
     *
     * Stamped as the LABEL. The reporting engine groups by a column and the
     * dimension registry has no slot for value labels, so a stored code has no
     * way to become two named buckets; see Classes\Cube\NewVsReturningStep.
     */
    'new_vs_returning' => array( 'kind' => 'new_vs_returning',
        'description' => 'New when the session was the visitor\'s first, otherwise Returning.' ),

    // Whether the session was engaged, on every row of it; the thresholds are
    // in Classes\Cube\IsEngagedSessionStep.
    'is_engaged_session' => array( 'kind' => 'is_engaged_session',
        'description' => '1 on every row of an engaged session -- at least 10 seconds of engagement, at least 2 page views, or a goal event -- otherwise 0.' ),

    // The session's first event, the mirror of is_exit off the same window.
    'is_entrance' => array( 'kind' => 'is_entrance',
        'description' => '1 on the session\'s first page view, otherwise 0.' ),

    // The visitor's acquisition, from the visitor store -- a build's only read
    // outside the partition, and the reason that store exists.
    'acq_source' => array(
        'kind'   => 'source',
        'tag'    => 'visitor.acq_source',
        'host'   => 'visitor.acq_referer_host',
        'absent' => 'acquisition.missing',
        'description' => 'The source of the visitor\'s first visit, from the visitor store.',
    ),

    'acq_medium' => array(
        'kind'   => 'medium',
        'tag'    => 'visitor.acq_medium',
        'host'   => 'visitor.acq_referer_host',
        'absent' => 'acquisition.missing',
        'description' => 'The medium of the visitor\'s first visit, from the visitor store.',
    ),

    'acq_campaign' => array(
        'kind'   => 'campaign',
        'tag'    => 'visitor.acq_campaign',
        'tags'   => array( 'visitor.acq_source', 'visitor.acq_medium', 'visitor.acq_ad' ),
        'medium' => array( 'tag' => 'visitor.acq_medium', 'host' => 'visitor.acq_referer_host' ),
        'absent' => 'acquisition.missing',
        'description' => 'The campaign of the visitor\'s first visit, from the visitor store.',
    ),
    'acq_channel' => array( 'kind' => 'channel', 'source' => 'acq_source', 'medium' => 'acq_medium',
        'campaign' => 'acq_campaign',
        'description' => 'The channel of the visitor\'s first visit, from acq_source, acq_medium and acq_campaign.' ),

    'acq_ad' => array(
        'kind' => 'copy', 'from' => 'visitor.acq_ad',
        'text' => true, 'absent' => 'acquisition.missing',
        'description' => 'The ad of the visitor\'s first visit, from the visitor store.',
    ),

    // Nullable, and no sentinel: an acquisition with no search terms is an
    // ordinary absence, and past the store's retention it stays NULL rather
    // than falling back to a later event.
    'acq_search_terms' => array( 'kind' => 'copy', 'from' => 'visitor.acq_search_terms', 'text' => true,
        'description' => 'The search terms of the visitor\'s first visit, from the visitor store.' ),

    /*
     * The last non-direct touch within the Property's lookback: the session's
     * own reading if it arrived with tags or a referrer, else the visitor's
     * last touch before it, stamped at ingest as prior_touch_* (PLAN 2.29).
     * Each side is read exactly as `source`, `medium` and `campaign` are.
     */
    'attributed_source' => array(
        'kind'  => 'attributed',
        'own'   => array( 'kind' => 'source', 'tag' => 'session.tagged_source', 'host' => 'session.referer_host' ),
        'prior' => array( 'kind' => 'source', 'tag' => 'session.prior_touch_source',
            'host' => 'session.prior_touch_referer_host' ),
        'description' => 'The session\'s source if it arrived with tags or a referrer, else the source of the visitor\'s last non-direct visit within the Property\'s attribution lookback, else (direct).',
    ),
    'attributed_medium' => array(
        'kind'  => 'attributed',
        'own'   => array( 'kind' => 'medium', 'tag' => 'session.tagged_medium', 'host' => 'session.referer_host' ),
        'prior' => array( 'kind' => 'medium', 'tag' => 'session.prior_touch_medium',
            'host' => 'session.prior_touch_referer_host' ),
        'description' => 'The session\'s medium if it arrived with tags or a referrer, else the medium of the visitor\'s last non-direct visit within the Property\'s attribution lookback, else (none).',
    ),
    'attributed_campaign' => array(
        'kind'  => 'attributed',
        'own'   => array(
            'kind'   => 'campaign',
            'tag'    => 'session.tagged_campaign',
            'tags'   => array( 'session.tagged_source', 'session.tagged_medium', 'session.tagged_ad' ),
            'medium' => array( 'tag' => 'session.tagged_medium', 'host' => 'session.referer_host' ),
        ),
        'prior' => array(
            'kind'   => 'campaign',
            'tag'    => 'session.prior_touch_campaign',
            'tags'   => array( 'session.prior_touch_source', 'session.prior_touch_medium', 'session.prior_touch_ad' ),
            'medium' => array( 'tag' => 'session.prior_touch_medium', 'host' => 'session.prior_touch_referer_host' ),
        ),
        'description' => 'The session\'s campaign if it arrived with tags or a referrer, else the campaign of the visitor\'s last non-direct visit within the Property\'s attribution lookback, else (direct).',
    ),
    'attributed_channel' => array( 'kind' => 'channel', 'source' => 'attributed_source',
        'medium' => 'attributed_medium', 'campaign' => 'attributed_campaign',
        'description' => 'The channel of the attributed source, medium and campaign.' ),
    'attributed_ad' => array(
        'kind'  => 'attributed',
        'own'   => array( 'kind' => 'copy', 'from' => 'session.tagged_ad', 'text' => true ),
        'prior' => array( 'kind' => 'copy', 'from' => 'session.prior_touch_ad', 'text' => true ),
        'description' => 'The session\'s ad if it arrived with tags or a referrer, else the ad of the visitor\'s last non-direct visit within the Property\'s attribution lookback.',
    ),

    // When the build wrote this partition, in microseconds. Constant within
    // one, which is what lets a report say how fresh its answer is.
    'built_at' => array( 'kind' => 'literal', 'value' => 'built_at',
        'description' => 'When the build that wrote this row ran, in microseconds. One value per partition.' ),
);

?>
