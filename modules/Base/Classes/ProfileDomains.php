<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Whether a recorded page is on a Profile's own domain: a host it names or a
 * subdomain of one.
 *
 * Asked before OWA opens a recorded page_location with an overlay in its
 * fragment, as the heatmap launcher and a module's player do. Anyone can send
 * a beacon with any page_location.
 *
 * Hosts compare as Spam\Hosts compares them: lower-cased, without a port or a
 * leading www.
 */
class ProfileDomains {

    /**
     * The Profiles' domains as comparable hosts. A domain may be stored with
     * a scheme or without one.
     *
     * @param  string[] $site_ids
     * @return string[]
     */
    public static function of( array $site_ids ) {

        $domains = array();

        foreach ( $site_ids as $site_id ) {

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

            $host = Spam\Hosts::of( $site->get( 'domain' ) );

            if ( $host !== '' ) {

                $domains[] = $host;
            }
        }

        return $domains;
    }

    /**
     * Whether a host is one of the domains or a subdomain of one.
     *
     * @param  string   $host
     * @param  string[] $domains from of()
     * @return bool
     */
    public static function isOnDomain( $host, array $domains ) {

        $host = Spam\Hosts::of( $host );

        return $host !== '' && Spam\Hosts::isWithin( $host, $domains );
    }

    /**
     * scheme://host[:port] of a url that is http or https and on one of the
     * domains; '' otherwise.
     *
     * @param  string   $url
     * @param  string[] $domains from of()
     * @return string
     */
    public static function originOf( $url, array $domains ) {

        $parts  = parse_url( trim( (string) $url ) );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );

        if ( ! in_array( $scheme, array( 'http', 'https' ), true )
             || $host === '' || ! self::isOnDomain( $host, $domains ) ) {

            return '';
        }

        return $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
    }
}
