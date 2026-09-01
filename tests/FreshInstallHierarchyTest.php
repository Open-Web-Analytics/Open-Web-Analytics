<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A freshly installed OWA and a migrated one must be indistinguishable.
 *
 * Update021 gives an existing install an Organization, a Property per website
 * and "Observation Profile N" beneath it. Installing created the hierarchy
 * TABLES and nothing in them, so a brand new install had its one site sitting
 * under "Unassigned" in both the site selector and the Property roster, while
 * an install of the same age that had been migrated looked correct.
 *
 * The values are duplicated between SiteManager and Update021 deliberately -- a
 * migration has to stay self-contained -- so this pins that they agree. If they
 * drift, "Observation Profile 1" means one thing on one install and something
 * else on another.
 */
final class FreshInstallHierarchyTest extends TestCase
{
    public function testTheDefaultsMatchWhatTheMigrationUses(): void
    {
        $this->assertSame(
            \OWA\Module\Base\Update\Update021::DEFAULT_ORGANIZATION_NAME,
            \OWA\Module\Base\Classes\SiteManager::DEFAULT_ORGANIZATION_NAME,
            'A fresh install would name its Organization differently from a migrated one.' );

        $this->assertSame(
            \OWA\Module\Base\Update\Update021::PROFILE_NAME_PREFIX,
            \OWA\Module\Base\Classes\SiteManager::PROFILE_NAME_PREFIX,
            'A fresh install would name its Profiles differently from a migrated one.' );
    }

    public function testCreatingASiteGivesItAPropertyAndANumberedProfile(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $sm     = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );
        $domain = 'fresh-' . uniqid() . '.example.com';

        $site = $sm->createSite( $domain, 'My Website', 'a description' );

        $this->assertNotEmpty( $site, 'The site was not created at all.' );

        try {

            $property_id = $site->get( 'property_id' );

            $this->assertNotEmpty(
                $property_id,
                'A new site has no Property, so it lands under "Unassigned" in the selector.' );

            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
            $property->load( $property_id );

            $this->assertSame(
                'My Website', $property->get( 'name' ),
                'The name the caller gave describes the WEBSITE and belongs on the Property.' );

            $this->assertSame(
                'Observation Profile 1', $site->get( 'name' ),
                'The Profile takes the generated name, as it does after a migration.' );

            $this->assertNotEmpty(
                $property->get( 'organization_id' ),
                'A Property with no Organization leaves the top tier empty.' );

            /* A second way of watching ONE website is a second Profile, not a
               second website. This is the case the hierarchy exists for. */
            $second = $sm->createSite( $domain, 'My Website' );

            try {

                $this->assertSame(
                    $property_id, $second->get( 'property_id' ),
                    'The same domain produced a second Property instead of a second Profile.' );

                $this->assertSame(
                    'Observation Profile 2', $second->get( 'name' ),
                    'Profiles are numbered within their Property.' );

            } finally {
                $second->delete( $second->get( 'id' ) );
            }

        } finally {

            $site->delete( $site->get( 'id' ) );

            if ( ! empty( $property_id ) ) {
                $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
                $property->delete( $property_id );
            }
        }
    }

    /** The Organization is found, not duplicated, on the second call. */
    public function testTheOrganizationIsCreatedOnceAndReused(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

        $this->assertSame(
            $sm->ensureOrganization(), $sm->ensureOrganization(),
            'Each call minted a new Organization, so every Property would sit in its own.' );
    }
}
