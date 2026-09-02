<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Removing a Profile or a Property archives it rather than destroying it.
 *
 * The old delete removed one row and left everything hanging off it -- access
 * grants, scoped settings, and every fact row ever collected under that
 * site_id -- behind as orphans a re-minted identifier could inherit. It was
 * also unrecoverable, which for the only record of a site's traffic is a
 * severe thing for one button to do.
 *
 * Archiving has to be airtight in one direction: an archived Profile must stop
 * being everywhere. Missing a single read path is how it reappears -- still
 * listed, still reportable, or worst of all still collecting, since the tag is
 * on the page and does not know anything happened.
 */
final class ArchiveNotDeleteTest extends TestCase
{
    private string $siteId = '';
    private string $propertyId = '';
    private array $archived = [];

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

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    private function skipUnlessParented(): void
    {
        if ( ! $this->siteId ) {
            $this->markTestSkipped( 'Needs a live Profile with a Property.' );
        }
    }

    /** Archive a Profile and remember to put it back. */
    private function archiveProfile( string $siteId ): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $siteId ) );

        $this->archived[] = [ 'base.site', $site->get( 'id' ) ];

        $site->set( 'archived_date', 1756000000 );
        $site->update();

        \OWA\Core\CoreAPI::forgetRegisteredSites();
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

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $this->siteId ) );
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

        \OWA\Core\CoreAPI::setScopedSetting(
            'profile', $this->siteId, 'base', $probe, 'still here' );

        try {
            $this->archiveProfile( $this->siteId );

            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->load( $site->generateId( $this->siteId ) );

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

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $this->siteId ) );

        $this->assertFalse( $site->isArchived(),
            'A live Profile reads as archived, which would hide every site on the install.' );

        $this->archiveProfile( $this->siteId );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $this->siteId ) );

        $this->assertTrue( $site->isArchived() );
    }

    /** A date, not a boolean, so a restore can say when this happened. */
    public function testTheColumnIsADateSoItCarriesWhen(): void
    {
        $this->skipUnlessParented();

        $this->archiveProfile( $this->siteId );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $this->siteId ) );

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
     * Which makes `IS NULL` the wrong test and empty() the right one. This is
     * the exact shape that has bitten this schema before: a boolean column
     * holding 1, 0 and NULL, where `= 0` silently excluded every row written
     * before the column existed.
     */
    public function testARestoredRowReadsAsLiveEvenThoughItIsNotNull(): void
    {
        $this->skipUnlessParented();

        $this->archiveProfile( $this->siteId );

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $this->siteId ) );
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
        $fresh->load( $fresh->generateId( $this->siteId ) );

        $this->assertFalse( $fresh->isArchived(),
            'A restored Profile reads as archived, so restoring does not work.' );
    }

    /** No read may use IS NULL, for the reason above. */
    public function testNoReadTestsTheColumnForNull(): void
    {
        $paths = [
            'Core/Controller.php',
            'Core/CoreAPI.php',
            'modules/Base/Classes/ServiceUser.php',
            'modules/Base/Controller/PropertyDelete.php',
            'modules/Base/Controller/PropertyProfile.php',
        ];

        foreach ( $paths as $path ) {

            $src = (string) file_get_contents( OWA_DIR . $path );

            $this->assertDoesNotMatchRegularExpression(
                '/archived_date[^\n]*IS\s+NULL/i', $src,
                "$path tests archived_date for NULL, which reads every RESTORED row as "
                . 'archived -- restoring writes 0, not NULL.' );
        }
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
     */
    public function testTheCascadeRunsDownwardOnly(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/PropertyDelete.php' );

        $this->assertStringContainsString( "where( 'property_id', \$propertyId )", $src,
            'Deleting a Property does not reach its Profiles.' );

        $profileDelete = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/SitesDelete.php' );

        $this->assertStringNotContainsString( 'base.property', $profileDelete,
            'Deleting a Profile touches its Property, so removing the last Profile of a '
            . 'website would silently remove the website.' );

        /* And neither of them destroys anything. */
        foreach ( [ $src, $profileDelete ] as $controller ) {

            $this->assertStringNotContainsString( '->delete(', $controller,
                'A delete controller still destroys rows.' );

            $this->assertStringContainsString( "set( 'archived_date'", $controller );
        }
    }

    /**
     * An empty Property stays reachable for whoever can see every Profile.
     *
     * It used to be filtered out for having none, which made it unreachable --
     * no screen could get back to it. That happened both to a Property whose
     * last Profile was removed and to one created from the fan-out before it
     * had any.
     */
    public function testTheHierarchyKeepsAnEmptyPropertyForAnAdmin(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'Core/Controller.php' );

        $this->assertStringContainsString( "return \$p['profiles'] || \$seesEveryProfile;", $src,
            'A Property with no Profiles is dropped unconditionally, so it cannot be '
            . 'repopulated after its last Profile is removed.' );

        $this->assertStringContainsString( "if ( ! empty( \$row['archived_date'] ) )", $src,
            'Archived Properties are still rendered in the site control.' );
    }

    /** Every listing has to agree, or an archived Profile reappears in one of them. */
    public function testEveryProfileListingExcludesArchived(): void
    {
        $paths = [
            'Core/Controller.php'                    => 'the admin site list',
            'modules/Base/Classes/ServiceUser.php'   => 'the granted site list',
        ];

        foreach ( $paths as $path => $what ) {

            $this->assertStringContainsString(
                'isArchived()', (string) file_get_contents( OWA_DIR . $path ),
                "An archived Profile is still visible through $what." );
        }
    }
}
