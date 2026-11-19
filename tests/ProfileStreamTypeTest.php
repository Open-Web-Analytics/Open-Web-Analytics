<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An Observation Profile says what KIND of thing it observes, and that decides
 * which identifier it needs.
 *
 * Identity is minted, so a domain is not primary-key material: requiring one
 * -- present, with a scheme, unique -- would prevent a second Profile for a
 * website already tracked, and anything without a domain being observed at
 * all.
 *
 * Moving the domain up to the Property would be the wrong fix. The leaf is
 * typed: a Property carries no URL, and each Profile declares its kind and
 * supplies what that kind needs.
 */
final class ProfileStreamTypeTest extends TestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'OWA database not reachable.' );
        }
    }

    protected function tearDown(): void
    {
        foreach ( $this->created as $entry ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entry[0] );
            $entity->delete( $entry[1] );
        }

        $this->created = [];
    }

    private function sm()
    {
        return \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );
    }

    private function track( $site ): void
    {
        $this->created[] = [ 'base.site', $site->get( 'id' ) ];

        if ( $site->get( 'property_id' ) ) {

            $this->created[] = [ 'base.property', $site->get( 'property_id' ) ];
        }
    }

    /** Everything that existed before types is a website. */
    public function testAProfileWithNoStoredTypeIsAWebsite(): void
    {
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $this->assertSame( 'web', $site->getStreamType(),
            'A row written before stream_type existed reads as something other than a '
            . 'website, so every existing Profile changes meaning on upgrade.' );
    }

    /** A website Profile is known by its domain. */
    public function testAWebProfileKeepsItsDomain(): void
    {
        $domain = 'stream-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.example';

        $site = $this->sm()->createSite( $domain, 'Stream Web Probe' );

        $this->assertNotEmpty( $site, 'the probe Profile was not created' );
        $this->track( $site );

        $this->assertSame( 'web', $site->getStreamType() );
        $this->assertSame( $domain, $site->get( 'domain' ) );
        $this->assertEmpty( $site->get( 'app_id' ) );
    }

    /**
     * An app Profile is known by a bundle id and has NO domain.
     *
     * This is the case that could not be expressed at all: the add screen
     * required a domain, so an app could be a Property and never be observed.
     */
    public function testAnAppProfileIsIdentifiedByItsAppIdAndHasNoDomain(): void
    {
        $appId = 'com.example.probe' . substr( md5( uniqid( '', true ) ), 0, 6 );

        $site = $this->sm()->createSite(
            '', 'Stream App Probe', '', '', '', '', 'app', $appId );

        $this->assertNotEmpty( $site, 'an app Profile could not be created at all' );
        $this->track( $site );

        $this->assertSame( 'app', $site->getStreamType() );
        $this->assertSame( $appId, $site->get( 'app_id' ) );

        $this->assertEmpty( $site->get( 'domain' ),
            'An app Profile was given a domain, which is the field it exists to avoid.' );
    }

    /**
     * A second Profile for a website you already track.
     *
     * nextProfileName() numbers them "Observation Profile 1", "2", "3" -- a
     * counter that could never reach 2 while the add screen rejected a repeated
     * domain.
     */
    public function testASecondProfileCanBeAddedToTheSameProperty(): void
    {
        $domain = 'second-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.example';

        $first = $this->sm()->createSite( $domain, 'Second Profile Probe' );
        $this->assertNotEmpty( $first );
        $this->track( $first );

        $propertyId = $first->get( 'property_id' );
        $this->assertNotEmpty( $propertyId, 'the first Profile got no Property' );

        $second = $this->sm()->createSite( '', '', '', '', '', $propertyId );

        $this->assertNotEmpty( $second, 'a second Profile could not be added to a Property' );
        $this->created[] = [ 'base.site', $second->get( 'id' ) ];

        $this->assertSame( $propertyId, $second->get( 'property_id' ),
            'The second Profile did not join the Property it was given.' );

        $this->assertNotSame( $first->get( 'site_id' ), $second->get( 'site_id' ),
            'Both Profiles were given the same tracking identifier.' );

        $this->assertSame( 'Observation Profile 2', $second->get( 'name' ),
            'The numbering never reached 2, which is what a Property is for.' );
    }

    /**
     * Choosing a Property beats deriving one.
     *
     * Deriving from the domain was the only way in, which made the domain the
     * Property's key -- so a Property with no domain could never be chosen, and
     * an empty one could never be repopulated.
     */
    public function testAChosenPropertyWinsOverTheDomain(): void
    {
        $sm = $this->sm();

        $appId = 'com.example.chosen' . substr( md5( uniqid( '', true ) ), 0, 6 );

        /* A Property with no domain at all -- the one that could not be found. */
        $propertyId = $sm->ensurePropertyFor( '', 'Domainless Probe' );
        $this->assertNotEmpty( $propertyId );
        $this->created[] = [ 'base.property', $propertyId ];

        $site = $sm->createSite( '', '', '', '', '', $propertyId, 'app', $appId );

        $this->assertNotEmpty( $site,
            'A Profile could not be put into a Property that has no domain.' );

        $this->created[] = [ 'base.site', $site->get( 'id' ) ];

        $this->assertSame( $propertyId, $site->get( 'property_id' ) );
    }

    /** A Property that does not exist is refused rather than silently derived. */
    public function testAnUnknownPropertyIsRefused(): void
    {
        $site = $this->sm()->createSite(
            'unknown.example', 'x', '', '', '', 'no-such-property-id' );

        $this->assertEmpty( $site,
            'A Profile was created against a Property that does not exist, so it would '
            . 'be parented to nothing.' );
    }

    /** Only web Profiles can grant a CORS origin -- a bundle id is not a host. */
    public function testOnlyWebProfilesContributeCorsOrigins(): void
    {
        $origin = 'https://com.example.app';
        $match  = static fn ( array $site ) => \OWA\Core\View\RestApi::matchAllowedOrigin( $origin, [ $site + [ 'domain' => 'com.example.app' ] ] );

        $this->assertSame( $origin, $match( [ 'stream_type' => 'web' ] ) );
        $this->assertSame( $origin, $match( [] ), 'a Profile that predates stream_type is a website' );

        $this->assertNull( $match( [ 'stream_type' => 'app' ] ),
            'An app Profile\'s identifier is parsed as a host for CORS matching.' );

        $this->assertNull( $match( [ 'stream_type' => 'web', 'archived_date' => '2026-01-01 00:00:00' ] ),
            'An archived Profile still grants its origin, though it has stopped observing.' );
    }
}
