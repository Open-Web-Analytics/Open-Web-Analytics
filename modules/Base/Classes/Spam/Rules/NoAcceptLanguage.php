<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user agent claims a browser (Client::claimsBrowser()) and the request
 * has no Accept-Language.
 *
 * One point: measured the week before the 4 November attack, it fired on 1 of
 * 2,389 real browser beacons, and on 12 bot beacons.
 */
class NoAcceptLanguage extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'NO_ACCEPT_LANGUAGE';
    const SET    = 'request_headers';
    const POINTS = 1.0;

    public function test( $event, array $headers ) {

        return trim( (string) ( $headers['accept-language'] ?? '' ) ) === ''
            && $this->client( $headers )->claimsBrowser();
    }
}

?>
