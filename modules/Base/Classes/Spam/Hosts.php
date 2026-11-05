<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Host comparison for the page_origin rules.
 *
 * Two hosts are the same when they are equal once each is lower-cased, its
 * port is dropped and a leading `www.` is removed: www.example.com,
 * EXAMPLE.com and example.com:8080 are one host. Nothing else is folded, so
 * blog.example.com is not example.com; isWithin() is what counts a subdomain
 * as on a site.
 */
class Hosts {

    /**
     * A URL's or a bare host's comparable host, '' when it has none.
     *
     * A Profile's domain is stored with a scheme or without one, and an alias
     * may be typed either way, so both shapes are read.
     *
     * @param  mixed $value  a URL, or a host with or without a port or path
     * @return string
     */
    public static function of( $value ) {

        $value = trim( (string) $value );

        if ( $value === '' ) {

            return '';
        }

        if ( strpos( $value, '://' ) === false ) {

            // A path alone names no host.
            if ( $value[0] === '/' ) {

                return '';
            }

            $value = 'http://' . $value;
        }

        $host = parse_url( $value, PHP_URL_HOST );

        if ( ! is_string( $host ) || $host === '' ) {

            return '';
        }

        $host = rtrim( strtolower( $host ), '.' );

        return strpos( $host, 'www.' ) === 0 ? substr( $host, 4 ) : $host;
    }

    /**
     * Whether a host is one of these or a subdomain of one: equal, or ending in
     * `.` and the host. Both sides comparable already (of()), so www. is gone
     * from each. The dot is what keeps badexample.com off example.com.
     *
     * @param  string   $host
     * @param  string[] $hosts
     * @return bool
     */
    public static function isWithin( $host, array $hosts ) {

        $host = (string) $host;

        foreach ( $hosts as $own ) {

            $own = (string) $own;

            if ( $own === '' ) {

                continue;
            }

            if ( $host === $own || substr( $host, -( strlen( $own ) + 1 ) ) === '.' . $own ) {

                return true;
            }
        }

        return false;
    }

    /**
     * The hosts a Profile's pages may be on: its domain and its aliases.
     *
     * @param  string $domain   Site::getDomainName(), or the stored value
     * @param  mixed  $aliases  the domain_aliases setting, comma-separated
     * @return string[]  comparable hosts; none when the Profile has no domain
     */
    public static function profileHosts( $domain, $aliases ) {

        $own = self::of( $domain );

        if ( $own === '' ) {

            return array();
        }

        $hosts = array( $own => true );

        foreach ( explode( ',', is_array( $aliases ) ? implode( ',', $aliases ) : (string) $aliases ) as $alias ) {

            $alias = self::of( $alias );

            if ( $alias !== '' ) {

                $hosts[ $alias ] = true;
            }
        }

        return array_keys( $hosts );
    }

    /**
     * The hosts of the Profile a site id names, as it resolves them: its domain
     * (Site::getDomainName()) and its domain_aliases setting, Profile then
     * install. None for a Profile with no domain, or none at all.
     *
     * @param  string $site_id
     * @return string[]
     */
    public static function forSite( $site_id ) {

        if ( (string) $site_id === '' ) {

            return array();
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $site_id ) );

        if ( ! $site->wasPersisted() ) {

            return array();
        }

        return self::profileHosts( $site->getDomainName(), $site->getSiteSetting( 'domain_aliases' ) );
    }
}

?>
