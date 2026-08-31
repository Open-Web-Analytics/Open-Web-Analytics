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

    function up( $force = false ) {

        /* 1. The new tiers. */
        foreach ( array( 'base.organization', 'base.property' ) as $entityName ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entityName );

            if ( $entity->createTable() === false ) {

                $this->e->notice( "Create table for $entityName failed" );

                return false;
            }
        }

        /* 2. The links from the existing tables into them. */
        \OWA\Core\CoreAPI::entityFactory( 'base.site' )->addColumn( 'property_id' );
        \OWA\Core\CoreAPI::entityFactory( 'base.user' )->addColumn( 'organization_id' );

        /* 3. What the existing rows imply. Decided by plan(), which is pure. */
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_site' );
        $db->selectColumn( 'site_id, domain, name, description' );

        $sites = (array) $db->getAllRows();

        $plan = self::plan( $sites );

        /* 4. One organization. */
        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );

        $organizationId = $organization->generateId( 'organization:default' );

        $organization->load( $organizationId );

        if ( ! $organization->wasPersisted() ) {

            $organization->set( 'id', $organizationId );
            $organization->set( 'name', $plan['organization']['name'] );
            $organization->set( 'creation_date', time() );
            $organization->create();
        }

        /* 5. A property per website, keyed so the id is stable if this re-runs. */
        $propertyIds = array();

        foreach ( $plan['properties'] as $planned ) {

            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

            $propertyId = $property->generateId( $planned['key'] );

            $propertyIds[ $planned['key'] ] = $propertyId;

            $property->load( $propertyId );

            if ( $property->wasPersisted() ) {

                continue;
            }

            $property->set( 'id', $propertyId );
            $property->set( 'organization_id', $organizationId );
            $property->set( 'name', $planned['name'] );
            $property->set( 'domain', $planned['domain'] );
            $property->set( 'description', $planned['description'] );
            $property->set( 'creation_date', time() );
            $property->create();
        }

        /*
         * 6. Point each profile at its property and give it its profile name.
         *
         * The site's own name moved UP to the property, so the profile takes
         * the generated one. That leaves the profile's stored name useless as a
         * picker label on its own, which is why the label shown to a user is
         * composed from both -- see getSitesAllowedForCurrentUser(). This
         * migration must not ship ahead of that composition, or the site picker
         * and the WordPress plugin would both start showing
         * "Observation Profile 1" for every website.
         */
        foreach ( $plan['profiles'] as $planned ) {

            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

            $site->load( $planned['site_id'], 'site_id' );

            if ( ! $site->wasPersisted() ) {

                continue;
            }

            $site->set( 'property_id', $propertyIds[ $planned['property_key'] ] );
            $site->set( 'name', $planned['name'] );
            $site->update();
        }

        /* 7. Every existing account joins the organization. */
        $db->selectFrom( 'owa_user' );
        $db->selectColumn( 'id' );

        foreach ( (array) $db->getAllRows() as $row ) {

            $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );

            $user->load( $row['id'] );

            if ( $user->wasPersisted() ) {

                $user->set( 'organization_id', $organizationId );
                $user->update();
            }
        }

        return true;
    }

    function down() {

        /*
         * Not implemented on purpose. Going back means deciding what to do with
         * profiles created under the hierarchy and with a site's name, which
         * this update overwrote -- neither is recoverable from what remains.
         * The tables can be dropped by hand if an install needs to retry.
         */
        return false;
    }

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
