<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Sec-CH-UA-Mobile disagrees with the user agent's Mobile token: ?1 while the
 * agent has no "Mobile", or ?0 while it has one.
 *
 * Chromium sends ?1 exactly when its agent says Mobile. An Android tablet says
 * neither, ?0 and no Mobile token, so it matches. The 117 `android` agents of
 * the week before the 4 November attack sent ?1.
 *
 * Only for the two real values; anything else is CH_INVALID_VALUE's.
 */
class ChMobileMismatch extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'CH_MOBILE_MISMATCH';
    const SET    = 'request_headers';
    const POINTS = 2.0;

    public function test( $event, array $headers ) {

        $mobile = trim( (string) ( $headers['sec-ch-ua-mobile'] ?? '' ) );

        if ( ! in_array( $mobile, ChInvalidValue::MOBILE, true ) ) {

            return false;
        }

        return ( $mobile === '?1' ) !== $this->client( $headers )->hasMobileToken();
    }
}

?>
