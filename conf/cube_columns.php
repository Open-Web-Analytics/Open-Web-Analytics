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
 *   literal  one value for the whole build.
 *   new_vs_returning
 *            whether the session was the visitor's first, as the label a
 *            report groups by rather than a flag a renderer has to name.
 *   compute  PHP works it out; see Classes\Cube\ComputeStep.
 *
 * `absent` names the test that is true when the visitor's ACQUISITION was never
 * captured, which is what distinguishes "unresolved" from an ordinary NULL in
 * one column. Not the row: a user property can put a row there for a visitor
 * whose acquisition is still unknown.
 */

return array(

    // The session's attribution, read from its first event -- the only row of
    // the session carrying tags, since the landing URL rides the landing beacon.
    'source' => array(
        'kind' => 'source',
        'tag'  => 'session.tagged_source',
        'host' => 'session.referer_host',
    ),

    'medium' => array(
        'kind' => 'medium',
        'tag'  => 'session.tagged_medium',
        'host' => 'session.referer_host',
    ),

    'campaign' => array( 'kind' => 'copy', 'from' => 'session.tagged_campaign', 'text' => true ),
    'ad'       => array( 'kind' => 'copy', 'from' => 'session.tagged_ad', 'text' => true ),

    /*
     * The tag, else what the engine put in its own query parameter -- which is
     * PHP, because SQL has no percent-decode. Worth almost nothing by volume
     * (0.075% of organic sessions across 2022-2026, and only yandex.ru still
     * does it at all) and built for the mechanism rather than the number.
     */
    'search_terms' => array(
        'kind'  => 'compute',
        'class' => '\OWA\Module\Base\Classes\Cube\SearchTermsStep',
    ),

    // The session's landing page, copied from that same first event. Makes a
    // landing-page report a group-by: 215ms against 3.1s on the measured corpus.
    'landing_page_location' => array( 'kind' => 'copy', 'from' => 'session.page_location' ),
    'landing_page_path'     => array( 'kind' => 'copy', 'from' => 'session.page_path' ),
    'landing_page_query'    => array( 'kind' => 'copy', 'from' => 'session.page_query' ),
    'landing_page_title'    => array( 'kind' => 'copy', 'from' => 'session.page_title' ),

    'is_exit' => array( 'kind' => 'is_exit' ),

    /*
     * New or Returning, read off prior_sessions on the row itself -- so no
     * join, and no second authority for a fact the row already carries.
     *
     * Stamped as the LABEL. The reporting engine groups by a column and the
     * dimension registry has no slot for value labels, so a stored code has no
     * way to become two named buckets; see Classes\Cube\NewVsReturningStep.
     */
    'new_vs_returning' => array( 'kind' => 'new_vs_returning' ),

    // The visitor's acquisition, from the visitor store -- a build's only read
    // outside the partition, and the reason that store exists.
    'acq_source' => array(
        'kind'   => 'source',
        'tag'    => 'visitor.acq_source',
        'host'   => 'visitor.acq_referer_host',
        'absent' => 'acquisition.missing',
    ),

    'acq_medium' => array(
        'kind'   => 'medium',
        'tag'    => 'visitor.acq_medium',
        'host'   => 'visitor.acq_referer_host',
        'absent' => 'acquisition.missing',
    ),

    'acq_campaign' => array(
        'kind' => 'copy', 'from' => 'visitor.acq_campaign',
        'text' => true, 'absent' => 'acquisition.missing',
    ),

    'acq_ad' => array(
        'kind' => 'copy', 'from' => 'visitor.acq_ad',
        'text' => true, 'absent' => 'acquisition.missing',
    ),

    // Nullable, and no sentinel: an acquisition with no search terms is an
    // ordinary absence, and past the store's retention it stays NULL rather
    // than falling back to a later event.
    'acq_search_terms' => array( 'kind' => 'copy', 'from' => 'visitor.acq_search_terms', 'text' => true ),

    // When the build wrote this partition, in microseconds. Constant within
    // one, which is what lets a report say how fresh its answer is.
    'built_at' => array( 'kind' => 'literal', 'value' => 'built_at' ),
);

?>
