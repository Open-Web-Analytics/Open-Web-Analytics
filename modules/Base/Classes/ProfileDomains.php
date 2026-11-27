<?php
namespace OWA\Module\Base\Classes;

/**
 * Whether a page or an origin is on a Profile's own domain: a host it names
 * or a subdomain of one, over http or https.
 *
 * The one rule for the overlays. The heatmap launcher and a module's player
 * ask it before opening a recorded page_location with an overlay in its
 * fragment, and the REST API asks it of a cross-origin request's Origin
 * (RestApi::matchAllowedOrigin()), so a page an overlay opens on is a page
 * whose fetch is answered. Anyone can send a beacon with any page_location.
 *
 * Only a live web Profile has a domain here: an app Profile's identifier is
 * a bundle id, and an archived Profile has stopped observing.
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

        $sites = [];

        foreach ( $site_ids as $site_id ) {

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

            $sites[] = [
                'domain'        => $site->get( 'domain' ),
                'stream_type'   => $site->get( 'stream_type' ),
                'archived_date' => $site->get( 'archived_date' ),
            ];
        }

        return self::ofSites( $sites );
    }

    /**
     * The domains of the live web Profiles among these site rows, as
     * comparable hosts.
     *
     * @param  array<mixed> $sites site rows, as CoreAPI::getSitesList() returns them;
     *                             an element that is not an array is skipped
     * @return string[]
     */
    public static function ofSites( array $sites ) {

        $domains = [];

        foreach ( $sites as $site ) {

            if ( ! is_array( $site )
                 || \OWA\Module\Base\Entity\Site::streamTypeOf( $site ) !== \OWA\Module\Base\Entity\Site::STREAM_WEB
                 || ! empty( $site['archived_date'] ) ) {

                continue;
            }

            $host = Spam\Hosts::of( is_string( $site['domain'] ?? null ) ? $site['domain'] : '' );

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
     * A url with a backslash or a control character is ''. A browser reads
     * those differently from parse_url(): it takes a backslash for a slash
     * and drops tabs and newlines, so the host it opens is not the one
     * checked here.
     *
     * @param  string   $url
     * @param  string[] $domains from of()
     * @return string
     */
    public static function originOf( $url, array $domains ) {

        $url = trim( (string) $url );

        if ( preg_match( '/[\\\\\x00-\x1f\x7f]/', $url ) ) {

            return '';
        }

        $parts  = parse_url( $url );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true )
             || $host === '' || ! self::isOnDomain( $host, $domains ) ) {

            return '';
        }

        return $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
    }
}
