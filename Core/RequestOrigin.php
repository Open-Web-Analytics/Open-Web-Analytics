<?php
namespace OWA\Core;

/**
 * Where a request to the REST API comes from: its Origin and Sec-Fetch-Site
 * headers, and the origin it was made to, the scheme, host and port the
 * browser addressed.
 *
 * Read in one place by the CORS headers (View\RestApi::addCorsHeaders()) and
 * by the session-cookie check (Auth::authenticateUser()).
 */
class RequestOrigin {

    /**
     * The request's Origin header; '' when it sent none.
     *
     * @return string
     */
    public static function header() {

        $origin = \OWA\Core\CoreAPI::getServerParam( 'HTTP_ORIGIN' );

        return is_string( $origin ) ? $origin : '';
    }

    /**
     * The request's Sec-Fetch-Site header, lower-cased; '' when it sent none.
     *
     * @return string
     */
    public static function fetchSite() {

        $site = \OWA\Core\CoreAPI::getServerParam( 'HTTP_SEC_FETCH_SITE' );

        return is_string( $site ) ? strtolower( trim( $site ) ) : '';
    }

    /**
     * The origin the request was made to: https when Lib::isHttps() says so,
     * else http, with the request's Host header, its port included when it
     * is not the scheme's default.
     *
     * @return string '' when the request has no Host
     */
    public static function own() {

        $host = \OWA\Core\CoreAPI::getServerParam( 'HTTP_HOST' );

        if ( ! is_string( $host ) || trim( $host ) === '' ) {

            return '';
        }

        return self::of( ( \OWA\Core\Lib::isHttps() ? 'https' : 'http' ) . '://' . trim( $host ) );
    }

    /**
     * A URL's or an Origin's origin: scheme://host, and :port when it is not
     * the scheme's default. Lower-cased.
     *
     * @param  mixed  $url
     * @return string '' when it is not an http or https URL with a host
     */
    public static function of( $url ) {

        if ( ! is_string( $url ) || $url === '' || preg_match( '/[\\\\\x00-\x20\x7f]/', $url ) ) {

            return '';
        }

        $parts = parse_url( $url );

        if ( ! is_array( $parts ) ) {

            return '';
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = rtrim( strtolower( (string) ( $parts['host'] ?? '' ) ), '.' );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' ) {

            return '';
        }

        $port    = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ( $port && $port !== $default ? ':' . $port : '' );
    }

    /**
     * Whether a request is same-origin: it sent no Origin or the origin it
     * was made to, and no Sec-Fetch-Site or `same-origin`.
     *
     * @param  string $origin     the Origin header; '' for none
     * @param  string $fetch_site the Sec-Fetch-Site header; '' for none
     * @param  string $own        the origin the request was made to, as own() gives it
     * @return bool
     */
    public static function isSameOrigin( $origin, $fetch_site, $own ) {

        $origin     = (string) $origin;
        $fetch_site = strtolower( trim( (string) $fetch_site ) );

        if ( $origin !== '' && ( (string) $own === '' || self::of( $origin ) !== (string) $own ) ) {

            return false;
        }

        return $fetch_site === '' || $fetch_site === 'same-origin';
    }

    /**
     * Whether this request is same-origin (isSameOrigin()).
     *
     * @return bool
     */
    public static function requestIsSameOrigin() {

        return self::isSameOrigin( self::header(), self::fetchSite(), self::own() );
    }
}
