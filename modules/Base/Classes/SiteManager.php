<?php
namespace OWA\Module\Base\Classes;

/**
 * Site Manager Class
 * 
 * handels the common tasks associated with creating and manipulating tracked sites
 */

class SiteManager extends \OWA\Core\Base {

    /**
     * Ensure a site exists for a domain, and return it if this call created it.
     *
     * Calling this twice for one domain creates one site, and the second call
     * returns nothing. Identity is minted, not derived from the domain, so the
     * domain is looked up explicitly.
     *
     * For a second site on a domain that already has one -- a website measured
     * by more than one profile -- use createSite().
     *
     * @param  string $domain
     * @param  string $name
     * @param  string $description
     * @param  string $site_family
     * @param  string $site_id      a pinned identifier; one is minted when empty
     * @return \OWA\Module\Base\Entity\Site|void nothing when the domain has a site, or the site was not created
     */
    public function createNewSite( $domain, $name = '', $description = '', $site_family = '', $site_id = '' ) {

        $domain = self::domainOnly( $domain );

        $existing = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $existing->load( $domain, 'domain' );

        if ( $existing->wasPersisted() ) {

            \OWA\Core\CoreAPI::debug(
                "Cannot create new site. A site for domain $domain already exists." );

            return;
        }

        return $this->createSite( $domain, $name, $description, $site_family, $site_id );
    }

    /*
     * The hierarchy defaults. 1.13's Update021 built the hierarchy of every
     * upgraded installation with these same values, so a fresh install and an
     * upgraded one are indistinguishable: "Observation Profile 1" means the
     * same thing on both.
     */
    const DEFAULT_ORGANIZATION_NAME = 'My Organization';

    /*
     * The key the default Organization's id is derived from -- a fixed string,
     * not the name. The value 1.13's Update021 used, so an install that
     * migrated and one installed fresh mint the same id.
     */
    const DEFAULT_ORGANIZATION_KEY  = 'organization:default';
    const PROFILE_NAME_PREFIX       = 'Observation Profile ';

    /**
     * The id the install's first Organization gets.
     *
     * A database OWA created for this install is named for its Organization
     * (InstallDatabase): the Organization takes that id, so the two belong
     * together from the start. Otherwise the id is derived from
     * DEFAULT_ORGANIZATION_KEY.
     *
     * @param string $db_name
     * @return string
     */
    public static function newOrganizationId( $db_name ) {

        return \OWA\Module\Base\Classes\InstallDatabase::organizationIdFrom( $db_name )
            ?? \OWA\Core\CoreAPI::entityFactory( 'base.organization' )->generateId( self::DEFAULT_ORGANIZATION_KEY );
    }

    /**
     * The Organization every Property hangs from, created on first need.
     *
     * There is exactly one until someone builds a screen to make more, which is
     * why this looks one up rather than taking a name. Installing does not have
     * to know about it, and neither does adding a site.
     *
     * @return mixed|void organization id; nothing when it could not be created
     */
    public function ensureOrganization() {

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );

        /*
         * Found by EXISTENCE, not by name: there is a screen for renaming an
         * Organization, and a name match would mint a second one after a rename.
         *
         * There is exactly one Organization, so the first row IS the answer -- and
         * looking for it this way is indifferent to how its id was derived, which
         * differs between installs.
         */
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $organization->getTableName() );
        $db->selectColumn( 'id' );

        $existing = (array) $db->getOneRow();

        if ( ! empty( $existing['id'] ) ) {

            return $existing['id'];
        }

        $id = self::newOrganizationId( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'db_name' ) );

        $organization->set( 'id', $id );
        $organization->set( 'name', self::DEFAULT_ORGANIZATION_NAME );
        $organization->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        if ( $organization->create() ) {

            return $id;
        }
    }

    /**
     * The Property a new Profile belongs to, found by domain among the
     * Properties that are not archived, or created.
     *
     * Keyed on the domain, as 1.13's Update021 grouped them: two Profiles of one
     * website share a Property, and that is what makes adding a second way of
     * tracking a site produce a second Profile rather than a second website.
     * Domains are compared by host (livePropertyWithHost()), so www.example.com
     * finds the Property of example.com: only one live Property may have a
     * host.
     *
     * The NAME given here lands on the Property, not the Profile. That is the
     * migration's rule too -- the human name describes the website, and the
     * Profile is only one way of watching it.
     *
     * @param  mixed  $domain
     * @param  string $name
     * @param  string $description
     * @return int|string|null property id
     */
    public function ensurePropertyFor( $domain, $name = '', $description = '' ) {

        $domain = self::normaliseDomain( $domain );

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( $domain !== '' ) {

            $existing = self::livePropertyWithHost( $domain );

            if ( $existing ) {

                return (string) $existing['id'];
            }
        }

        /*
         * The id encodes nothing about the Property.
         *
         * A Property is an artificial grouping. Its domain is OPTIONAL -- an app has
         * none -- and editable where it exists, so it is not an identity: an id
         * derived from it would collide once a Property moved to another domain and a
         * new one was made for the old.
         *
         * Grouping happens by the domain's host, looked up above. PropertyEdit mints
         * ids the same way.
         */
        $id = \OWA\Core\Lib::randomId( \OWA\Core\Lib::useNarrowGuid() );

        $property->set( 'id', $id );
        $property->set( 'organization_id', $this->ensureOrganization() );
        $property->set( 'name', $name !== '' ? $name : ( $domain !== '' ? $domain : 'Untitled' ) );
        $property->set( 'domain', $domain );
        $property->set( 'description', $description );
        $property->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        if ( $property->create() ) {

            return $id;
        }

        return null;
    }

    /**
     * The live Property ensurePropertyFor() would put a domain's Profile
     * under; '' when it would make one.
     *
     * @param  string $domain
     * @return string
     */
    public static function propertyIdForDomain( $domain ) {

        $row = self::livePropertyWithHost( self::normaliseDomain( $domain ) );

        return $row ? (string) $row['id'] : '';
    }

    /**
     * The live web Property that has this domain's host, oldest first; null
     * when none has, or the domain has no host. One at most once Update102
     * has run (Entity\Property::LIVE_HOST_INDEX).
     *
     * @param  mixed      $domain
     * @param  int|string $except  a Property id to pass over: the one being edited
     * @return array<string, mixed>|null its row
     */
    public static function livePropertyWithHost( $domain, $except = '' ) {

        $host = Spam\Hosts::of( (string) $domain );

        if ( $host === '' ) {

            return null;
        }

        foreach ( self::propertiesWithHost( $host ) as $row ) {

            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
            $property->setProperties( $row );

            if ( (string) $row['id'] !== (string) $except && $property->liveHost() !== null ) {

                return $row;
            }
        }

        return null;
    }

    /**
     * Every Property, live or archived, whose domain has this domain's host:
     * oldest first. The table holds a few rows, so they are compared here,
     * with the same parse as the rest of OWA.
     *
     * @param  mixed $domain
     * @return list<array<string, mixed>> rows
     */
    public static function propertiesWithHost( $domain ) {

        $host = Spam\Hosts::of( (string) $domain );

        if ( $host === '' ) {

            return [];
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName() );
        $db->selectColumn( '*' );
        $db->orderBy( 'creation_date', 'ASC' );
        $db->orderBy( 'id', 'ASC' );

        $out = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( Spam\Hosts::of( (string) ( $row['domain'] ?? '' ) ) === $host ) {

                $out[] = (array) $row;
            }
        }

        return $out;
    }

    /**
     * Why a live web Property may not have this domain: the live Property
     * that has its host already. '' when none has.
     *
     * @param  mixed      $domain
     * @param  int|string $except  the Property being edited
     * @return string
     */
    public static function domainTakenMessage( $domain, $except = '' ) {

        $row = self::livePropertyWithHost( $domain, $except );

        if ( ! $row ) {

            return '';
        }

        return sprintf( \OWA\Core\CoreAPI::t( 'The Property %1$s (id %2$s) already has the domain %3$s. Only one live Property may have a domain.' ),
            (string) $row['name'], (string) $row['id'], Spam\Hosts::of( (string) $domain ) );
    }

    /**
     * "Observation Profile N", numbered within its Property.
     *
     * Counts what is already there rather than tracking a sequence, so it is
     * correct after a migration, after a delete, and on an install whose
     * Profiles arrived by several different routes.
     *
     * @param  int|string $property_id
     * @return string
     */
    public function nextProfileName( $property_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'property_id', $property_id );

        $existing = count( (array) $db->getAllRows() );

        return self::PROFILE_NAME_PREFIX . ( $existing + 1 );
    }

    /**
     * Create a site for a domain, whether or not one already exists: a website
     * may have several Profiles measuring it.
     *
     * @param mixed  $domain
     * @param string $name
     * @param string $description
     * @param string $site_family
     * @param string $site_id      a pinned identifier; one is minted when empty
     * @param string $property_id  Put the Profile in THIS Property, instead of
     *                             deriving one from the domain. This is how a
     *                             second Profile is added to a website, and the
     *                             only way to add one to a Property that has no
     *                             domain to be found by.
     * @param string $stream_type  a Site::STREAM_* value
     * @param string $app_id       an app stream's id
     * @return \OWA\Module\Base\Entity\Site|void nothing when it was not created
     */
    public function createSite( $domain, $name = '', $description = '', $site_family = '', $site_id = '', $property_id = '', $stream_type = '', $app_id = '' ) {

        $domain = self::domainOnly( $domain );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        if ( $site_id ) {

            /*
             * A pinned identifier. Imports, restores and test fixtures need the
             * identifier to be known in advance -- a fixture has to put the same
             * value in a tracking payload that it puts in the database, and
             * cannot read back something it has not created yet. InstallManager
             * has always accepted one for the same reason.
             */
            $id = $site->generateId( $site_id );

        } else {

            list( $site_id, $id ) = $this->mintUnusedIdentity( $site );
        }

        if ( ! $site_id ) {

            \OWA\Core\CoreAPI::debug( "Could not mint an unused site identity for $domain." );

            return;
        }

        if ( ! $name ) {
            $name = $domain;
        }

        /*
         * A Property named by the caller wins over one derived from the domain: a
         * second Profile for a website needs no retyped domain, and a Property with
         * no domain (an app) can be chosen.
         */
        if ( $property_id ) {

            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
            $property->load( $property_id );

            if ( ! $property->wasPersisted() ) {

                \OWA\Core\CoreAPI::debug( "No such property: $property_id." );

                return;
            }

        } else {

            /*
             * The name the caller gave describes the WEBSITE, so it goes on the
             * Property; the Profile takes the generated "Observation Profile N".
             * That is the split 1.13's Update021 applied to existing sites, and
             * the two have to agree -- a fresh install and a migrated one must
             * not be distinguishable.
             */
            $property_id = $this->ensurePropertyFor( $domain, $name, $description );
        }

        $site->set( 'id', $id );
        $site->set( 'site_id', $site_id );
        $site->set( 'property_id', $property_id );
        $site->set( 'name', $property_id ? $this->nextProfileName( $property_id ) : $name );

        /*
         * The identifier belongs to the PROFILE, and which one it needs depends
         * on what it observes.
         *
         * A web Profile is known by its domain -- read off this row by CORS
         * origin matching, by makeUrlCanonical's alias rewrite, and by
         * GET /v1/sites, which the WordPress plugin labels its picker with. An
         * app Profile is known by a bundle id or package name and has no domain
         * at all, which is the case that could not be expressed before.
         */
        $stream_type = $stream_type ?: \OWA\Module\Base\Entity\Site::STREAM_WEB;

        $site->set( 'stream_type', $stream_type );

        if ( $stream_type === \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

            $site->set( 'domain', $domain );

        } else {

            $site->set( 'app_id', $app_id );
        }
        $site->set( 'description', $description );
        $site->set( 'site_family', $site_family );

        if ( $site->create() ) {

            return $site;
        }
    }

    /**
     * A minted site_id whose derived numeric key is not already taken.
     *
     * Worth the loop rather than trusting the width: a collision would hand
     * back a DIFFERENT site than the caller asked to create, silently. Checking
     * costs one indexed read on a table with a handful of rows.
     *
     * @param  \OWA\Module\Base\Entity\Site $site
     * @param  int $attempts
     * @return array{0: string, 1: string} the site_id and its numeric key, or
     *                                     two empty strings if none was free
     */
    private function mintUnusedIdentity( $site, $attempts = 5 ) {

        for ( $i = 0; $i < $attempts; $i++ ) {

            $site_id = $site->mintSiteId();
            $id      = $site->generateId( $site_id );

            $taken = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $taken->load( $id );

            if ( ! $taken->wasPersisted() ) {

                return [ $site_id, $id ];
            }
        }

        return [ '', '' ];
    }

    /**
     * A Profile or Property domain as it is stored: host[:port], with no
     * scheme and no trailing slash.
     *
     * Every save of a domain goes through this, and Update088 applies it to
     * the rows saved before it. A leading http:// or https:// (any case) and
     * trailing slashes are removed, and surrounding space; nothing else, so a
     * port or a path stays. Repeated until nothing changes, so a value it
     * returns comes back unchanged.
     *
     * @param mixed $domain
     * @return string
     */
    public static function domainOnly( $domain ) {

        $domain = (string) $domain;

        do {

            $before = $domain;
            $domain = rtrim( trim( (string) preg_replace( '#^https?://#i', '', trim( $domain ) ) ), '/' );

        } while ( $domain !== $before );

        return $domain;
    }

    /**
     * A domain reduced to the host, so that values differing only by scheme,
     * case or a trailing slash group together.
     *
     * @param  mixed $domain
     * @return string
     */
    public static function normaliseDomain( $domain ) {

        // Lower-cased because hosts are case-insensitive, and two rows differing
        // only in case are one website however they were typed.
        return strtolower( self::domainOnly( $domain ) );
    }
}
