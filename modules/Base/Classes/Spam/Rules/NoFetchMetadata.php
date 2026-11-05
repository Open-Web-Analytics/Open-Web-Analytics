<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user agent claims a browser that sends Fetch Metadata, and the beacon
 * request carried no Sec-Fetch-Mode.
 *
 * The browser sets Sec-Fetch-* itself on every request, and a page cannot
 * remove them. An HTTP client sending a browser's user agent sends none unless
 * it is written to. Measured on 2,701 beacons for app.openwebanalytics.com: every
 * Firefox, every Safari from 16.4 and every real Chrome beacon carried
 * Sec-Fetch-Mode; the ones without it were Googlebot, one iPhone on iOS 15.6 and
 * forged-Chrome bots.
 *
 * The browsers, and the first version of each that sends it:
 *
 *   Chromium, any browser built on it   76    the Chrome/NN token, which is the
 *                                             engine's version (Client)
 *   Firefox                             90
 *   Safari                              16.4
 *   any browser on iOS                  16.4  the OS version: every one is the
 *                                             system's WebKit
 *
 * Anything else claims nothing this can test, and does not fire.
 */
class NoFetchMetadata extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'NO_FETCH_METADATA';
    const SET    = 'request_headers';
    const POINTS = 5.0;

    public function test( $event, array $headers ) {

        if ( (string) ( $headers['sec-fetch-mode'] ?? '' ) !== '' ) {

            return false;
        }

        return self::claimsFetchMetadata( $this->client( $headers ) );
    }

    /** Whether the browser the agent names sends Fetch Metadata. */
    public static function claimsFetchMetadata( \OWA\Module\Base\Classes\Spam\Client $client ) {

        if ( $client->userAgent() === '' ) {

            return false;
        }

        // First: CriOS and FxiOS name a browser whose version is not the engine's.
        if ( $client->isIos() ) {

            return \OWA\Module\Base\Classes\Spam\Client::atLeast( $client->osVersion(), 16, 4 );
        }

        $chromium = $client->chromiumMajor();

        if ( $chromium !== null ) {

            return $chromium >= 76;
        }

        $family = $client->family();

        if ( $family === 'Safari' ) {

            return \OWA\Module\Base\Classes\Spam\Client::atLeast( $client->version(), 16, 4 );
        }

        // Firefox, Firefox Mobile, Firefox Beta and the like.
        if ( strpos( $family, 'Firefox' ) === 0 ) {

            return \OWA\Module\Base\Classes\Spam\Client::atLeast( $client->version(), 90 );
        }

        return false;
    }
}

?>
