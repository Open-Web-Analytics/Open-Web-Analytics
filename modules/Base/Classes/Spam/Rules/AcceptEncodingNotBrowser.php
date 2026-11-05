<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user agent claims a browser (Client::claimsBrowser()) and Accept-Encoding
 * is not what a browser sends: missing, exactly `gzip`, or a list with no
 * space after a comma (`gzip,deflate,br`).
 *
 * Browsers send `gzip, deflate, br` or `gzip, deflate, br, zstd`. A bare `gzip`
 * is Go's net/http default, which is what the 4 November attack sent. Measured
 * the week before it: 128 beacons with `gzip` or a list without spaces, no
 * real browser.
 *
 * READ AS APACHE RECEIVED IT. A proxy in front of OWA that rewrites
 * Accept-Encoding -- Varnish sets it to `gzip` on a request it fetches for its
 * cache -- makes every beacon fire this.
 */
class AcceptEncodingNotBrowser extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'ACCEPT_ENCODING_NOT_BROWSER';
    const SET    = 'request_headers';
    const POINTS = 2.0;

    public function test( $event, array $headers ) {

        if ( ! $this->client( $headers )->claimsBrowser() ) {

            return false;
        }

        return self::notFromABrowser( (string) ( $headers['accept-encoding'] ?? '' ) );
    }

    /** Whether an Accept-Encoding value is one no browser sends. */
    public static function notFromABrowser( $value ) {

        $value = trim( (string) $value );

        return $value === ''
            || strcasecmp( $value, 'gzip' ) === 0
            || (bool) preg_match( '/,\S/', $value );
    }
}

?>
