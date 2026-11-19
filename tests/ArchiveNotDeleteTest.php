<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/DeclaresProbeSettings.php';

/**
 * Removing a Profile or a Property archives it rather than destroying it.
 *
 * A delete would leave access grants, scoped settings, and every fact row ever
 * collected under that site_id behind as orphans a re-minted identifier could
 * inherit, and it could not be undone.
 *
 * Archiving has to be airtight in one direction: an archived Profile must stop
 * being everywhere. Missing a single read path is how it reappears -- still
 * listed, still reportable, or worst of all still collecting, since the tag is
 * on the page and does not know anything happened.
 */
final class ArchiveNotDeleteTest extends TestCase
{
    use DeclaresProbeSettings;

    private string $siteId = '';
    private string $propertyId = '';
    private array $archived = [];
    private array $properties = [];

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'site_id, property_id, archived_date' );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( ! empty( $row['property_id'] ) && empty( $row['archived_date'] ) ) {

                $this->siteId     = $row['site_id'];
                $this->propertyId = $row['property_id'];
                break;
            }
        }
    }

    protected function tearDown(): void
    {
        $this->forgetProbes();

        foreach ( $this->archived as $entry ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entry[0] );
            $entity->load( $entry[1] );

            if ( $entity->wasPersisted() ) {

                // 0, not '': setting '' on a numeric column is treated as
                // "no value given" and skipped, so the row would stay archived
                // and every later test in this run would see a dead install.
                $entity->set( 'archived_date', 0 );
                $entity->update();
            }
        }

        $this->archived = [];

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName();

        foreach ( $this->properties as $id ) {

            \OWA\Core\CoreAPI::dbSingleton()->query( "DELETE FROM $table WHERE id = ?", [ $id ] );
        }

        $this->properties = [];

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    private function skipUnlessParented(): void
    {
        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a live Profile with a Property.' );
        }
    }

    /**
     * A Profile's row, loaded by its row id.
     *
     * Not by site_id: an entity is cached under the column it was loaded by,
     * and update() clears the id entry only, so a reload by site_id after an
     * archive read the stale copy. Not by generateId( $siteId ) either, which
     * these tests used: row ids are not derived from site ids, so it found
     * nothing, and update() on that empty entity wrote every row.
     */
    private function site( string $siteId ): \OWA\Core\Entity
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'site_id', $siteId );
        $row = $db->getOneRow();

        $this->assertNotEmpty( $row['id'] ?? null, "no row for site $siteId" );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $row['id'] );

        return $site;
    }

    /** Archive a Profile and remember to put it back. */
    private function archiveProfile( string $siteId ): void
    {
        $site = $this->site( $siteId );

        $this->archived[] = [ 'base.site', $site->get( 'id' ) ];

        $site->set( 'archived_date', 1756000000 );
        $site->update();

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    /**
     * The live Profiles of a Property, each remembered to be put back.
     *
     * @return list<string> owa_site row ids
     */
    private function rememberLiveProfiles( string $propertyId ): array
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'id, archived_date' );
        $db->where( 'property_id', $propertyId );

        $ids = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( empty( $row['archived_date'] ) ) {

                $this->archived[] = [ 'base.site', $row['id'] ];
                $ids[]            = (string) $row['id'];
            }
        }

        return $ids;
    }

    /** A Property with no Profiles, removed in tearDown. */
    private function newProperty( ?int $archivedDate ): string
    {
        $id = (string) \OWA\Core\Lib::randomId( \OWA\Core\Lib::useNarrowGuid() );

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->set( 'id', $id );
        $property->set( 'organization_id', ( new \OWA\Module\Base\Classes\SiteManager() )->ensureOrganization() );
        $property->set( 'name', 'owatest empty property ' . $id );
        $property->set( 'domain', 'empty-' . $id . '.owatest.example.com' );
        $property->set( 'creation_date', time() );

        if ( $archivedDate !== null ) {

            $property->set( 'archived_date', $archivedDate );
        }

        $property->create();

        $this->properties[] = $id;

        return $id;
    }

    /** A controller exposing the site list and the site control's hierarchy. */
    private function controller(): object
    {
        return new class( [] ) extends \OWA\Core\Controller {

            public function sites() { return $this->getSitesAllowedForCurrentUser(); }

            public function hierarchy( $sites ) { return $this->getSiteHierarchy( $sites ); }
        };
    }

    private function asRole( string $role, callable $run ): void
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $was  = $user->getRole();

        $user->setRole( $role );

        try {
            $run();
        } finally {
            $user->setRole( $was );
        }
    }

    /** @return list<string> the site_ids listed under a Property in the site control */
    private function profilesUnder( array $hierarchy, string $propertyId ): array
    {
        foreach ( $hierarchy['properties'] as $property ) {

            if ( (string) $property['id'] === $propertyId ) {

                return array_column( $property['profiles'], 'site_id' );
            }
        }

        return [];
    }

    /**
     * The one that matters most.
     *
     * The tracking tag stays on the page after a Profile is removed and keeps
     * firing. Anything short of rejecting here leaves a site the owner believes
     * they deleted quietly recording -- which is both a surprise and, for a site
     * deleted FOR a privacy reason, the opposite of what was asked for.
     */
    public function testAnArchivedProfileStopsBeingRegistered(): void
    {
        $this->skipUnlessParented();

        $this->assertTrue(
            \OWA\Core\CoreAPI::isSiteRegistered( $this->siteId ),
            'Needs a registered Profile for this test to mean anything.' );

        $this->archiveProfile( $this->siteId );

        $this->assertFalse(
            \OWA\Core\CoreAPI::isSiteRegistered( $this->siteId ),
            'An archived Profile still accepts events, so a deleted site keeps collecting.' );
    }

    /** Restoring it puts collection back, which is the point of archiving. */
    public function testClearingTheDateRestoresIt(): void
    {
        $this->skipUnlessParented();

        $this->archiveProfile( $this->siteId );
        $this->assertFalse( \OWA\Core\CoreAPI::isSiteRegistered( $this->siteId ) );

        $site = $this->site( $this->siteId );
        $site->set( 'archived_date', 0 );
        $site->update();

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $this->assertTrue(
            \OWA\Core\CoreAPI::isSiteRegistered( $this->siteId ),
            'A restored Profile did not start collecting again, so archiving is one-way '
            . 'and the recoverability it exists for is not there.' );
    }

    /** Nothing that hung off the Profile is destroyed -- that IS the feature. */
    public function testArchivingDestroysNothing(): void
    {
        $this->skipUnlessParented();

        $probe = 'archive_probe_' . substr( md5( uniqid( '', true ) ), 0, 8 );

        // Declared, as only a declared setting may be stored at a scope.
        $this->declareProbe( 'base', $probe,
            [ 'storable' => true, 'scopes' => [ 'install', 'profile' ] ] );

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $probe, 'still here' );

        try {
            $this->archiveProfile( $this->siteId );

            $site = $this->site( $this->siteId );

            $this->assertTrue( $site->wasPersisted(),
                'The row was deleted, so there is nothing to restore.' );

            $this->assertSame( 'still here',
                \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', $this->siteId, 'base', $probe ),
                'The scoped settings went with it, so a restore would come back misconfigured.' );

        } finally {
            \OWA\Core\CoreAPI::clearScopedSetting( 'profile', $this->siteId, 'base', $probe );
        }
    }

    /** isArchived() reads the date, and an un-archived row is not archived. */
    public function testTheFlagIsTheDate(): void
    {
        $this->skipUnlessParented();

        $site = $this->site( $this->siteId );

        $this->assertFalse( $site->isArchived(),
            'A live Profile reads as archived, which would hide every site on the install.' );

        $this->archiveProfile( $this->siteId );

        $site = $this->site( $this->siteId );

        $this->assertTrue( $site->isArchived() );
    }

    /** A date, not a boolean, so a restore can say when this happened. */
    public function testTheColumnIsADateSoItCarriesWhen(): void
    {
        $this->skipUnlessParented();

        $this->archiveProfile( $this->siteId );

        $site = $this->site( $this->siteId );

        $this->assertSame( 1756000000, (int) $site->get( 'archived_date' ),
            'The stamp did not survive, so nothing can say when this was archived.' );
    }

    /**
     * The column holds THREE values, and every read has to cope.
     *
     * NULL for a row never archived, a stamp for one that is, and 0 for one
     * that was restored -- 0 rather than NULL because setting '' on a numeric
     * column is treated by the entity layer as "no value given" and skipped, so
     * a restore cannot put NULL back.
     *
     * Which makes `IS NULL` the wrong test and empty() the right one -- the same
     * shape as a boolean column holding 1, 0 and NULL, where `= 0` silently
     * excludes every NULL row.
     */
    public function testARestoredRowReadsAsLiveEvenThoughItIsNotNull(): void
    {
        $this->skipUnlessParented();

        $this->archiveProfile( $this->siteId );

        $site = $this->site( $this->siteId );
        $site->set( 'archived_date', 0 );
        $site->update();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'archived_date' );
        $db->where( 'site_id', $this->siteId );

        $stored = $db->getOneRow();

        $this->assertNotNull( $stored['archived_date'],
            'A restore wrote NULL after all -- if the entity layer can do that, the '
            . 'three-value warning in the comments is wrong and should go.' );

        $fresh = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $fresh = $this->site( $this->siteId );

        $this->assertFalse( $fresh->isArchived(),
            'A restored Profile reads as archived, so restoring does not work.' );
    }

    /**
     * A restored row (archived_date 0) is listed again by every reader, which an
     * IS NULL test would get wrong: the admin site list, the site control, the
     * Property's live-Profile count and the collection check.
     */
    public function testARestoredProfileIsListedEverywhereAgain(): void
    {
        $this->skipUnlessParented();

        $this->archiveProfile( $this->siteId );

        $without = \OWA\Module\Base\Controller\PropertyProfile::countLiveProfiles( $this->propertyId );

        $site = $this->site( $this->siteId );
        $site->set( 'archived_date', 0 );
        $site->update();

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $this->asRole( 'admin', function () use ( $without ) {

            $sites = $this->controller()->sites();

            $this->assertArrayHasKey( $this->siteId, $sites,
                'A restored Profile is missing from the admin site list.' );

            $profiles = $this->profilesUnder( $this->controller()->hierarchy( $sites ), $this->propertyId );

            $this->assertContains( $this->siteId, $profiles,
                'A restored Profile is missing from the site control.' );

            $this->assertSame( $without + 1,
                \OWA\Module\Base\Controller\PropertyProfile::countLiveProfiles( $this->propertyId ),
                'A restored Profile is not counted as live.' );
        } );

        $this->assertTrue( \OWA\Core\CoreAPI::isSiteRegistered( $this->siteId ) );
    }

    /**
     * Deleting a Property takes its Profiles; deleting a Profile does NOT take
     * its Property.
     *
     * The asymmetry is the design. A Property is how someone says "this
     * website", so removing it removes the ways it was being watched. The other
     * direction would make an implicit destructive act ride along with an
     * explicit one -- and an empty Property is a legitimate state, the one that
     * lets someone start a website's Profiles over.
     *
     * Neither destroys a row: both archive.
     */
    public function testDeletingAPropertyArchivesItAndItsProfiles(): void
    {
        $this->skipUnlessParented();

        $this->archived[] = [ 'base.property', $this->propertyId ];
        $profiles         = $this->rememberLiveProfiles( $this->propertyId );

        $this->assertNotEmpty( $profiles );

        ( new \OWA\Module\Base\Controller\PropertyDelete( [ 'propertyId' => $this->propertyId ] ) )->action();

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $this->propertyId );

        $this->assertTrue( $property->wasPersisted(), 'Deleting a Property destroyed its row.' );
        $this->assertTrue( $property->isArchived(), 'Deleting a Property did not archive it.' );

        foreach ( $profiles as $id ) {

            $profile = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $profile->load( $id );

            $this->assertTrue( $profile->wasPersisted(), 'Deleting a Property destroyed a Profile row.' );
            $this->assertTrue( $profile->isArchived(), 'Deleting a Property does not reach its Profiles.' );
        }
    }

    public function testDeletingAProfileLeavesItsProperty(): void
    {
        $this->skipUnlessParented();

        // The Property too, so a failure here does not leave it archived.
        $this->archived[] = [ 'base.site', $this->site( $this->siteId )->get( 'id' ) ];
        $this->archived[] = [ 'base.property', $this->propertyId ];

        ( new \OWA\Module\Base\Controller\SitesDelete( [ 'siteId' => $this->siteId ] ) )->action();

        $site = $this->site( $this->siteId );

        $this->assertTrue( $site->wasPersisted(), 'Deleting a Profile destroyed its row.' );
        $this->assertTrue( $site->isArchived(), 'Deleting a Profile did not archive it.' );

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $this->propertyId );

        $this->assertFalse( $property->isArchived(),
            'Deleting a Profile archived its Property, so removing the last Profile of a '
            . 'website would silently remove the website.' );
    }

    /**
     * An empty Property stays reachable for whoever can see every Profile:
     * one whose last Profile was removed, and one created from the fan-out before
     * it had any. Filtered out for having none, no screen could get back to it.
     * For anyone else it is left out, and so is an archived Property.
     */
    public function testTheHierarchyKeepsAnEmptyPropertyForAnAdmin(): void
    {
        $empty    = $this->newProperty( null );
        $archived = $this->newProperty( 1756000000 );

        $ids = function () {

            return array_map( 'strval', array_column( $this->controller()->hierarchy( [] )['properties'], 'id' ) );
        };

        $this->asRole( 'admin', function () use ( $ids, $empty, $archived ) {

            $this->assertContains( $empty, $ids(),
                'A Property with no Profiles is dropped for an admin, so it cannot be '
                . 'repopulated after its last Profile is removed.' );

            $this->assertNotContains( $archived, $ids(), 'An archived Property is still in the site control.' );
        } );

        $this->asRole( 'viewer', function () use ( $ids, $empty ) {

            $this->assertNotContains( $empty, $ids(),
                'A Property none of whose Profiles the viewer can see is shown to them.' );
        } );
    }

    /**
     * Deleting a Property takes down its Profiles' tracking bundles, as deleting
     * one Profile does: a bundle left in place keeps every page that loads it
     * sending beacons.
     */
    public function testDeletingAPropertyRemovesItsProfilesBundles(): void
    {
        $this->skipUnlessParented();

        $out = sys_get_temp_dir() . '/owatest-bundles-' . getmypid() . '/';
        $was = \OWA\Module\Base\Classes\TrackerBundle::$outDir;

        @mkdir( $out, 0775, true );
        file_put_contents( $out . $this->siteId . '.js', '/* owatest */' );

        \OWA\Module\Base\Classes\TrackerBundle::$outDir = $out;

        // Put back in tearDown: the Property and each of its live Profiles.
        $this->archived[] = [ 'base.property', $this->propertyId ];

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn( 'id, archived_date' );
        $db->where( 'property_id', $this->propertyId );

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( empty( $row['archived_date'] ) ) {

                $this->archived[] = [ 'base.site', $row['id'] ];
            }
        }

        try {
            ( new \OWA\Module\Base\Controller\PropertyDelete( [ 'propertyId' => $this->propertyId ] ) )->action();

            $this->assertFileDoesNotExist( $out . $this->siteId . '.js',
                'The Profile was archived and its bundle is still served.' );

        } finally {

            \OWA\Module\Base\Classes\TrackerBundle::$outDir = $was;
            @unlink( $out . $this->siteId . '.js' );
            @rmdir( $out );
        }
    }
}
