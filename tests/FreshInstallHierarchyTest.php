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

    /**
     * Renaming the only Organization must not make it un-findable.
     *
     * This looked the default up by matching DEFAULT_ORGANIZATION_NAME, and
     * there is a screen for renaming an Organization -- so the name was a key
     * that the product invites you to change. Renaming it made the next call
     * mint a SECOND Organization: the rename appeared to revert, and every
     * Property added afterwards attached to the new empty row.
     *
     * Asked in a FRESH PROCESS on purpose. The entity cache is per-process and
     * getByColumn() populates it, so asking again in this one is answered from
     * the lookup made before the rename -- the same masking that let this ship.
     */
    public function testRenamingTheOrganizationDoesNotMintASecondOne(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

        $id = $sm->ensureOrganization();

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $organization->load( $id );

        $was = $organization->get( 'name' );

        $this->assertNotEmpty( $was, 'no Organization to rename' );

        $organization->set( 'name', 'Renamed ' . substr( md5( uniqid( '', true ) ), 0, 6 ) );
        $organization->update();

        try {
            $before = $this->organizationCount();
            $found  = $this->askInAFreshProcess();

            $this->assertSame( (string) $id, $found,
                'The renamed Organization was not found, so a second one was created and '
                . 'the rename looks like it reverted.' );

            $this->assertSame( $before, $this->organizationCount(),
                'A second Organization row was created.' );

        } finally {
            $organization->set( 'name', $was );
            $organization->update();
        }
    }

    private function organizationCount(): int
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        return count( (array) $db->get_results(
            'SELECT id FROM ' . $entity->getTableName() ) );
    }

    private function askInAFreshProcess(): string
    {
        $descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );

        $proc = proc_open(
            escapeshellarg( PHP_BINARY ) . ' '
            . escapeshellarg( __DIR__ . '/fixtures/organization_lookup_probe.php' ),
            $descriptors, $pipes, dirname( __DIR__ ) );

        $this->assertIsResource( $proc, 'could not spawn the organization probe' );

        $stdout = (string) stream_get_contents( $pipes[1] );
        $stderr = (string) stream_get_contents( $pipes[2] );

        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $proc );

        $stdout = trim( $stdout );

        /*
         * An empty answer is a failure in its own right, not a broken probe:
         * ensureOrganization() returning nothing means every Property created
         * afterwards gets a null organization_id.
         */
        $this->assertNotSame( '', $stdout,
            'ensureOrganization() answered with nothing in a fresh process, so Properties '
            . 'created after this point would have no Organization. stderr was: ' . $stderr );

        return $stdout;
    }

    /**
     * A Property whose domain has moved must not block the next one.
     *
     * ensurePropertyFor() derives the id from 'domain:' . $domain, and a
     * Property's domain is editable on the Property Details screen. Move one
     * from a.com to b.com and its id stays derived from a.com -- so the next
     * Property created for a.com derives an id that is already taken, the
     * insert fails on the primary key, and the caller gets null. The Profile
     * being added is then created with no Property at all.
     */
    public function testAMovedDomainDoesNotCollideWithTheNextProperty(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        $sm     = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );
        $domain = 'collide-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.example';

        $first = $sm->ensurePropertyFor( $domain, 'First' );

        $this->assertNotEmpty( $first );

        $created = array( $first );

        try {
            /* Move it, exactly as the Property Details screen does. */
            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
            $property->load( $first );
            $property->set( 'domain', 'moved-' . $domain );
            $property->update();

            $second = $sm->ensurePropertyFor( $domain, 'Second' );

            $this->assertNotEmpty( $second,
                'The second Property could not be created, so the Profile being added '
                . 'would have had no Property.' );

            $created[] = $second;

            $this->assertNotSame( $first, $second,
                'Both Properties were given the same id.' );

        } finally {

            foreach ( $created as $id ) {
                $p = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
                $p->delete( $id );
            }
        }
    }

    /**
     * The default id must not be derived from the name.
     *
     * Two reasons. It has to match what Update021 mints, so a migrated install
     * and a fresh one are indistinguishable -- and an id derived from a
     * renameable column is one ContentDerivedIdCoverageTest has to treat as
     * needing migration, because it cannot tell that intent apart from a
     * dimension's.
     */
    public function testTheDefaultOrganizationIdIsNotAHashOfItsName(): void
    {
        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );

        $this->assertNotSame(
            (string) $organization->generateId(
                \OWA\Module\Base\Classes\SiteManager::DEFAULT_ORGANIZATION_NAME ),
            (string) $organization->generateId(
                \OWA\Module\Base\Classes\SiteManager::DEFAULT_ORGANIZATION_KEY ),
            'The two keys collide, so this test cannot tell them apart.' );

        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Classes/SiteManager.php' );

        $this->assertStringNotContainsString(
            'generateId( self::DEFAULT_ORGANIZATION_NAME )', $src,
            'The id is still derived from the name.' );

        /* The value is the contract with Update021, which cannot reach in here. */
        $migration = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Update/Update021.php' );

        $this->assertStringContainsString(
            "'" . \OWA\Module\Base\Classes\SiteManager::DEFAULT_ORGANIZATION_KEY . "'", $migration,
            'The migration and the installer derive the default Organization id from '
            . 'different keys, so a migrated install and a fresh one disagree.' );
    }
}
