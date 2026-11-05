<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A value in the page URL or its referrer is percent-encoded three or more
 * times over: `%25` followed by `25` at least twice, as in `%252525`.
 *
 * Each pass of encoding turns a `%` into `%25`, so a value encoded twice reads
 * `%2525`, which a redirect chain or a link copied out of a tracking email can
 * produce honestly, and does not fire. Three or more is a crawler re-encoding
 * the URL it fetched on every pass. Measured across app's sites: no beacon on
 * 1-3 November, 542 during the 4-5 November attack.
 *
 * Both URLs as received (Spam\Received), case-insensitively.
 */
class QueryEncodingLoop extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'QUERY_ENCODING_LOOP';
    const SET    = 'query_string';
    const POINTS = 3.0;

    const PATTERN = '/%25(?:25){2,}/i';

    public function test( $event, array $headers ) {

        foreach ( array( \OWA\Module\Base\Classes\Spam\Received::pageLocation( $event ),
                         \OWA\Module\Base\Classes\Spam\Received::referrer( $event ) ) as $url ) {

            if ( preg_match( self::PATTERN, $url ) ) {

                return true;
            }
        }

        return false;
    }
}

?>
