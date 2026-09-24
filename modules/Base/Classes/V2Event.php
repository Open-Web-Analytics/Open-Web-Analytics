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
     * The event-name renames, read from conf/beacon_compat.php.
     *
     * v2 speaks the vocabulary the market already speaks; arguing about the
     * names is not where this project should spend its budget. A name already
     * in that vocabulary passes through untouched, which is what lets the
     * tracker send `scroll` or `file_download` directly with no entry at all.
     *
     * A const here was the only enumerable bridge of the seven, and it was
     * enumerable by accident rather than design -- nothing tied it to the other
     * six. They are indexed together now, and this one is APPLIED from there.
     *
     * Memoised because name() runs once per beacon and loadConf() stats two
     * paths and includes a file on every call.
     *
     * @var array|null
     */
    private static $type_map = null;

    /** @return array old event type => v2 name */
    public static function typeMap() {

        if ( self::$type_map === null ) {

            $conf = (array) \OWA\Core\CoreAPI::loadConf(
                'beacon_compat.php', 'beacon.compat' );

            self::$type_map = isset( $conf['event_names'] )
                ? (array) $conf['event_names']
                : array();
        }

        return self::$type_map;
    }

    /** Raised by the server from flags on a page_view. No browser sends them. */
    const MARKER_SESSION_START = 'session_start';
    const MARKER_FIRST_VISIT   = 'first_visit';

    /**
     * What a build writes where it could not resolve a value at all.
     *
     * NULL keeps one meaning -- the beacon carried nothing -- and this carries
     * the other, the pipeline could not work it out. Not a contradiction of
     * "absence is NULL": a build generates this and no visitor can.
     *
     * It is a control byte because it has to be un-typeable. A tagged visit
     * puts the URL's own text straight into source and medium, so a readable
     * token like "(unknown)" would be forgeable by anyone who could write a
     * link -- and this value asserts that OUR pipeline failed. strip() below is
     * the other half: control bytes are removed from observed values, so the
     * two alphabets do not overlap.
     *
     * The renderer shows NULL as `(not set)` and this as `(unknown)`.
     */
    const UNRESOLVED = "\x1A";

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

        $map = self::typeMap();

        if ( isset( $map[ $event_type ] ) ) {

            return $map[ $event_type ];
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
     * An observed value with its control bytes removed.
     *
     * Keeps UNRESOLVED un-forgeable, and keeps control bytes out of a VARCHAR
     * every consumer downstream has to render. Tab, CR and LF are left: they
     * are whitespace by intent, and removing them joins two words.
     *
     * Matched as BYTES, not with /u. A Unicode class match returns null on
     * malformed UTF-8, erasing the value, and \p{C} also covers the format
     * characters that are load-bearing in Arabic, Persian and emoji sequences.
     * The bytes below cannot occur inside a UTF-8 multibyte sequence.
     *
     * @param mixed $value
     * @return string|null  null in, null out
     */
    public static function strip( $value ) {

        if ( $value === null ) {

            return null;
        }

        return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );
    }

    /**
     * The longest a domain name can be, per RFC 1035.
     *
     * A parse that yields something longer has not found a host, whatever else
     * it looks like. Checked here so `(not a host)` is one answer -- NULL --
     * rather than a value that overflows the column it is headed for.
     */
    const MAX_HOSTNAME = 253;

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

        $host = isset( $parts['host'] ) && $parts['host'] !== ''
            ? strtolower( $parts['host'] ) : null;

        if ( $host !== null && strlen( $host ) > self::MAX_HOSTNAME ) {

            $host = null;
        }

        return array(
            'host'  => $host,
            // A URL with no path is the site root, which IS a path -- '/' --
            // and grouping it under NULL would hide the home page from the
            // landing-page report.
            'path'  => isset( $parts['path'] ) && $parts['path'] !== ''
                ? $parts['path'] : '/',
            'query' => isset( $parts['query'] ) && $parts['query'] !== ''
                ? $parts['query'] : null,
        );
    }

    /**
     * A page path, canonicalised.
     *
     * THE PATH IS A READING, AND page_location IS THE EVIDENCE. This edits the
     * reading only: the URL as it arrived is stored untouched beside it, so
     * nothing here can destroy what was observed.
     *
     * Two collapses, both of which v1 does and neither of which GA does:
     *
     *   - the site's default page. /store/index.html and /store/ are one page
     *     to everyone except a report that groups on the raw path.
     *   - the trailing slash, for the same reason -- except on the root, which
     *     IS '/' and would otherwise group under the empty string.
     *
     * NOT LOWERCASED. Paths are case-sensitive by specification and by most
     * servers' behaviour, so /About and /about can be two pages; folding them
     * would merge rows that a site may deliberately keep apart.
     *
     * @param string|null $path
     * @param string      $default_page  e.g. 'index.html', or '' for none
     * @return string|null
     */
    public static function canonicalPath( $path, $default_page = '' ) {

        if ( $path === null || $path === '' ) {

            return $path;
        }

        $path = (string) $path;

        if ( $default_page !== '' ) {

            $length = strlen( $default_page );

            if ( substr( $path, -$length ) === $default_page ) {

                $path = substr( $path, 0, -$length );
            }
        }

        // The root is '/' and stays that way. Stripping its slash would leave
        // '' and hide the home page under a different value from every other
        // page's.
        if ( strlen( $path ) > 1 && substr( $path, -1 ) === '/' ) {

            $path = rtrim( $path, '/' );
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * A query string with named parameters removed.
     *
     * REBUILT FROM PARTS, never edited with a regex. v1 strips a parameter with
     * `#\?name=.*$|&name=.*$|name=.*&#msiU` over the whole URL, which matches
     * the name anywhere -- inside a path segment, inside another parameter's
     * value -- and then takes everything after it. A query string is a list and
     * this treats it as one.
     *
     * What survives keeps its original encoding, because the pairs are never
     * decoded and re-encoded: a value written with %20 comes back with %20, and
     * one written with + comes back with +. Only the NAME is decoded, and only
     * to compare it.
     *
     * @param string|null $query
     * @param string[]    $drop  parameter names
     * @return string|null  null when nothing is left
     */
    public static function filterQuery( $query, array $drop ) {

        if ( $query === null || $query === '' || ! $drop ) {

            return $query === '' ? null : $query;
        }

        $unwanted = array();

        foreach ( $drop as $name ) {

            $name = trim( (string) $name );

            if ( $name !== '' ) {

                $unwanted[ $name ] = true;
            }
        }

        if ( ! $unwanted ) {

            return $query;
        }

        $kept = array();

        foreach ( explode( '&', (string) $query ) as $pair ) {

            if ( $pair === '' ) {

                continue;
            }

            $name = strpos( $pair, '=' ) === false
                ? $pair : substr( $pair, 0, strpos( $pair, '=' ) );

            // A name arrives percent-encoded; the list an operator typed does
            // not. Compared decoded so the two can match at all.
            if ( isset( $unwanted[ urldecode( $name ) ] ) ) {

                continue;
            }

            $kept[] = $pair;
        }

        return $kept ? implode( '&', $kept ) : null;
    }

}

?>
