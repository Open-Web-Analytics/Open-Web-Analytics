<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Sec-CH-UA-Platform names a different operating system from the user agent's:
 * "Linux" with a Mac or Windows agent, "Windows" with an Android or iOS one.
 *
 * A Chromium browser derives both from the same system, so they agree. A bot
 * that sets the hints by hand gets them wrong: measured the week before the
 * 4 November attack, 18 Mac agents with platform "Linux" and 117 `android`
 * agents with platform "Windows", and no real browser.
 *
 * Never fires for "Unknown", for an agent whose system it cannot tell
 * (Client::osClass()), or for a value outside the list (CH_INVALID_VALUE's).
 * Nor for Linux against Android, either way round: Android is Linux, and
 * Chrome's desktop-site mode on Android sends a Linux agent with platform
 * "Android".
 */
class ChPlatformMismatch extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'CH_PLATFORM_MISMATCH';
    const SET    = 'request_headers';
    const POINTS = 3.0;

    /** Every value Chromium sends, and the system each names; '' names none. */
    const PLATFORMS = array(
        'Windows'     => 'windows',
        'macOS'       => 'mac',
        'Linux'       => 'linux',
        'Android'     => 'android',
        'Chrome OS'   => 'chromeos',
        'Chromium OS' => 'chromeos',
        'iOS'         => 'ios',
        'Unknown'     => '',
    );

    public function test( $event, array $headers ) {

        $platform = self::unquote( $headers['sec-ch-ua-platform'] ?? '' );

        if ( $platform === '' || ! array_key_exists( $platform, self::PLATFORMS ) ) {

            return false;
        }

        $claimed = self::PLATFORMS[ $platform ];
        $agent   = $this->client( $headers )->osClass();

        if ( $claimed === '' || $agent === '' || $claimed === $agent ) {

            return false;
        }

        $pair = array( $claimed, $agent );
        sort( $pair );

        return $pair !== array( 'android', 'linux' );
    }

    /** A structured-header string's value: "Windows" is Windows. */
    public static function unquote( $value ) {

        $value = trim( (string) $value );

        if ( strlen( $value ) >= 2 && $value[0] === '"' && substr( $value, -1 ) === '"' ) {

            return substr( $value, 1, -1 );
        }

        return $value;
    }
}

?>
