<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user agent claims a Chromium browser that sends User-Agent Client Hints,
 * Chromium 89 or later, and the beacon request carried no Sec-CH-UA.
 *
 * The browsers that send it, by the parser's family: Chrome (and Brave, whose
 * agent is Chrome's), Edge, Opera, Samsung Internet and Android WebView. Not
 * Chrome on iOS, which is WebKit, and not Firefox or Safari, which send none.
 * The version is the Chromium one (Client::chromiumMajor()).
 *
 * Fewer points than NO_FETCH_METADATA, so on its own it does not reach the
 * default threshold.
 */
class NoClientHints extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'NO_CLIENT_HINTS';
    const SET    = 'request_headers';
    const POINTS = 2.5;

    /** The parser's families for the browsers above. */
    const FAMILIES = array(
        'Chrome', 'Chrome Mobile', 'Chrome Mobile WebView',
        'Edge', 'Edge Mobile',
        'Opera', 'Opera Mobile',
        'Brave',
        'Samsung Internet',
    );

    public function test( $event, array $headers ) {

        if ( (string) ( $headers['sec-ch-ua'] ?? '' ) !== '' ) {

            return false;
        }

        return self::claimsClientHints( $this->client( $headers ) );
    }

    public static function claimsClientHints( \OWA\Module\Base\Classes\Spam\Client $client ) {

        // EdgiOS is Edge Mobile to the parser, and WebKit like everything on iOS.
        if ( $client->userAgent() === '' || $client->isIos() ) {

            return false;
        }

        if ( ! in_array( $client->family(), self::FAMILIES, true ) ) {

            return false;
        }

        $chromium = $client->chromiumMajor();

        return $chromium !== null && $chromium >= 89;
    }
}

?>
