<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The page URL and its referrer as the beacon carried them.
 *
 * received_page_location and received_referer, copied at the edge before the
 * property stage cleans the URLs (TrackingEventHelpers::receivedPageLocation()).
 * An event that never passed the edge -- built in process, or queued before the
 * copies existed -- has neither, and then the property itself is the best
 * there is: a queued beacon's is still uncleaned until its drain runs the
 * property stage, an in-process one's may already be cleaned.
 */
class Received {

    /** @return string */
    public static function pageLocation( $event ) {

        return self::either( $event, 'received_page_location', 'page_location' );
    }

    /** The page's own referrer (p_r), not the beacon request's Referer header. */
    public static function referrer( $event ) {

        return self::either( $event, 'received_referer', 'HTTP_REFERER' );
    }

    /** A URL's query string: after the first `?`, up to a `#`. '' when it has none. */
    public static function query( $url ) {

        $url = (string) $url;
        $at  = strpos( $url, '?' );

        if ( $at === false ) {

            return '';
        }

        $query = substr( $url, $at + 1 );
        $hash  = strpos( $query, '#' );

        return $hash === false ? $query : substr( $query, 0, $hash );
    }

    private static function either( $event, $received, $property ) {

        foreach ( array( $received, $property ) as $name ) {

            $value = $event->get( $name );

            if ( is_string( $value ) && $value !== '' ) {

                return $value;
            }
        }

        return '';
    }
}

?>
