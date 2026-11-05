<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The page URL's query string is longer than 500 characters. Fires instead of
 * QUERY_LONG, never beside it.
 *
 * Measured across app's sites: none on 1-3 November, 86 during the 4-5
 * November attack.
 */
class QueryVeryLong extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'QUERY_VERY_LONG';
    const SET    = 'query_string';
    const POINTS = 2.0;

    /** Longer than this fires. */
    const LONGER_THAN = 500;

    public function test( $event, array $headers ) {

        return QueryLong::queryLength( $event ) > self::LONGER_THAN;
    }
}

?>
