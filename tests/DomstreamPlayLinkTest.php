<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A recording is played by opening the page it was recorded on, so only a web
 * page gets a Play link.
 *
 * The page is the location the beacon claimed, and anyone can send a beacon.
 * A recording whose location is javascript: would have a Play link that runs
 * script in OWA's own window. The page is opened with the
 * player's data link in its fragment, so it must also be on the Profile's
 * domain or a subdomain of it, as the heatmap launcher requires.
 */
final class DomstreamPlayLinkTest extends TestCase
{
    const SITE = 'owa-play-link-site';

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'reads the Profile\'s domain' );
        }

        $this->dropSite();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            "INSERT INTO owa_site (id, site_id, property_id, name, domain) VALUES (%s, ?, 0, 'Play link fixture', 'www.example.test')",
            $entity->generateId( self::SITE ) ), [ self::SITE ] );
    }

    protected function tearDown(): void
    {
        if ( owa_test_db_available() ) {
            $this->dropSite();
        }
    }

    private function dropSite(): void
    {
        \OWA\Core\CoreAPI::dbSingleton()->query( 'DELETE FROM owa_site WHERE site_id = ?', [ self::SITE ] );
    }

    private function playUrl( string $location ): string
    {
        $controller = ( new ReflectionClass( \OWA\Module\Domstream\Controller\ReportDomstreams::class ) )
            ->newInstanceWithoutConstructor();

        $m = new ReflectionMethod( $controller, 'playerPayload' );

        return (string) $m->invoke( $controller, [
            'recording_id'  => '4921417228',
            'site_id'       => self::SITE,
            'page_location' => $location,
            'viewport_w'    => 1280,
            'viewport_h'    => 800,
        ] )['url'];
    }

    public function testAWebPageIsPlayedWhereItWasRecorded(): void
    {
        $this->assertSame( 'https://example.test/pricing', $this->playUrl( 'https://example.test/pricing#top' ) );
        $this->assertSame( 'http://example.test/', $this->playUrl( 'http://example.test/' ) );
        $this->assertSame( 'https://www.example.test/a', $this->playUrl( 'https://www.example.test/a' ) );
        $this->assertSame( 'https://shop.example.test/a', $this->playUrl( 'https://shop.example.test/a' ), 'a subdomain' );
    }

    /** @return array<string, array{string}> */
    public static function offTheDomain(): array
    {
        return [
            'another host'          => [ 'https://elsewhere.test/pricing' ],
            'a host ending like it' => [ 'https://badexample.test/pricing' ],
            'the domain as a path'  => [ 'https://elsewhere.test/example.test' ],
            'the domain as a user'  => [ 'https://example.test@elsewhere.test/' ],
            'a backslash before it' => [ 'https://elsewhere.test\\@example.test/' ],
            'a tab in the host'     => [ "https://elsewhere.te\tst@example.test/" ],
        ];
    }

    /**
     * @dataProvider offTheDomain
     */
    public function testAPageOffTheProfilesDomainHasNoPlayLink( string $location ): void
    {
        $this->assertSame( '', $this->playUrl( $location ) );
    }

    /** @return array<string, array{string}> */
    public static function notPages(): array
    {
        return [
            'javascript'        => [ 'javascript:alert(1)' ],
            'mixed case'        => [ 'JaVaScRiPt:alert(1)' ],
            'data'              => [ 'data:text/html,<script>1</script>' ],
            'no scheme'         => [ 'example.test/pricing' ],
            'protocol relative' => [ '//example.test/pricing' ],
            'empty'             => [ '' ],
        ];
    }

    /**
     * @dataProvider notPages
     */
    public function testAnythingElseHasNoPlayLink( string $location ): void
    {
        $this->assertSame( '', $this->playUrl( $location ) );
    }
}
