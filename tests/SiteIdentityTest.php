<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/SiteIdentityHarness.php';

use OWA\Tests\SiteIdentityHarness as Harness;

/**
 * Pins how a site's identity is derived, before the domain stops determining it.
 *
 * Two halves that behave differently under the coming change, and keeping them
 * apart is the point of the recording:
 *
 *   domain -> site_id   EXPECTED TO CHANGE for newly created sites.
 *   site_id -> id       MUST NOT CHANGE, ever, or existing installs lose their
 *                       sites -- every fact row references a site_id that has to
 *                       keep resolving to the same numeric key.
 */
final class SiteIdentityTest extends TestCase
{
    /** @return array<string,mixed> */
    private function recorded(): array
    {
        $this->assertFileExists( Harness::fixturePath() );

        return (array) json_decode(
            (string) file_get_contents( Harness::fixturePath() ), true );
    }

    public function testIdentityDerivationMatchesItsRecording(): void
    {
        $this->assertSame( $this->recorded(), Harness::snapshot() );
    }

    /*
     * ---- the half that must survive ---------------------------------------
     */

    public function testTheNumericIdIsAPureFunctionOfTheSiteIdString(): void
    {
        $recorded = $this->recorded()['siteIdToId'];

        foreach ( $recorded as $siteId => $expected ) {

            $this->assertSame(
                (string) $expected,
                Harness::idForSiteId( (string) $siteId ),
                "The numeric id for site_id '$siteId' moved. Every fact row references a "
                . 'site_id and resolves it this way, so existing installs would lose their sites.' );
        }
    }

    public function testTheNumericIdIgnoresCase(): void
    {
        /*
         * setStringGuid() lowercases before hashing, and MySQL's default
         * collation compares these equal too. Pinned rather than assumed: if the
         * lowercasing were dropped, every existing site's numeric id would shift
         * silently -- there is no error to raise when a hash simply changes.
         */
        $mixed = Harness::idForSiteId( 'OWA-CaseTest' );

        $this->assertSame( $mixed, Harness::idForSiteId( 'owa-casetest' ) );
        $this->assertSame( $mixed, Harness::idForSiteId( 'OwA-cAsEtEsT' ) );

        $this->assertNotSame(
            $mixed, Harness::idForSiteId( 'OWA-CaseTes' ),
            'Ignoring case must not mean ignoring content.' );
    }

    public function testAnEmptySiteIdYieldsAnEmptyIdRatherThanAHash(): void
    {
        /*
         * Degenerate and silent: a site created without a site_id gets an empty
         * primary key rather than a hash of the empty string, and nothing
         * complains. Recorded so the coming change -- which starts issuing
         * identifiers rather than deriving them -- cannot quietly begin
         * producing these.
         */
        $this->assertSame( '', Harness::idForSiteId( '' ) );
    }

    /*
     * ---- the half that is expected to change -------------------------------
     */

    public function testTheSiteIdIsCurrentlyDerivedFromTheDomain(): void
    {
        /*
         * EXPECTED TO CHANGE. This is the coupling being removed: identity is a
         * pure function of the domain, which is what makes two sites for one
         * domain impossible by construction.
         *
         * Asserted through the production code path rather than by recomputing
         * md5() here, so it states what the application does.
         */
        $first  = Harness::derive( 'example.com' );
        $second = Harness::derive( 'example.com' );

        $this->assertSame(
            $first, $second,
            'Derivation is currently pure -- the same domain gives the same identity.' );

        $this->assertNotSame(
            $first['site_id'],
            Harness::derive( 'other.example.com' )['site_id'],
            'Different domains must give different identities.' );
    }

    public function testTheRecordingWouldNoticeTheDerivationChanging(): void
    {
        /*
         * The recording is only worth its confidence if it can be shown to
         * fail. Compares against the fixture's stored value rather than a
         * recomputation, which is what the real check does.
         */
        $recorded = $this->recorded()['domainToSiteId']['example.com']['site_id'];

        $this->assertSame( $recorded, Harness::derive( 'example.com' )['site_id'] );

        $this->assertNotSame(
            $recorded, 'OWA-somethingElse',
            'A non-derived identifier must not match the recorded derived one -- if it did, '
            . 'this recording could not tell the change had happened.' );
    }

    /*
     * ---- idempotency, which the derivation is quietly providing -------------
     */

    public function testCreatingTheSameDomainTwiceDoesNotDuplicate(): void
    {
        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'OWA database not reachable.' );
        }

        /*
         * The behaviour most at risk. createNewSite() is idempotent only
         * BECAUSE the id is a function of the domain: it derives, loads, and
         * skips creation when the row is already there. Once identifiers stop
         * being derived, idempotency has to come from an explicit lookup
         * instead -- and this is what proves the replacement actually kept it.
         *
         * tests/e2e/seed_reporting_fixtures.php depends on this and says so.
         */
        $manager = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

        $domain = 'idempotency-' . bin2hex( random_bytes( 6 ) ) . '.example.com';

        $first = $manager->createNewSite( $domain, 'First' );

        $this->assertNotEmpty( $first, 'The first create must return a site.' );

        /*
         * The second call must REFUSE, not merely leave the first alone.
         *
         * An earlier version of this test asserted the first site had not been
         * overwritten, which passes even when idempotency is broken: a second
         * site is created alongside the first, and the first is indeed
         * untouched. The mutation that replaces the derived id with a random one
         * -- exactly the change this groundwork makes -- sailed through it. The
         * property that matters is that no SECOND site appears.
         */
        $second = $manager->createNewSite( $domain, 'Second' );

        $this->assertEmpty(
            $second,
            'A second create for the same domain must refuse rather than create another site.' );

        $this->assertSame(
            1,
            $this->countSitesForDomain( $domain ),
            'Exactly one site may carry a given domain today. When identifiers stop being '
            . 'derived, this has to be preserved by an explicit lookup instead.' );
    }

    /** How many rows in owa_site carry this domain. */
    private function countSitesForDomain( string $domain ): int
    {
        $summary = \OWA\Core\CoreAPI::summarize( array(
            'entity'      => 'base.site',
            'columns'     => array( 'id' => 'count' ),
            'constraints' => array( 'domain' => $domain ),
        ) );

        return (int) ( $summary['id_count'] ?? 0 );
    }
}
