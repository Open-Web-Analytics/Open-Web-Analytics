<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The v2 event vocabulary, and the id every v2 event is known by.
 *
 * Pure functions over values. Nothing here reads the database, the request or a
 * setting, so ingest, the migrator and the tests all derive the same answers
 * from the same inputs -- which matters more than usual, because the id is
 * CONTENT-DERIVED and two writers disagreeing about the formula would produce
 * two rows for one event instead of one row twice.
 */
class V2Event {

    /**
     * v1 event type -> v2 event name.
     *
     * v2 speaks the vocabulary the market already speaks; arguing about the
     * names is not where this project should spend its budget. A name already
     * in that vocabulary passes through untouched, which is what lets the
     * tracker send `scroll` or `file_download` directly without a line here.
     *
     * base.first_page_request collapses into page_view deliberately: it was
     * never a different KIND of event, only a page view carrying a flag, and
     * the flag is what the markers are raised from.
     */
    const TYPE_MAP = array(
        'base.page_request'       => 'page_view',
        'base.first_page_request' => 'page_view',
        'dom.click'               => 'click',
        'ecommerce.transaction'   => 'purchase',
        'track.action'            => 'custom_event',
    );

    /** Raised by the server from flags on a page_view. No browser sends them. */
    const MARKER_SESSION_START = 'session_start';
    const MARKER_FIRST_VISIT   = 'first_visit';

    /**
     * Event types that never reach owa_event_raw.
     *
     * A domstream chunk is an ATTACHMENT to a page view, not an event: promoting
     * chunks -- or worse, their samples -- would swamp the table, one measured
     * corpus holding 229,663 chunks carrying 5,767,986 pointer and scroll
     * samples. Feed requests are retired; nothing has written one since 2021.
     */
    const NOT_EVENTS = array( 'dom.stream', 'base.feed_request' );

    /**
     * The v2 name for an incoming event type.
     *
     * @param string $event_type
     * @return string
     */
    public static function name( $event_type ) {

        $event_type = (string) $event_type;

        if ( isset( self::TYPE_MAP[ $event_type ] ) ) {

            return self::TYPE_MAP[ $event_type ];
        }

        /*
         * An unmapped name with a v1 namespace on it is not a v2 name, and
         * storing `dom.keypress` in a VARCHAR(24) called event_type would put a
         * v1 spelling into a v2 column where it would then have to be
         * special-cased forever. Flatten the separator instead, so the value is
         * at least well-formed, and leave a bare name alone.
         */
        return str_replace( '.', '_', $event_type );
    }

    /**
     * Should this event type be written to owa_event_raw at all?
     *
     * @param string $event_type
     * @return bool
     */
    public static function isStorable( $event_type ) {

        return ! in_array( (string) $event_type, self::NOT_EVENTS, true );
    }

    /**
     * The id of one v2 event.
     *
     *     wideStringGuid( site_id + visitor_id + session_id + ts + event_name )
     *
     * Derived, never minted, and by ONE formula with no exceptions -- so a
     * redelivered beacon derives the same ids and collapses on PRIMARY KEY (id,
     * yyyymmdd) instead of counting twice. That is the whole reason a fact id
     * is content-derived here, where 1.x mints fact ids randomly: a fact that
     * arrives twice is the same fact, and two facts differing in any input
     * differ in their id.
     *
     * NO ORDERING AND NO COUNTER, and it does not need one. A beacon yields at
     * most one event of any given name -- page_view, session_start, first_visit
     * -- so the name separates them whatever order they are built in. If a
     * beacon is ever made to carry two events of one name, the fix is a
     * per-event ts, not a discriminator bolted onto the key.
     *
     * THE RESOLUTION OF $ts IS THE UNIQUENESS GUARANTEE. Two events collide
     * only when one visitor and session produce two of them at the same ts
     * under the same name. At second resolution that is reachable; at
     * microsecond resolution it is not. Pass microseconds.
     *
     * Uniqueness is required within a partition, not globally: the key is (id,
     * yyyymmdd), so two events a week apart may share an id with no effect.
     *
     * @param string     $site_id
     * @param string|int $visitor_id
     * @param string|int $session_id
     * @param string|int $ts          MICROSECONDS
     * @param string     $event_name  the v2 name, not the v1 type
     * @return int
     */
    public static function id( $site_id, $visitor_id, $session_id, $ts, $event_name ) {

        return \OWA\Core\Lib::wideStringGuid(
            $site_id . $visitor_id . $session_id . $ts . $event_name );
    }

    /**
     * Split a URL into the three readings owa_event_raw stores beside it.
     *
     * Stored as columns rather than parsed in SQL at read time, because a GROUP
     * BY over a parsing expression cannot use an index and the expression would
     * have to be written once per dialect. The complete URL is kept as the
     * evidence; these are readings of it.
     *
     * A page has no id, deliberately: a hash has to commit to whether the query
     * string is part of a page's identity before anyone asks, and neither
     * answer is any good -- keep the query and every campaign-tagged arrival is
     * a distinct page, drop it and the campaign evidence is gone from raw.
     *
     * @param string $url
     * @return array host, path, query -- each null when the URL has none
     */
    public static function parseUrl( $url ) {

        $empty = array( 'host' => null, 'path' => null, 'query' => null );

        $url = trim( (string) $url );

        if ( $url === '' ) {

            return $empty;
        }

        $parts = parse_url( $url );

        // parse_url returns false only on a seriously malformed URL. Keeping
        // the evidence and storing no readings of it is the honest answer:
        // page_location still holds exactly what arrived.
        if ( $parts === false ) {

            return $empty;
        }

        return array(
            'host'  => isset( $parts['host'] ) && $parts['host'] !== ''
                ? strtolower( $parts['host'] ) : null,
            // A URL with no path is the site root, which IS a path -- '/' --
            // and grouping it under NULL would hide the home page from the
            // landing-page report.
            'path'  => isset( $parts['path'] ) && $parts['path'] !== ''
                ? $parts['path'] : '/',
            'query' => isset( $parts['query'] ) && $parts['query'] !== ''
                ? $parts['query'] : null,
        );
    }
}

?>
