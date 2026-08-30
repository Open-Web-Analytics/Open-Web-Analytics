<?php

namespace OWA\Tests;

/**
 * A recording of how a site's identity is derived, before that derivation is
 * broken on purpose.
 *
 * WHY THIS EXISTS
 * ---------------
 * A site's identity is currently a pure function of its domain:
 *
 *     site_id = md5( $domain )                 <- to be replaced
 *     id      = generateId( $site_id )         <- to be kept
 *
 * That makes two sites for one domain impossible by construction, which is the
 * blocker for giving a tracked website several profiles. The coming change stops
 * DERIVING new identifiers from the domain without REISSUING any that already
 * exist -- every site_id in the wild stays exactly as stored, because every fact
 * row references it.
 *
 * So this harness pins two things that behave differently under that change, and
 * the value of the recording is in keeping them apart:
 *
 *   - domain -> site_id  IS EXPECTED TO CHANGE. It is recorded so the change is
 *     visible and deliberate rather than incidental.
 *
 *   - site_id -> id      MUST NOT CHANGE. The numeric primary key stays a pure
 *     function of the site_id string, which is what lets an existing site keep
 *     its identity when the domain no longer determines it. If this moves,
 *     existing installs lose their sites.
 *
 * Fixed domains rather than generated ones, so the fixture is a stable statement
 * about the algorithm and not about whatever a test happened to make up.
 */
final class SiteIdentityHarness
{
    /** Domains chosen to cover a plain host, a subdomain, a scheme prefix and a port. */
    public const DOMAINS = array(
        'example.com',
        'www.example.com',
        'shop.example.co.uk',
        'http://example.com',
        'example.com:8080',
        'EXAMPLE.com',
    );

    /**
     * The identity a domain produces through the production code path.
     *
     * Goes through the entity rather than reimplementing md5() here, so the
     * recording tracks what the application does rather than what this file
     * believes it does.
     *
     * @return array{site_id: string, id: string}
     */
    public static function derive( string $domain ): array
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $siteId = $site->generateSiteId( $domain );

        return array(
            'site_id' => (string) $siteId,
            'id'      => (string) $site->generateId( $siteId ),
        );
    }

    /**
     * The numeric id a given site_id string produces.
     *
     * Separate from derive() because this is the half that must survive. Taking
     * a site_id directly -- rather than a domain -- is the whole point: it is
     * how an existing site is still found once the domain stops determining
     * anything.
     */
    public static function idForSiteId( string $siteId ): string
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        return (string) $site->generateId( $siteId );
    }

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $derivations = array();

        foreach ( self::DOMAINS as $domain ) {

            $derivations[ $domain ] = self::derive( $domain );
        }

        /*
         * Recorded against literal site_id strings that no domain produces, so
         * this half of the fixture is unaffected by the domain derivation
         * changing. These are the values an existing install depends on.
         */
        $stableIds = array();

        foreach ( self::STABLE_SITE_IDS as $siteId ) {

            $stableIds[ $siteId ] = self::idForSiteId( $siteId );
        }

        return array(
            'domainToSiteId' => $derivations,
            'siteIdToId'     => $stableIds,
        );
    }

    /**
     * site_id strings standing in for identifiers already issued.
     *
     * The first is a real md5 shape, as every existing install holds; the others
     * are shapes a non-derived identifier might take, so the mapping is pinned
     * for both the past and the future.
     */
    public const STABLE_SITE_IDS = array(
        /* The shape every existing install holds: md5 of the domain. */
        '5ababd603b22780302dd8d83498e5172',
        /* The shape new sites will take -- prefixed so a non-derived id is
           recognisable on sight, and legacy ids can be told from new ones
           without consulting the database. */
        'OWA-7f3a91c4e85b402d',
        'OWA-0000000000000000',
        /* Case variants of one identifier. setStringGuid() lowercases before
           hashing, so all three must map to the SAME numeric id -- and MySQL's
           default collation compares them equal too. Pinned because if that
           lowercasing were ever dropped, every existing site's numeric id would
           shift and installs would lose their sites. */
        'OWA-CaseTest',
        'owa-casetest',
        'OwA-cAsEtEsT',
        /* Degenerate, pinned because it is silent: an empty site_id yields an
           empty id rather than a hash, so nothing would flag a site created
           without one. */
        '',
    );

    public static function fixturePath(): string
    {
        return __DIR__ . '/fixtures/site-identity.json';
    }
}
