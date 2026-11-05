<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The beacon request's Referer header names a host, and it is not the page's.
 *
 * A browser sets the Referer of the request to log.php to the page that sent
 * the beacon, or to its origin when the page's referrer policy trims it, so its
 * host is the page's host. Measured on 2,701 browser beacons: it was on every
 * one except 117, all from m.baidu.com, Baidu's mobile transcoder. A page served
 * through such a proxy scores 3.0 here, below the default threshold.
 *
 * The header is request_referer, read at the edge. Not HTTP_REFERER, the page's
 * own referrer, which the tracker sends.
 *
 * Does not fire when the request has no Referer (NO_REFERER) or the beacon no
 * page location (NO_PAGE_LOCATION). Hosts compare as Spam\Hosts does.
 */
class RefererMismatch extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'REFERER_MISMATCH';
    const SET    = 'page_origin';
    const POINTS = 3.0;

    public function test( $event, array $headers ) {

        $referer = \OWA\Module\Base\Classes\Spam\Hosts::of( $headers['referer'] ?? '' );
        $page    = \OWA\Module\Base\Classes\Spam\Hosts::of(
            \OWA\Module\Base\Classes\Spam\Received::pageLocation( $event ) );

        return $referer !== '' && $page !== '' && $referer !== $page;
    }
}

?>
