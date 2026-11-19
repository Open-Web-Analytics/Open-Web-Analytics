<?php
namespace OWA\Module\Base\Classes;

/**
 * Sanitize Class
 *
 * Responsible sanitizing input and escaping output
 */

class Sanitize {

    /**
     * Escapes a string for use in display output
     *
     * @param    string     $string     The string to be escaped
     * @param    string    $encoding     The charset to use in encoding.
     * @param    string    $quotes        The php constant for encodig quotations used by htmlentities
     * @return    string    html encoded string
     * @link     http://www.php.net/manual/en/function.htmlentities.php
     * @access public
     */
    public static function escapeForDisplay($string, $encoding = 'UTF-8', $quotes = '') {

        if (!$quotes) {
            //use mode to ocnvert both single and double quotes.
            $quotes = ENT_QUOTES;
        }
        
        /*
         * A ZERO is a value, not an absence.
         *
         * This guarded on truthiness, so 0, '0' and 0.0 all fell through and
         * returned null -- and out() echoes whatever it gets. Every zero
         * rendered through a template therefore vanished: a funnel step nobody
         * reached printed "visitors" with no number in front of it, and any
         * count, total or metric that happened to be zero printed as blank
         * rather than as none.
         *
         * Only genuinely absent values are skipped now.
         */
        if ( $string === null || $string === '' ) {

            return '';
        }

        // revert special chars, some values are saved encoded in the database eg. page title
        $string = html_entity_decode( (string) $string, $quotes );

        return htmlentities($string, $quotes, $encoding);
    }

    /**
     * Strip IMG html tags
     *
     * @param    string    $input    String to sanitize
     * @return    string     String with no img tags
     * @access    public
     */
    public static function stripImages( $input ) {

        $output = preg_replace('/(<a[^>]*>)(<img[^>]+alt=")([^"]*)("[^>]*>)(<\/a>)/i', '$1$3$5<br />', $input);
        $output = preg_replace('/(<img[^>]+alt=")([^"]*)("[^>]*>)/i', '$2<br />', $output);
        $output = preg_replace('/<img[^>]*>/i', '', $output);
        return $output;
    }

    /**
     * Strip Scripts and Stylesheets
     *
     * @param    string $input String to sanitize
     * @return    string String with <script>, <style>, <link> elements removed.
     * @access    public
     * @static
     */
    public static function stripScriptsAndCss( $input ) {

        return preg_replace(
                '/(<link[^>]+rel="[^"]*stylesheet"[^>]*>|<img[^>]*>|style="[^"]*")|<script[^>]*>.*?<\/script>|<style[^>]*>.*?<\/style>|<!--.*?-->/is',
                '',
                $input );
    }

    /**
     * Strip whitespace, images, scripts and stylesheets
     *
     * @param     string $input String to sanitize
     * @return    string sanitized string
     * @access public
     */
    public static function stripAllTags( $input = '' ) {

        $output = \OWA\Module\Base\Classes\Sanitize::stripScriptsAndCss( $input );
        $output = \OWA\Module\Base\Classes\Sanitize::stripImages( $output );
        $output = \OWA\Module\Base\Classes\Sanitize::stripHtml( $output );

        return $output;
    }

    /**
     * Strips specified html tags
     *
     * @param    string    $input     String to sanitize
     * @param     string[] $tags    Tag to remove
     * @return    string sanitized String
     * @access    public
     * @static
     */
    public static function stripHtml( $input = '', $tags = [] ) {

        // Passes repeat until one removes nothing: taking a tag out can join
        // the text either side of it into a new one ('<<a>b>' leaves '<b>').
        $output = (string) $input;

        do {
            $before = $output;
            $output = self::stripHtmlOnce( $output, $tags );
        } while ( $output !== $before );

        return $output;
    }

    /**
     * One pass of stripHtml().
     *
     * @param  string   $input
     * @param  string[] $tags
     * @return string
     */
    private static function stripHtmlOnce( $input, $tags ) {

        if ($tags) {
            // Each tag is stripped from what the previous pass left. The closing tag is
            // matched by whole name, as the opening one is, so stripping b does not take
            // </blockquote> too.
            $output = $input;
            foreach ( $tags as $tag ) {
                $tag = preg_quote( (string) $tag, '/' );
                $output = (string) preg_replace( '/<' . $tag . '\b[^>]*>/i', '', $output );
                $output = (string) preg_replace( '/<\/' . $tag . '\b[^>]*>/i', '', $output );
            }
        } else {
            // A tag is a < followed by a letter, /, ! or ?, as an HTML parser
            // reads one; it runs to the next > or, unclosed, to the end. Any
            // other < is text: strip_tags() treated "<5" as an unclosed tag, so
            // the constraint pageViews<5 arrived as "pageViews".
            $output = (string) preg_replace( '/<!--.*?(?:-->|$)|<[a-zA-Z\/!?][^>]*(?:>|$)/s', '', $input );
        }

        return $output;
    }

    /**
     * A non-breaking space (U+00A0) becomes a space.
     *
     * @param  string $input
     * @return string
     */
    public static function removeHiddenSpaces( $input = '' ) {

        return str_replace( "\u{00A0}", ' ', $input );
    }

    /**
     * @param  string $input
     * @return string|null null when the pattern fails
     */
    public static function escapeUnicode( $input = '' ) {

        return preg_replace( "/&amp;#([0-9]+);/s", "&#\\1;", $input );
    }

    /**
     * Changes nothing: each backslash it matches is replaced with a backslash.
     *
     * @param  string $input
     * @return string|null null when the pattern fails
     */
    public static function escapeBackslash( $input = '' ) {

        return preg_replace( "/\\\(?!&amp;#|\?#)/", "\\", $input );
    }

    /**
     * @param  string $input
     * @return string
     */
    public static function stripCarriageReturns( $input = '' ) {

        return str_replace( "\r", "", $input );
    }

    /**
     * Unescapes: `\$` becomes `$`.
     *
     * @param  string $input
     * @return string
     */
    public static function escapeDollarSigns( $input = '' ) {

        return str_replace( "\\\$", "$", $input );
    }

    /**
     * Sanitizes for safe input. Takes an array of options:
     *
     * - hidden_spaces - a non-breaking space becomes a space (removeHiddenSpaces())
     * - escape_html - Encode any html entities.
     * - dollar - `\$` becomes `$` (escapeDollarSigns())
     * - carriage - Remove `\r`
     * - unicode - `&amp;#N;` becomes `&#N;` (escapeUnicode())
     * - backslash - changes nothing (escapeBackslash())
     * - remove_html - Strip tags (stripAllTags()).
     *
     * `encode` is accepted and not read.
     *
     * @param mixed $input Data to sanitize
     * @param array<string, mixed> $options name => on, over the defaults; each on by default but remove_html
     * @return mixed Sanitized data
     * @access public
     * @static
     */
    public static function cleanInput($input, $options = []) {

        /*
         * NOT empty(). It is true for "0", so every request parameter and every
         * tracked string whose value was zero arrived as null: a settings form
         * could not save 0 into an integer setting, and a page titled "0" was
         * stored with no title.
         */
        if ($input === null || $input === '' || $input === false || $input === []) {
            return;
        }

        $options = array_merge(
            [
                'hidden_spaces'     => true,
                'remove_html'     => false,
                'encode'         => true,
                'dollar'         => true,
                'carriage'        => true,
                'unicode'         => true,
                'escape_html'     => true,
                'backslash'     => true],
            $options);

        if (is_array($input)) {

            $output = [];
            foreach ($input as $k => $v) {
                $output[$k] = \OWA\Module\Base\Classes\Sanitize::cleanInput($v, $options);
            }
            return $output;

        } else {

            // The steps below use /u patterns, which answer null for text that
            // is not UTF-8: a Latin-1 value was blanked rather than kept.
            if (is_string($input)) {
                $input = \OWA\Core\Lib::utf8Encode($input);
            }

            $output = $input;

            if ($options['hidden_spaces']) {
                $output = \OWA\Module\Base\Classes\Sanitize::removeHiddenSpaces($output);
            }

            if ($options['remove_html']) {
                $output = \OWA\Module\Base\Classes\Sanitize::stripAllTags($output);
            }

            if ($options['dollar']) {
                $output = \OWA\Module\Base\Classes\Sanitize::escapeDollarSigns($output);
            }

            if ($options['carriage']) {
                $output = \OWA\Module\Base\Classes\Sanitize::stripCarriageReturns($output);
            }

            if ($options['unicode']) {
                $output = \OWA\Module\Base\Classes\Sanitize::escapeUnicode($output);
            }

            if ($options['escape_html']) {
                $output = \OWA\Module\Base\Classes\Sanitize::escapeForDisplay($output);
            }

            if ($options['backslash']) {
                $output = \OWA\Module\Base\Classes\Sanitize::escapeBackslash($output);
            }

            return $output;
        }
    }

    /**
     * A tracking value, kept as the text that was sent.
     *
     * Tracking values are plain text -- a page title, a search term, a URL, a
     * campaign name -- and are stored as such and escaped where they are shown
     * (each output escapes for its own context). cleanInput() HTML-escaped them
     * and stripped anything shaped like a tag, so "a<b and c>d" was stored as
     * "ad", an "é" as "&eacute;", and one title arrived in two spellings that
     * grouped as two rows.
     *
     * What text gets here: valid UTF-8 (Lib::utf8Encode() reads anything else
     * as Windows-1252) with control characters removed. Tabs and line breaks
     * stay. Arrays are cleaned value by value; anything not a string is
     * returned as it is.
     *
     * @param mixed $input
     * @return mixed
     */
    public static function cleanTrackingText( $input ) {

        if ( is_array( $input ) ) {

            $output = [];

            foreach ( $input as $k => $v ) {

                $output[ $k ] = self::cleanTrackingText( $v );
            }

            return $output;
        }

        if ( ! is_string( $input ) ) {

            return $input;
        }

        return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', \OWA\Core\Lib::utf8Encode( $input ) );
    }

    /**
     * @param  mixed $url
     * @return string|void nothing for a value cleanInput() leaves empty
     */
    public static function cleanUrl( $url ) {

        $url = \OWA\Module\Base\Classes\Sanitize::cleanInput($url,
            [
                'hidden_spaces' => true,
                'remove_html'     => true,
                'encode'         => false,
                'dollar'         => true,
                'carriage'        => true,
                'unicode'         => true,
                'escape_html'     => true,
                'backslash'     => false
            ]
        );
        if ( $url ){
            return trim(str_replace('&amp;', '&', $url));
        }
    }

    /**
     * Neutralize dangerous URL schemes for use in an href/src attribute.
     *
     * htmlentities() (escapeForDisplay) stops attribute breakout but leaves a
     * scheme-based payload -- javascript:, data:, vbscript: -- intact and live
     * in an href. Stored tracker URLs are attacker-controllable and are not
     * re-escaped by makeUrlCanonical(), so link-emitting templates must reject
     * unexpected schemes here, before the value reaches the attribute.
     *
     * Permitted: http, https, mailto, ftp, tel; scheme-relative (//host) and
     * root/relative paths (/x, x). Anything else collapses to '#'.
     *
     * @param  string $url
     * @return string        a safe href value ('#' if the scheme is rejected)
     */
    public static function sanitizeHref( $url ) {

        $url = trim( (string) $url );

        if ( $url === '' ) {
            return '#';
        }

        // Decode entities/whitespace obfuscation so we test the real scheme.
        // e.g. "java&#115;cript:" or "java\tscript:" must not slip through.
        $probe = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $probe = preg_replace( '/[\x00-\x20\x7f]+/', '', $probe );

        // No scheme (relative, root-relative, scheme-relative, fragment, query).
        if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $probe, $m ) ) {
            return $url;
        }

        $allowed = [ 'http', 'https', 'mailto', 'ftp', 'tel' ];

        if ( in_array( strtolower( $m[1] ), $allowed, true ) ) {
            return $url;
        }

        return '#';
    }

    /**
     * @param  string $user_id
     * @return mixed cleanInput()'s answer
     */
    public static function cleanUserId ( $user_id ) {

        $illegals = \OWA\Core\CoreAPI::getSetting('base', 'user_id_illegal_chars');

         foreach ( $illegals as $k => $char ) {

             if ( strpos( $user_id, $char ) ) {

                 $user_id = str_replace( $char, "", $user_id);
             }
         }

         return \OWA\Module\Base\Classes\Sanitize::cleanInput($user_id, [] );
    }

    /**
     * Re-encode a JSON value, or reject it.
     *
     * json_decode() answers null for input that is not JSON at all, and
     * json_encode(null) is the four-character string "null" -- so anything
     * malformed would be stored as if it were data.
     *
     * The literal input "null" is itself valid JSON and decodes to null, which
     * is why the check is not simply "did this decode to null".
     *
     * An array is what PHP parsed from bracketed keys, as the tracker sends
     * line items (o_items[0][item_id]=…). It is encoded, each value cleaned as
     * tracking text, and an array keyed by integers encodes as a list.
     *
     * @param  string|array<mixed>|null $json_string
     * @return string|false|void nothing for no value; '' for one that does not parse
     */
    public static function cleanJson( $json_string ) {

        /*
         * Absence tested explicitly, not by truthiness. '0' is valid JSON -- the
         * number zero -- and is falsy in PHP, so a truthiness guard here rejects
         * a legitimate document. The same trap as a dimension value of '0'.
         */
        if ( $json_string === null || $json_string === '' || $json_string === [] ) {

            return;
        }

        if ( is_array( $json_string ) ) {

            return json_encode( self::parsedArray( $json_string ) );
        }

        $decoded = json_decode( $json_string, true );

        if ( $decoded === null && strtolower( trim( (string) $json_string ) ) !== 'null' ) {

            \OWA\Core\CoreAPI::debug( 'Discarding a value declared as JSON that does not parse.' );

            return '';
        }

        return json_encode( $decoded );
    }

    /**
     * A parsed query-string array with its values cleaned as tracking text, and
     * every array keyed only by integers made a list.
     *
     * @param  array<mixed> $input
     * @return array<mixed>
     */
    private static function parsedArray( array $input ) {

        $output = [];

        foreach ( $input as $key => $value ) {

            $output[ $key ] = is_array( $value ) ? self::parsedArray( $value ) : self::cleanTrackingText( $value );
        }

        if ( $output && count( array_filter( array_keys( $output ), 'is_int' ) ) === count( $output ) ) {

            $output = array_values( $output );
        }

        return $output;
    }
}
