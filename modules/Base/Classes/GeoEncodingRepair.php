<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * Recognise, and undo, a UTF-8 string that was encoded as UTF-8 a second time.
 *
 * The geolocation reader used to convert every name it read from the MaxMind
 * database from ISO-8859-1 to UTF-8. MaxMind stores names as UTF-8 already --
 * the MaxMind DB format spec defines its string type as "a variable length byte
 * sequence that contains valid utf8" -- so the conversion ran over text that was
 * never Latin-1 and encoded it twice. "München" was stored as "MÃ¼nchen", and
 * any city or region with an umlaut, accent or cedilla has been wrong since.
 * See issue #742.
 *
 * WHY THIS IS A SEPARATE CLASS. Undoing a mis-encoding means rewriting stored
 * data, and a rule that is slightly too eager corrupts rows that were fine. So
 * the decision is kept away from the code that does the writing, where it can be
 * tested exhaustively against the cases that must NOT be touched, with no
 * database in the way. GeoNameEncodingTest is that suite.
 *
 * NO MBSTRING. ext-mbstring is not a production Composer requirement and the
 * release build installs with --no-dev, so a repair that needed it would refuse
 * to run on exactly the hosts most likely to need it. PCRE's UTF-8 mode is
 * always available, and the rest is arithmetic.
 */
class GeoEncodingRepair {

    /**
     * The original text, if this value is double-encoded UTF-8. Null otherwise.
     *
     * Null means LEAVE IT ALONE, and every branch below that returns null is a
     * case where rewriting would do damage:
     *
     *   not valid UTF-8      something else is wrong with this row; a repair
     *                        would guess, and guessing is what caused this
     *   a codepoint > U+00FF encoding Latin-1 as UTF-8 can only ever produce
     *                        codepoints up to U+00FF, so anything above proves
     *                        the value was never put through that conversion.
     *                        This is what protects genuine Cyrillic, Greek,
     *                        Hebrew and CJK names
     *   pure ASCII           nothing to undo
     *   decodes to invalid   the value is genuine text that merely happens to
     *   UTF-8                live in the Latin-1 range, like "são paulo". Its
     *                        bytes read as Latin-1 are not valid UTF-8, which is
     *                        exactly what tells it apart from a double encoding
     *
     * The conversion is reversible by construction: each surviving codepoint is
     * emitted as the single byte it came from, so re-encoding the result
     * reproduces the input exactly.
     *
     * The test is conservative rather than provably unique. A genuine name whose
     * Latin-1 bytes happened to form valid UTF-8 would be rewritten, so the
     * command that calls this has a dry run and names every row it would change.
     * In practice a sequence like "Ã¼" does not occur in a real place name, and
     * where it does occur it is a double encoding.
     *
     * @param string|null $value
     * @return string|null the repaired value, or null to leave it alone
     */
    public static function repair( $value ) {

        if ( ! is_string( $value ) || $value === '' ) {

            return null;
        }

        // preg with the /u modifier fails on invalid UTF-8, which is the cheapest
        // validity test available without mbstring.
        if ( ! preg_match( '//u', $value ) ) {

            return null;
        }

        $out       = '';
        $above_ascii = false;

        foreach ( self::codepoints( $value ) as $codepoint ) {

            if ( $codepoint > 0xFF ) {

                return null;
            }

            if ( $codepoint > 0x7F ) {

                $above_ascii = true;
            }

            $out .= chr( $codepoint );
        }

        if ( ! $above_ascii ) {

            return null;
        }

        if ( ! preg_match( '//u', $out ) ) {

            return null;
        }

        return $out;
    }

    /** Is this value double-encoded? Convenience for callers that only count. */
    public static function isDoubleEncoded( $value ) {

        return self::repair( $value ) !== null;
    }

    /**
     * The codepoints of a UTF-8 string, without mbstring.
     *
     * The caller has already established the string is valid UTF-8, so this does
     * not re-validate; it decodes the leading-byte length and folds in the
     * continuation bytes.
     *
     * @param string $value
     * @return array<int>
     */
    private static function codepoints( $value ) {

        $codepoints = array();
        $length     = strlen( $value );
        $i          = 0;

        while ( $i < $length ) {

            $byte = ord( $value[ $i ] );

            if ( $byte < 0x80 ) {

                $codepoints[] = $byte;
                $i           += 1;
                continue;
            }

            if ( ( $byte & 0xE0 ) === 0xC0 ) {

                $width     = 2;
                $codepoint = $byte & 0x1F;

            } elseif ( ( $byte & 0xF0 ) === 0xE0 ) {

                $width     = 3;
                $codepoint = $byte & 0x0F;

            } else {

                $width     = 4;
                $codepoint = $byte & 0x07;
            }

            for ( $c = 1; $c < $width; $c++ ) {

                $codepoint = ( $codepoint << 6 ) | ( ord( $value[ $i + $c ] ) & 0x3F );
            }

            $codepoints[] = $codepoint;
            $i           += $width;
        }

        return $codepoints;
    }
}
