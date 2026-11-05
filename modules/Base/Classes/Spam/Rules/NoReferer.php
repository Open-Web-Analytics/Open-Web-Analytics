<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The beacon request has no Referer header at all.
 *
 * A browser sends one to log.php unless the page's referrer policy is
 * no-referrer, so this is weak evidence and worth one point: it decides
 * nothing on its own and tips a beacon the other rules already doubt.
 */
class NoReferer extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'NO_REFERER';
    const SET    = 'page_origin';
    const POINTS = 1.0;

    public function test( $event, array $headers ) {

        return trim( (string) ( $headers['referer'] ?? '' ) ) === '';
    }
}

?>
