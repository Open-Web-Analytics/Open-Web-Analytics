<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

/**
 * The page URL's query string is longer than 500 characters. Fires instead of
 * QUERY_LONG, never beside it.
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
