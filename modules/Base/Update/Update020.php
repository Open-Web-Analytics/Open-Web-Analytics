<?php

namespace OWA\Module\Base\Update;

/**
 * Introduces Organization / Property / Observation Profile.
 *
 * Creates the two new tables and reshapes what exists into the hierarchy:
 * one organization, one property per website, one profile per existing site.
 *
 * THE PLAN IS SEPARATE FROM THE WRITING. plan() is pure -- it takes the rows
 * and returns the structure they imply, touching nothing -- so the decisions
 * worth arguing about can be tested without a database, and are the same
 * decisions whether they run here or in the installer. up() then applies what
 * plan() decided.
 *
 * The rule the whole thing rests on: an existing site keeps its site_id
 * unchanged. Every fact row references it, so a migration that reissues
 * identifiers is a migration that orphans data.
 */
class Update020 extends \OWA\Core\Update {

    var $schema_version = 20;
    var $is_cli_mode_required = false;

    /** What a migrated or freshly installed instance calls its organization. */
    const DEFAULT_ORGANIZATION_NAME = 'My Organization';

    /** Profiles are numbered within their property, from one. */
    const PROFILE_NAME_PREFIX = 'Observation Profile ';

    /**
     * The hierarchy a set of existing sites implies.
     *
     * @param array $sites rows with at least site_id, and optionally domain,
     *                     name and description
     * @return array{organization: array, properties: array, profiles: array}
     */
    public static function plan( array $sites ) {

        $properties = array();
        $profiles   = array();

        foreach ( $sites as $site ) {

            $siteId = isset( $site['site_id'] ) ? trim( (string) $site['site_id'] ) : '';

            /*
             * A site with no identifier cannot become a profile: the identifier
             * is what every fact row references, so there is nothing to carry
             * forward and nothing to attach the rows to. Skipped rather than
             * given a new one, which would silently invent a site.
             */
            if ( $siteId === '' ) {

                continue;
            }

            $domain = self::normaliseDomain( isset( $site['domain'] ) ? $site['domain'] : '' );

            /*
             * The domain is the property key, which is why it is normalised
             * first: http://x and https://x are one website, and only looked
             * like two because a site's identity used to be md5( domain ). A
             * site with no domain gets its own property keyed by its
             * identifier, since nothing says it is the same website as any
             * other.
             */
            $key = $domain !== '' ? 'domain:' . $domain : 'site:' . $siteId;

            if ( ! isset( $properties[ $key ] ) ) {

                $name = isset( $site['name'] ) ? trim( (string) $site['name'] ) : '';

                $properties[ $key ] = array(
                    'key'         => $key,
                    /* Named for the site that introduced it; the domain is the
                     * fallback because an unnamed property is unusable in a
                     * picker, and the domain is the only other thing known. */
                    'name'        => $name !== '' ? $name : ( $domain !== '' ? $domain : $siteId ),
                    'domain'      => $domain,
                    'description' => isset( $site['description'] ) ? (string) $site['description'] : '',
                );

                $properties[ $key ]['profile_count'] = 0;
            }

            $properties[ $key ]['profile_count']++;

            $profiles[] = array(
                /* Unchanged. This is the whole safety rule of the migration. */
                'site_id'      => $siteId,
                'property_key' => $key,
                'name'         => self::PROFILE_NAME_PREFIX . $properties[ $key ]['profile_count'],
            );
        }

        return array(
            'organization' => array( 'name' => self::DEFAULT_ORGANIZATION_NAME ),
            'properties'   => array_values( $properties ),
            'profiles'     => $profiles,
        );
    }

    /**
     * A domain reduced to the host, so that values differing only by scheme,
     * case or a trailing slash group together.
     */
    public static function normaliseDomain( $domain ) {

        $domain = trim( (string) $domain );

        if ( $domain === '' ) {

            return '';
        }

        $separator = strpos( $domain, '://' );

        if ( $separator !== false ) {

            $domain = substr( $domain, $separator + 3 );
        }

        $domain = rtrim( trim( $domain ), '/' );

        /*
         * Lower-cased because hosts are case-insensitive, and two rows differing
         * only in case are one website however they were typed.
         */
        return strtolower( $domain );
    }
}

?>
