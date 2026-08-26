<?php
namespace OWA\Module\Base\Classes;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006-2010 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * Sanitize Class
 *
 * Responsible sanitizing input and escaping output
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

class Sanitize {

    /**
     * Remove Non alpha or numeric characters
     *
     * @param     string|array    $input         String or array contain input to sanitize.
     * @param     array            $exceptions An array of additional characters that should be allowed.
     * @return     string|array     $sanitzed    A Santized string or array
     */
    public static function removeNonAlphaNumeric($input, $exceptions = array()) {

        $allow = '';

        // add exceptions to allowed char part of regex
        if ( !empty( $exceptions ) ) {
            foreach ( $exceptions as $value ) {
                $allowed_chars .= "\\$value";
            }
        }

        $regex = "/[^{$allowed_chars}a-zA-Z0-9]/";

        // check to see if string is an array
        if ( is_array ( $input ) ) {
            $sanitized = array();
            foreach ( $input as $key => $item ) {
                $sanitized[$key] = preg_replace( $regex, '', $item );
            }
        // assume input is a singel string
        } else {
            $sanitized = preg_replace( $regex, '', $input );
        }

        return $sanitized;
    }

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
     * Strip Whitespace
     *
     * @param     string     $str    String to strip
     * @return    string             whitespace sanitized input
     * @access    public
     */
    public static function stripWhitespace( $input ) {

        $output = preg_replace( '/[\n\r\t]+/', '', $input );
        return preg_replace( '/\s{2,}/', ' ', $output );
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

        //$output = owa_sanitize::stripWhitespace( $input );
        $output = \OWA\Module\Base\Classes\Sanitize::stripScriptsAndCss( $input );
        $output = \OWA\Module\Base\Classes\Sanitize::stripImages( $output );
        $output = \OWA\Module\Base\Classes\Sanitize::stripHtml( $output );

        return $output;
    }

    /**
     * Strips specified html tags
     *
     * @param    string    $input     String to sanitize
     * @param     array    $tags    Tag to remove
     * @return    string sanitized String
     * @access    public
     * @static
     */
    public static function stripHtml( $input = '', $tags = array() ) {

        if ($tags) {
            foreach ( $tags as $tag ) {
                $output = preg_replace( '/<' . $tag . '\b[^>]*>/i', '', $input );
                $output = preg_replace( '/<\/' . $tag . '[^>]*>/i', '', $output );
            }
        } else {
            $output = strip_tags($input);
        }

        return $output;
    }

    public static function removeHiddenSpaces( $input = '' ) {

        return str_replace( chr( 0xCA ), '', str_replace( ' ', ' ', $input ) );
    }

    public static function escapeUnicode( $input = '' ) {

        return preg_replace( "/&amp;#([0-9]+);/s", "&#\\1;", $input );
    }

    public static function escapeBackslash( $input = '' ) {

        return preg_replace( "/\\\(?!&amp;#|\?#)/", "\\", $input );
    }

    public static function stripCarriageReturns( $input = '' ) {

        return str_replace( "\r", "", $input );
    }

    public static function escapeDollarSigns( $input = '' ) {

        return str_replace( "\\\$", "$", $input );
    }

    public static function escapeOctets ( $input = '' ) {

        $match = array();
        $found = false;
        while ( preg_match('/%[a-f0-9]{2}/i', $input, $match) ) {
            $input = str_replace($match[0], '', $input);
            $found = true;
        }

        if ( $found ) {
            // Strip out the whitespace that may now exist after removing the octets.
            $filtered_input = trim( preg_replace( '/ +/', ' ', $input ) );
        }
    }

    /**
     * Sanitizes for safe input. Takes an array of options:
     *
     * - hidden_spaces - removes any non space whitespace characters
     * - escape_html - Encode any html entities. Encode must be true for the `remove_html` to work.
     * - dollar - Escape `$` with `\$`
     * - carriage - Remove `\r`
     * - unicode
     * - backslash -
     * - remove_html - Strip HTML with strip_tags. `encode` must be true for this option to work.
     *
     * @param mixed $data Data to sanitize
     * @param array $options
     * @return mixed Sanitized data
     * @access public
     * @static
     */
    public static function cleanInput($input, $options = array()) {

        if (empty($input)) {
            return;
        }

        $options = array_merge(
            array(
                'hidden_spaces'     => true,
                'remove_html'     => false,
                'encode'         => true,
                'dollar'         => true,
                'carriage'        => true,
                'unicode'         => true,
                'escape_html'     => true,
                'backslash'     => true),
            $options);

        if (is_array($input)) {

            $output = array();
            foreach ($input as $k => $v) {
                $output[$k] = \OWA\Module\Base\Classes\Sanitize::cleanInput($v, $options);
            }
            return $output;

        } else {

            if ($options['hidden_spaces']) {
                $output = \OWA\Module\Base\Classes\Sanitize::removeHiddenSpaces($input);
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

    public static function cleanFilename( $str ) {

        $str = str_replace("http://", "", $str);
        $str = str_replace("/", "", $str);
        $str = str_replace("\\", "", $str);
        $str = str_replace("../", "", $str);
        $str = str_replace("..", "", $str);
        $str = str_replace("?", "", $str);
        $str = str_replace("%00", "", $str);

        if (strpos($str, '%00')) {
            $str = '';
        }

        if ($str == null) {
            $str = '';
        }

        return $str;
    }

    public static function cleanUrl( $url ) {

        $url = \OWA\Module\Base\Classes\Sanitize::cleanInput($url,
            array(
                'hidden_spaces' => true,
                'remove_html'     => true,
                'encode'         => false,
                'dollar'         => true,
                'carriage'        => true,
                'unicode'         => true,
                'escape_html'     => true,
                'backslash'     => false
            )
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

        $allowed = array( 'http', 'https', 'mailto', 'ftp', 'tel' );

        if ( in_array( strtolower( $m[1] ), $allowed, true ) ) {
            return $url;
        }

        return '#';
    }

    public static function cleanUserId ( $user_id ) {

        $illegals = \OWA\Core\CoreAPI::getSetting('base', 'user_id_illegal_chars');

         foreach ( $illegals as $k => $char ) {

             if ( strpos( $user_id, $char ) ) {

                 $user_id = str_replace( $char, "", $user_id);
             }
         }

         return \OWA\Module\Base\Classes\Sanitize::cleanInput($user_id, array() );
    }

    public static function cleanMd5( $md5 ) {

        $valid = false;

        if ( ! empty( $md5 ) && preg_match( '/^[a-f0-9]{32}$/', $md5 ) ) {

            $valid = true;
        }

        if ( $valid ) {

            return $md5;
        } else {

            \OWA\Core\CoreAPI::debug("This is not a valid MD5: ".$md5 );
            return "";
        }
    }

    public static function cleanJson( $json_string ) {

        if ( $json_string) {

            $json_array = json_decode( $json_string, true );
            $json_string = json_encode( $json_array );

            return $json_string;
        }
    }
}

?>