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

    /** Materialized at ingest from flags on the event that carried them. No tracker sends them. */
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
     * The id of one v2 event.
     *
     *     wideStringGuid( site_id + visitor_id + session_id + ts + event_name [+ '#' + event_seq] )
     *
     * Derived, never minted -- so a redelivered beacon derives the same ids and
     * collapses on PRIMARY KEY (id, yyyymmdd) instead of counting twice. That
     * is the whole reason a fact id is content-derived here, where 1.x mints
     * fact ids randomly: a fact that arrives twice is the same fact, and two
     * facts differing in any input differ in their id.
     *
     * A beacon yields at most one event of any given name -- page_view,
     * session_start, first_visit -- so the name separates the events of one
     * beacon whatever order they are built in.
     *
     * $ts DOES NOT SEPARATE TWO BEACONS. It is the edge receipt, stamped when
     * each request boots, and beacons that a mobile connection holds back and
     * then releases together reach two processes in the same microsecond:
     * measured on app.openwebanalytics.com, two clicks 77ms apart on the device
     * arrived 1.5ms apart and derived one id, and the second was lost. The
     * tracker's event_seq, counted per session on the device, is what tells
     * them apart; a redelivered beacon carries the same one, so it still
     * collapses. Without one -- an older tracker, a migrated v1 row -- the
     * formula is the five inputs alone, so those ids derive as they always did.
     *
     * Uniqueness is required within a partition, not globally: the key is (id,
     * yyyymmdd), so two events a week apart may share an id with no effect.
     *
     * @param string     $site_id
     * @param string|int $visitor_id
     * @param string|int $session_id
     * @param string|int $ts          MICROSECONDS
     * @param string     $event_name  the v2 name, not the v1 type
     * @param int|null   $event_seq   the tracker's count within the session; null or 0 when it sent none
     * @return int
     */
    public static function id( $site_id, $visitor_id, $session_id, $ts, $event_name, $event_seq = null ) {

        // '#' cannot occur in an event name, so a name ending in digits cannot
        // stand in for another name and a sequence.
        $seq = ( is_numeric( $event_seq ) && (int) $event_seq > 0 ) ? '#' . (int) $event_seq : '';

        return \OWA\Core\Lib::wideStringGuid(
            $site_id . $visitor_id . $session_id . $ts . $event_name . $seq );
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
     * The file names a web server serves for a directory, and the default of
     * base.directory_index_pages. A path ending in one of them is the
     * directory's own page: /store/index.html is /store.
     */
    const DIRECTORY_INDEX_PAGES = array(
        'index.html', 'index.htm', 'index.php', 'default.htm', 'default.html', 'default.aspx', 'default.asp' );

    /**
     * A page path, canonicalised.
     *
     * THE PATH IS A READING, AND page_location IS THE EVIDENCE. This edits the
     * reading only: the URL as it arrived is stored untouched beside it, so
     * nothing here can destroy what was observed.
     *
     * Two collapses:
     *
     *   - a directory index page (dropIndexPage()). /store/index.html and
     *     /store/ are one page to everyone except a report that groups on the
     *     raw path.
     *   - the trailing slash, for the same reason -- except on the root, which
     *     IS '/' and would otherwise group under the empty string.
     *
     * NOT LOWERCASED. Paths are case-sensitive by specification and by most
     * servers' behaviour, so /About and /about can be two pages; folding them
     * would merge rows that a site may deliberately keep apart. Only an index
     * page's name is compared without case: a server that answers for
     * Default.aspx answers for default.aspx too.
     *
     * @param string|null  $path
     * @param array|string $index_pages  directory index file names (indexPageNames());
     *                                   none folds only the trailing slash
     * @return string|null
     */
    public static function canonicalPath( $path, $index_pages = array() ) {

        if ( $path === null || $path === '' ) {

            return $path;
        }

        $path = self::dropIndexPage( (string) $path, $index_pages );

        // The root is '/' and stays that way. Stripping its slash would leave
        // '' and hide the home page under a different value from every other
        // page's.
        if ( strlen( $path ) > 1 && substr( $path, -1 ) === '/' ) {

            $path = rtrim( $path, '/' );
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * A path with its last segment removed when that segment is a directory
     * index page, and the slash before it kept: /store/index.html is /store/.
     *
     * THE LAST SEGMENT ONLY, and all of it. /index.php/products is a front
     * controller's route and keeps its path, and /store/myindex.html is not
     * /store/my. The name is compared without case.
     *
     * @param  string       $path
     * @param  array|string $index_pages  as canonicalPath() takes them
     * @return string
     */
    public static function dropIndexPage( $path, $index_pages ) {

        $path  = (string) $path;
        $names = self::indexPageNames( $index_pages );

        if ( ! $names ) {

            return $path;
        }

        $slash = strrpos( $path, '/' );
        $last  = $slash === false ? $path : substr( $path, $slash + 1 );

        if ( $last === '' || ! in_array( strtolower( $last ), $names, true ) ) {

            return $path;
        }

        return $slash === false ? '' : substr( $path, 0, $slash + 1 );
    }

    /**
     * Directory index file names as a list, lowercased.
     *
     * Takes a list, or one string separated by commas, which is how a config
     * file is likeliest to write it. Blanks are dropped, so an empty list or ''
     * names nothing.
     *
     * @param  array|string|null $value
     * @return array
     */
    public static function indexPageNames( $value ) {

        if ( is_string( $value ) ) {

            $value = explode( ',', $value );
        }

        $out = array();

        foreach ( (array) $value as $name ) {

            if ( ! is_scalar( $name ) ) {

                continue;
            }

            $name = strtolower( trim( (string) $name ) );

            if ( $name !== '' && ! in_array( $name, $out, true ) ) {

                $out[] = $name;
            }
        }

        return $out;
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

    /**
     * filterQuery(), except that a query string none of whose parameters is
     * named comes back exactly as it was. filterQuery() also drops empty pairs
     * ("a=1&&b=2"), which is a reading; a stored value is only edited when a
     * parameter is actually removed.
     *
     * @param string|null $query
     * @param string[]    $drop
     * @return string|null  null when nothing is left
     */
    public static function removeQueryParams( $query, array $drop ) {

        if ( $query === null || $query === '' ) {

            return $query;
        }

        $kept   = self::filterQuery( $query, $drop );
        $before = count( array_filter( explode( '&', (string) $query ), 'strlen' ) );
        $after  = $kept === null ? 0 : count( explode( '&', $kept ) );

        return $after === $before ? $query : $kept;
    }

    /**
     * A URL with named query parameters removed and every other part of it
     * left as it was: scheme, host, path, the surviving pairs in their order
     * and encoding, and the fragment. A URL none of whose parameters is named
     * comes back unchanged.
     *
     * The query is what lies between the first '?' and the first '#'; a '?'
     * inside the fragment is part of the fragment.
     *
     * @param string|null $url
     * @param string[]    $drop  parameter names
     * @return string|null
     */
    public static function removeUrlParams( $url, array $drop ) {

        if ( ! is_string( $url ) || $url === '' || ! $drop ) {

            return $url;
        }

        $hash = strpos( $url, '#' );
        $head = $hash === false ? $url : substr( $url, 0, $hash );
        $tail = $hash === false ? '' : substr( $url, $hash );
        $mark = strpos( $head, '?' );

        if ( $mark === false ) {

            return $url;
        }

        $query = substr( $head, $mark + 1 );
        $kept  = self::removeQueryParams( $query, $drop );

        if ( $kept === $query ) {

            return $url;
        }

        return substr( $head, 0, $mark ) . ( $kept === null ? '' : '?' . $kept ) . $tail;
    }

}

?>
