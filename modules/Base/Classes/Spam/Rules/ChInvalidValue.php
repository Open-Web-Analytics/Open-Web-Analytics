<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A client hint holds a value real Chromium never sends: a Sec-CH-UA-Platform
 * outside ChPlatformMismatch::PLATFORMS, such as "Mac OS X", or a
 * Sec-CH-UA-Mobile other than ?0 or ?1.
 *
 * Measured the week before the 4 November attack: 5 beacons with platform
 * "Mac OS X", none from a real browser. The platform is compared without its
 * quotes, so "Windows" and Windows are the same value here.
 */
class ChInvalidValue extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'CH_INVALID_VALUE';
    const SET    = 'request_headers';
    const POINTS = 3.0;

    /** The two values of Sec-CH-UA-Mobile, a structured-header boolean. */
    const MOBILE = array( '?0', '?1' );

    public function test( $event, array $headers ) {

        $platform = ChPlatformMismatch::unquote( $headers['sec-ch-ua-platform'] ?? '' );

        if ( $platform !== '' && ! array_key_exists( $platform, ChPlatformMismatch::PLATFORMS ) ) {

            return true;
        }

        $mobile = trim( (string) ( $headers['sec-ch-ua-mobile'] ?? '' ) );

        return $mobile !== '' && ! in_array( $mobile, self::MOBILE, true );
    }
}

?>
