<?php
namespace OWA\Core;

/**
 * The address of the client a request came from.
 *
 * The client is the first address, from the right, that no trusted proxy
 * added. The chain is X-Forwarded-For's hops followed by REMOTE_ADDR, the
 * peer that connected. Read from the right, a hop that is private or
 * reserved, or listed in OWA_TRUSTED_PROXIES, is a proxy of this
 * installation's and is passed; the first one that is neither is the
 * client. Everything to its left is what the client, or a proxy of its own,
 * chose to send.
 *
 * When every hop is passed, as for a request from inside a private network,
 * the client is the leftmost of them. A hop that is not an address ends the
 * walk: the client is then the last address passed.
 *
 * Client-IP, X-Cluster-Client-IP and Forwarded-For are not read: any client
 * can send them.
 */
class ClientAddress {

    /** The constant that lists the trusted proxies. */
    const CONSTANT = 'OWA_TRUSTED_PROXIES';

    /**
     * This request's client address, '' when it has none.
     *
     * @return string
     */
    public static function fromRequest() {

        return self::choose(
            (string) CoreAPI::getServerParam( 'REMOTE_ADDR' ),
            (string) CoreAPI::getServerParam( 'HTTP_X_FORWARDED_FOR' ),
            (array) CoreAPI::getSetting( 'base', 'trusted_proxies' ) );
    }

    /**
     * The client address of a chain (see the class).
     *
     * @param  string        $remote_addr   the peer that connected
     * @param  string        $forwarded_for X-Forwarded-For as sent: "a, b, c"
     * @param  array<mixed>  $trusted       addresses and CIDR ranges of trusted proxies
     * @return string '' when REMOTE_ADDR is not an address and nothing to its left is reached
     */
    public static function choose( $remote_addr, $forwarded_for, array $trusted = [] ) {

        $chain = $forwarded_for !== '' ? explode( ',', $forwarded_for ) : [];
        $chain[] = $remote_addr;

        $passed = '';

        foreach ( array_reverse( $chain ) as $hop ) {

            $address = self::address( $hop );

            if ( $address === '' ) {

                break;
            }

            if ( Lib::isNotPrivateIp( $address ) && ! self::isTrusted( $address, $trusted ) ) {

                return $address;
            }

            $passed = $address;
        }

        return $passed;
    }

    /**
     * A hop as an address: brackets and a port removed ("[2001:db8::1]:443",
     * "203.0.113.9:8080"); '' when it is not one.
     *
     * @param  string $hop
     * @return string
     */
    public static function address( $hop ) {

        $hop = trim( (string) $hop );

        if ( preg_match( '/^\[([^\]]+)\](?::\d+)?$/D', $hop, $m ) ) {

            $hop = $m[1];

        } elseif ( preg_match( '/^([0-9.]+):\d+$/D', $hop, $m ) ) {

            $hop = $m[1];
        }

        return filter_var( $hop, FILTER_VALIDATE_IP ) !== false ? $hop : '';
    }

    /**
     * Whether an address is one of the trusted proxies, or in one of their ranges.
     *
     * @param  string       $address a valid address
     * @param  array<mixed> $trusted
     * @return bool
     */
    public static function isTrusted( $address, array $trusted ) {

        $packed = inet_pton( $address );

        if ( $packed === false ) {

            return false;
        }

        foreach ( $trusted as $entry ) {

            $range = self::range( $entry );

            if ( $range && strlen( $range[0] ) === strlen( $packed ) && self::prefixMatches( $packed, $range[0], $range[1] ) ) {

                return true;
            }
        }

        return false;
    }

    /**
     * What is wrong with OWA_TRUSTED_PROXIES: not a list, or an entry that is
     * not an address or a CIDR range.
     *
     * @param  mixed $value the constant's value
     * @return list<string>
     */
    public static function configProblems( $value ) {

        if ( ! is_array( $value ) ) {

            return [ sprintf( CoreAPI::t( '%s must be a list of IP addresses and CIDR ranges.' ), self::CONSTANT ) ];
        }

        $problems = [];

        foreach ( $value as $entry ) {

            if ( ! self::range( $entry ) ) {

                $problems[] = sprintf( CoreAPI::t( '%1$s lists %2$s, which is not an IP address or a CIDR range.' ),
                    self::CONSTANT, is_scalar( $entry ) ? '"' . (string) $entry . '"' : gettype( $entry ) );
            }
        }

        return $problems;
    }

    /**
     * An entry as its packed network address and prefix length; null when it
     * is not an address or a CIDR range.
     *
     * @param  mixed $entry
     * @return array{0: string, 1: int}|null
     */
    private static function range( $entry ) {

        if ( ! is_string( $entry ) ) {

            return null;
        }

        $parts = explode( '/', trim( $entry ), 2 );

        if ( filter_var( $parts[0], FILTER_VALIDATE_IP ) === false ) {

            return null;
        }

        $packed = (string) inet_pton( $parts[0] );
        $bits   = strlen( $packed ) * 8;

        if ( ! isset( $parts[1] ) ) {

            return [ $packed, $bits ];
        }

        if ( ! preg_match( '/^\d{1,3}$/D', $parts[1] ) || (int) $parts[1] > $bits ) {

            return null;
        }

        return [ $packed, (int) $parts[1] ];
    }

    /**
     * Whether the first $prefix bits of two packed addresses of one family agree.
     *
     * @param  string $a
     * @param  string $b
     * @param  int    $prefix
     * @return bool
     */
    private static function prefixMatches( $a, $b, $prefix ) {

        $bytes = intdiv( $prefix, 8 );

        if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {

            return false;
        }

        $rest = $prefix % 8;

        if ( $rest === 0 ) {

            return true;
        }

        $mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

        return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
    }
}
