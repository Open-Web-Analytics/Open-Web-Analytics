<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The page URL's query string is longer than 200 characters, and not longer
 * than 500 (QUERY_VERY_LONG takes those, so the two never fire together).
 *
 * The query string is everything after the first `?` up to a `#`, as the beacon
 * carried it (Spam\Received). Measured across app's sites: none over 200 on 1-3
 * November, 386 during the 4-5 November attack.
 */
class QueryLong extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'QUERY_LONG';
    const SET    = 'query_string';
    const POINTS = 1.0;

    /** Longer than this fires. */
    const LONGER_THAN = 200;

    public function test( $event, array $headers ) {

        $length = self::queryLength( $event );

        return $length > self::LONGER_THAN && $length <= QueryVeryLong::LONGER_THAN;
    }

    /** The length of the page URL's query string, in characters. */
    public static function queryLength( $event ) {

        $query = \OWA\Module\Base\Classes\Spam\Received::query(
            \OWA\Module\Base\Classes\Spam\Received::pageLocation( $event ) );

        return function_exists( 'mb_strlen' ) ? mb_strlen( $query, 'UTF-8' ) : strlen( $query );
    }
}

?>
